<?php

declare(strict_types=1);

/**
 * Shutting the PBX down by text message, for the day you cannot sign in to do it.
 *
 * The sending number proves nothing - anyone can put your number in the "from" field - so the
 * message itself has to carry the proof:
 *
 *     <passphrase> <6-digit code> <command> [who]
 *
 * The passphrase is something only you know and is stored hashed. The code comes from an
 * authenticator app, so a message someone copies today is useless tomorrow, and a code is refused
 * once it has been used even inside its own minute.
 *
 * Every command here tightens access. There is deliberately no command that unlocks, lifts a
 * lockdown or changes these settings: if a text could open the doors, then a text is all an
 * attacker needs. Getting back in is done from the local network or from the console with
 * bin/unlock.php, which is the one place that cannot be reached from outside.
 */
final class SmsGuardService
{
    public const SETTING_ENABLED = 'sms_guard_enabled';
    public const SETTING_NUMBERS = 'sms_guard_numbers';
    public const SETTING_PASSPHRASE = 'sms_guard_passphrase_hash';
    public const SETTING_SECRET = 'sms_guard_totp_secret';
    public const SETTING_USED_CODE = 'sms_guard_used_code';
    public const SETTING_FAILS = 'sms_guard_fails';

    /** Wrong attempts allowed in a row before the channel stops answering for a while. */
    public const MAX_FAILS = 5;
    public const FAIL_WINDOW = 900;

    public static function enabled(): bool
    {
        return getSystemSetting(self::SETTING_ENABLED, '0') === '1'
            && (string) getSystemSetting(self::SETTING_PASSPHRASE, '') !== ''
            && (string) getSystemSetting(self::SETTING_SECRET, '') !== '';
    }

