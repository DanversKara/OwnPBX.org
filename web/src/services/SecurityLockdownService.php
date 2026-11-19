<?php

declare(strict_types=1);

/**
 * Shutting the doors when somebody else may already be inside.
 *
 * Three separate things, deliberately kept apart so one can be used without the others:
 *
 *   kick    - end sessions. Everything signed in stops working: the portal, the phone app and
 *             the REST tokens. Nobody is prevented from signing back in.
 *   lock    - seal one account. It cannot sign in at all until it is unlocked.
 *   lockdown- seal every account except from the local network. The whole PBX stops accepting
 *             sign-ins from outside while it is on.
 *
 * Everything here tightens or loosens access, so every one of them is written to the audit log
 * with who asked and from where. Loosening is the dangerous direction: see SmsGuardService, which
 * can only ever call the tightening half.
 */
final class SecurityLockdownService
{
    public const SETTING_ACTIVE = 'lockdown_active';
    public const SETTING_AT = 'lockdown_at';
    public const SETTING_BY = 'lockdown_by';
    public const SETTING_LOCAL_CIDRS = 'lockdown_local_cidrs';

    /** The networks that may still sign in during a lockdown: private ranges and this machine. */
    public const DEFAULT_LOCAL_CIDRS = '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7';

    // ------------------------------------------------------------------ state

    public static function isActive(): bool
    {
        return getSystemSetting(self::SETTING_ACTIVE, '0') === '1';
    }

    /** @return array{active: bool, at: string, by: string, cidrs: string} */
    public static function state(): array
    {
        return [
            'active' => self::isActive(),
            'at' => (string) getSystemSetting(self::SETTING_AT, ''),
            'by' => (string) getSystemSetting(self::SETTING_BY, ''),
            'cidrs' => self::localCidrs(),
        ];
    }

    public static function localCidrs(): string
    {
        $v = trim((string) getSystemSetting(self::SETTING_LOCAL_CIDRS, ''));
        return $v !== '' ? $v : self::DEFAULT_LOCAL_CIDRS;
    }

    // ------------------------------------------------------------- the switch

    /**
     * Seals every sign-in from outside the local network.
     *
     * The sessions already open are ended at the same time, because a lockdown that only stops
     * new sign-ins leaves whoever is already inside exactly where they were.
     */
    public static function enable(string $by, string $ip = ''): void
    {
        setSystemSetting(self::SETTING_ACTIVE, '1');
        setSystemSetting(self::SETTING_AT, date('Y-m-d H:i:s'));
        setSystemSetting(self::SETTING_BY, mb_substr($by, 0, 120));
        self::kickAll();
        self::audit('lockdown_on', "Lockdown switched on by {$by}" . ($ip !== '' ? " from {$ip}" : ''));
    }

    /** Lifting a lockdown is the loosening direction, so only local callers reach this. */
    public static function disable(string $by, string $ip = ''): void
    {
        setSystemSetting(self::SETTING_ACTIVE, '0');
        setSystemSetting(self::SETTING_AT, '');
        setSystemSetting(self::SETTING_BY, '');
        self::restoreApiTokens();
        self::audit('lockdown_off', "Lockdown lifted by {$by}" . ($ip !== '' ? " from {$ip}" : ''));
    }

    // -------------------------------------------------------------- the kicks