    /** @return list<string> the numbers allowed to command the PBX, digits only */
    public static function numbers(): array
    {
        $out = [];
        foreach (explode(',', (string) getSystemSetting(self::SETTING_NUMBERS, '')) as $n) {
            $d = preg_replace('/\D+/', '', trim($n)) ?? '';
            if ($d !== '') {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** Numbers arrive in many shapes (+1 555…, 1555…, 555…), so the last ten digits decide. */
    public static function numberAllowed(string $from): bool
    {
        $d = preg_replace('/\D+/', '', $from) ?? '';
        if ($d === '') {
            return false;
        }
        $tail = substr($d, -10);
        foreach (self::numbers() as $n) {
            if (strlen($tail) >= 7 && substr($n, -10) === $tail) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads one incoming text and carries out what it asks, if it proves itself.
     *
     * @return string|null what to text back, or null to say nothing at all. Nothing is said to a
     *                     number that is not on the list, so a stranger texting the PBX cannot
     *                     learn that this channel exists, let alone probe it.
     */
    public static function handle(string $from, string $text): ?string
    {
        if (!self::enabled() || !self::numberAllowed($from)) {
            return null;
        }
        $waitFor = self::lockedOutFor();
        if ($waitFor > 0) {
            // The number is on the list, so it has already been told this channel exists: saying
            // why it has gone quiet gives a stranger nothing and saves the owner from believing
            // the whole thing is broken when it is working exactly as intended.
            return 'Too many wrong attempts. Try again in ' . (int) ceil($waitFor / 60) . ' minute(s).';
        }

        $parts = preg_split('/\s+/', trim($text)) ?: [];
        if (count($parts) < 3) {
            self::noteFailure();
            return 'Not understood.';
        }
        [$pass, $code] = [$parts[0], $parts[1]];
        $command = strtoupper($parts[2]);
        $who = $parts[3] ?? '';

        if (!self::proves($pass, $code)) {
            self::noteFailure();
            // The same words whatever was wrong: a different answer for a wrong passphrase than
            // for a wrong code would tell someone which half they had already guessed.
            return 'Not accepted.';
        }
        self::clearFailures();
        self::burnCode($code);

        // Every answer ends with where things stand. A text that silently worked and a text that
        // never arrived look identical from a phone, and this is the one channel whose whole
        // purpose is being used when nothing else can be checked.
        return rtrim(self::run($command, $who, $from)) . ' — ' . self::state();
    }

    /** Where the PBX stands, in the few words a text has room for. */
    private static function state(): string
    {
        $state = SecurityLockdownService::state();
        $locked = count(SecurityLockdownService::lockedUsers());
        return 'lockdown ' . ($state['active'] ? 'ON since ' . $state['at'] : 'off')
             . ', ' . $locked . ' locked.';
    }

    /** Carries out one command. Every one of these tightens access; none of them loosens it. */
    private static function run(string $command, string $who, string $from): string
    {
        $by = 'SMS ' . self::mask($from);
        switch ($command) {
            case 'LOCKDOWN':
                SecurityLockdownService::enable($by, '');
                return 'Lockdown ON. All sessions ended, API tokens suspended. '
                     . 'Sign-in is now local-network only. Lift it from the LAN or the console.';

            case 'KICK':
                if ($who === '') {
                    $n = SecurityLockdownService::kickAll();
                    return "Sessions ended for {$n} user(s), API tokens suspended.";
                }
                $user = SecurityLockdownService::findUser($who);
                if ($user === null) {
                    return "No user {$who}.";
                }
                SecurityLockdownService::kickUser((int) $user['id']);
                return "Sessions ended for {$who}.";

            case 'LOCK':
                if ($who === '') {
                    return 'LOCK needs an extension or username.';
                }
                $user = SecurityLockdownService::findUser($who);
                if ($user === null) {
                    return "No user {$who}.";
                }
                SecurityLockdownService::lockUser((int) $user['id'], $by);
                return "{$who} is locked out. Unlock from the LAN or the console.";

            case 'STATUS':
                return 'Status:';

            default:
                return 'Commands: LOCKDOWN, KICK [who], LOCK <who>, STATUS.';
        }
    }

    // ------------------------------------------------------------------ proof

    private static function proves(string $pass, string $code): bool
    {
        $hash = (string) getSystemSetting(self::SETTING_PASSPHRASE, '');
        $secret = (string) getSystemSetting(self::SETTING_SECRET, '');
        if ($hash === '' || $secret === '') {
            return false;
        }
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if ($code === '' || $code === (string) getSystemSetting(self::SETTING_USED_CODE, '')) {
            // Already spent. A text can be delivered twice, and can also be read by someone else
            // on its way, so a code is good for exactly one command.
            return false;
        }
        // Both halves are always checked, so the time taken says nothing about which one failed.
        $passOk = password_verify($pass, $hash);
        $codeOk = TwoFactorService::verifyCode($secret, $code, 1);
        return $passOk && $codeOk;
    }

    private static function burnCode(string $code): void
    {
        setSystemSetting(self::SETTING_USED_CODE, preg_replace('/\D+/', '', $code) ?? '');
    }

    // ------------------------------------------------------------ rate limits

    /** Seconds still to wait, or 0 when the channel is answering. */
    private static function lockedOutFor(): int
    {
        [$count, $since] = self::failures();
        if ($count < self::MAX_FAILS) {
            return 0;
        }
        $left = self::FAIL_WINDOW - (time() - $since);
        return $left > 0 ? $left : 0;
    }

    /** @return array{0: int, 1: int} */
    private static function failures(): array
    {
        $raw = (string) getSystemSetting(self::SETTING_FAILS, '');
        if (!str_contains($raw, ':')) {
            return [0, 0];
        }
        [$c, $t] = explode(':', $raw, 2);
        return [(int) $c, (int) $t];
    }

    private static function noteFailure(): void
    {
        [$count, $since] = self::failures();
        if ($since === 0 || (time() - $since) >= self::FAIL_WINDOW) {
            $count = 0;
            $since = time();
        }
        setSystemSetting(self::SETTING_FAILS, ($count + 1) . ':' . $since);
    }

    private static function clearFailures(): void
    {
        setSystemSetting(self::SETTING_FAILS, '');
    }

    /**
     * Holds a reply until something else can send it.
     *
     * The command arrives on the provider's webhook, and sending the answer from inside that
     * request keeps the provider waiting on us: our SMS API call can take longer than its own
     * patience, so it times out, reports an error for a message that was in fact delivered, and
     * may redeliver the incoming text. The command has already been carried out by this point,
     * so the answer can wait the second or two until the worker picks it up.
     */
    public static function queueReply(string $to, string $text): void
    {
        $row = PluginManager::kvGet(self::SLUG_SMS, 'state', 'guard_replies');
        $queue = is_array($row['value'] ?? null) ? $row['value'] : [];
        $queue[] = ['to' => $to, 'text' => $text, 'at' => time()];
        // A reply nobody collected for an hour is of no use to anyone by then.
        $cutoff = time() - 3600;
        $queue = array_values(array_filter(
            array_slice($queue, -20),
            static fn(array $r): bool => (int) ($r['at'] ?? 0) >= $cutoff
        ));
        PluginManager::kvSet(self::SLUG_SMS, 'state', 'guard_replies', $queue);
    }

    /**
     * Takes everything waiting, and empties the queue in the same breath.
     *
     * Emptying first means a reply can be lost if sending then fails, which is the right way
     * round: the alternative is a reply that cannot be sent being tried again every two seconds
     * for ever.
     *
     * @return list<array{to: string, text: string, at: int}>
     */
    public static function takeQueuedReplies(): array
    {
        $row = PluginManager::kvGet(self::SLUG_SMS, 'state', 'guard_replies');
        $queue = is_array($row['value'] ?? null) ? $row['value'] : [];
        if ($queue !== []) {
            PluginManager::kvSet(self::SLUG_SMS, 'state', 'guard_replies', []);
        }
        return $queue;
    }

    /** The queue belongs to the SMS plugin, because its worker is what empties it. */
    public const SLUG_SMS = 'sms';

    /** "…5188" - enough to tell which phone it was in the audit log, not the whole number. */
    public static function mask(string $number): string
    {
        $d = preg_replace('/\D+/', '', $number) ?? '';
        return $d === '' ? 'unknown' : '…' . substr($d, -4);
    }
}