    /**
     * Ends every session of one person: the portal, the phone app and anything holding a cookie.
     *
     * token_epoch is what the session check compares against on every request, so raising it
     * drops them wherever they are signed in, without touching any session file.
     */
    public static function kickUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $st = getDB()->prepare('UPDATE sys_users SET token_epoch = token_epoch + 1 WHERE id = ?');
        $st->execute([$userId]);
        $hit = $st->rowCount() > 0;
        if ($hit) {
            self::audit('kick_user', "Sessions ended for user #{$userId}");
        }
        return $hit;
    }

    /** The same for everybody, and the REST tokens with them. */
    public static function kickAll(): int
    {
        $db = getDB();
        $n = $db->exec('UPDATE sys_users SET token_epoch = token_epoch + 1');
        self::suspendApiTokens();
        self::audit('kick_all', 'Sessions ended for every user, and API tokens suspended');
        return (int) $n;
    }

    /**
     * Switches off every live API token, remembering which ones were switched off here.
     *
     * Without that record, lifting a lockdown would wake every token the PBX has ever held,
     * including ones an administrator had deliberately turned off.
     */
    public static function suspendApiTokens(): int
    {
        if (!self::hasTokenTable()) {
            return 0;
        }
        try {
            return (int) getDB()->exec(
                'UPDATE sys_api_tokens SET is_active = 0, suspended_at = NOW()
                 WHERE is_active = 1 AND suspended_at IS NULL'
            );
        } catch (\Throwable $e) {
            error_log('lockdown suspendApiTokens: ' . $e->getMessage());
            return 0;
        }
    }

    public static function restoreApiTokens(): int
    {
        if (!self::hasTokenTable()) {
            return 0;
        }
        try {
            return (int) getDB()->exec(
                'UPDATE sys_api_tokens SET is_active = 1, suspended_at = NULL WHERE suspended_at IS NOT NULL'
            );
        } catch (\Throwable $e) {
            error_log('lockdown restoreApiTokens: ' . $e->getMessage());
            return 0;
        }
    }

    // --------------------------------------------------------------- the lock

    public static function lockUser(int $userId, string $reason): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $st = getDB()->prepare(
            'UPDATE sys_users SET locked_at = NOW(), locked_reason = ?, token_epoch = token_epoch + 1 WHERE id = ?'
        );
        $st->execute([mb_substr($reason, 0, 190), $userId]);
        $hit = $st->rowCount() > 0;
        if ($hit) {
            self::audit('lock_user', "Account #{$userId} locked: {$reason}");
        }
        return $hit;
    }

    public static function unlockUser(int $userId, string $by): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $st = getDB()->prepare('UPDATE sys_users SET locked_at = NULL, locked_reason = NULL WHERE id = ?');
        $st->execute([$userId]);
        $hit = $st->rowCount() > 0;
        if ($hit) {
            self::audit('unlock_user', "Account #{$userId} unlocked by {$by}");
        }
        return $hit;
    }

    /** @return list<array<string, mixed>> the accounts currently sealed */
    public static function lockedUsers(): array
    {
        try {
            return getDB()->query(
                'SELECT id, username, full_name, extension, locked_at, locked_reason
                 FROM sys_users WHERE locked_at IS NOT NULL ORDER BY locked_at DESC'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Finds a user by extension or username, for a command that names one. */
    public static function findUser(string $who): ?array
    {
        $who = trim($who);
        if ($who === '') {
            return null;
        }
        try {
            $q = getDB()->prepare(
                'SELECT id, username, full_name, extension FROM sys_users
                 WHERE extension = ? OR username = ? LIMIT 1'
            );
            $q->execute([$who, $who]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------- the gatekeeper

    /**
     * May this person sign in from this address?
     *
     * Called once the password has been checked, so a wrong password still fails as a wrong
     * password: a locked account must not become a way of telling valid passwords apart.
     *
     * @param array<string, mixed> $user
     * @return array{allowed: bool, reason: string}
     */
    public static function loginAllowed(array $user, string $ip): array
    {
        if (!empty($user['locked_at'])) {
            return ['allowed' => false, 'reason' => 'locked'];
        }
        if (self::isActive() && !self::isLocalIp($ip)) {
            return ['allowed' => false, 'reason' => 'lockdown'];
        }
        return ['allowed' => true, 'reason' => ''];
    }

    /** Is this address on one of the networks allowed to sign in during a lockdown? */
    public static function isLocalIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach (explode(',', self::localCidrs()) as $cidr) {
            if (self::ipInCidr($ip, trim($cidr))) {
                return true;
            }
        }
        return false;
    }

    /** Plain prefix matching on the packed address, so IPv4 and IPv6 take the same path. */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$net, $bitsRaw] = explode('/', $cidr, 2);
        $bits = (int) $bitsRaw;
        $a = @inet_pton($ip);
        $b = @inet_pton(trim($net));
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $max = strlen($a) * 8;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;
        if ($whole > 0 && !hash_equals(substr($b, 0, $whole), substr($a, 0, $whole))) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (($a[$whole] & $mask) === ($b[$whole] & $mask));
    }

    // -------------------------------------------------------------- internals

    private static function hasTokenTable(): bool
    {
        static $known = null;
        if ($known === null) {
            try {
                $q = getDB()->prepare(
                    'SELECT 1 FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
                );
                $q->execute(['sys_api_tokens']);
                $known = $q->fetchColumn() !== false;
            } catch (\Throwable $e) {
                $known = false;
            }
        }
        return $known;
    }

    private static function audit(string $action, string $detail): void
    {
        if (function_exists('writeAuditLog')) {
            writeAuditLog('security', 'lockdown', $action, $detail, 'update', null);
        }
    }
}
