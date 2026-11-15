<?php

/**
 * Keeps trunk registrations alive.
 *
 * A trunk that is not registered means no calls in or out, and Asterisk does not always
 * recover on its own: before OwnPBX 1.75.29 an authentication rejection stopped the
 * registration for good (auth_rejection_permanent, Asterisk's default), and a registration
 * can also sit "Stopped" after a reload at the wrong moment. The every-minute cron
 * (bin/task_dispatcher.php) calls check(): when a trunk has been unregistered for a couple
 * of minutes it reloads the registration module, which starts the attempts again, and
 * writes it to the audit log. Nudges are spaced out so a carrier never sees a flood.
 */
class TrunkRegistrationWatchdog
{
    /** Unregistered for this long before the first nudge (seconds). */
    public const GRACE = 120;

    /** Shortest time between two nudges (seconds). */
    public const COOLDOWN = 600;

    private const STATE_KEY = 'trunk_reg_watchdog_state';

    /**
     * @return array{down: list<string>, recovered: bool} the trunks that are not registered,
     *                                                    and whether a nudge was sent
     */
    public static function check(?int $now = null): array
    {
        $now = $now ?? time();
        $down = self::unregisteredTrunks();
        $state = self::state();

        if ($down === []) {
            if ($state !== []) {
                self::saveState([]);
            }
            return ['down' => [], 'recovered' => false];
        }

        $since = (int) ($state['since'] ?? 0);
        if ($since <= 0 || $since > $now) {
            $since = $now;
        }
        $lastNudge = (int) ($state['nudged'] ?? 0);
        $recovered = false;

        if ($now - $since >= self::GRACE && $now - $lastNudge >= self::COOLDOWN) {
            self::nudge();
            $lastNudge = $now;
            $recovered = true;
            if (function_exists('writeAuditLog')) {
                writeAuditLog(null, 'trunks', 'watchdog', 'Trunk not registered, registration restarted: ' . implode(', ', $down), 'update', null);
            }
        }

        self::saveState(['since' => $since, 'nudged' => $lastNudge, 'trunks' => $down]);
        return ['down' => $down, 'recovered' => $recovered];
    }

    /**
     * Active trunks whose registration Asterisk reports as not working.
     * @return list<string>
     */
    public static function unregisteredTrunks(): array
    {
        try {
            $names = getDB()->query('SELECT trunk_name FROM pbx_trunks WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$names) {
            return [];
        }
        $regs = self::sh('asterisk -rx "pjsip show registrations"');
        // No answer from Asterisk (stopped, or the CLI is not reachable): not our problem to fix.
        if (trim($regs) === '') {
            return [];
        }
        $down = [];
        foreach ($names as $name) {
            $q = preg_quote((string) $name, '/');
            if (preg_match('/^\s*' . $q . '(?:-reg)?\/\S+\s+\S+\s+(Rejected|Unregistered|Stopped)/mi', $regs)) {
                $down[] = (string) $name;
            }
        }
        return $down;
    }

    /**
     * Every inbound call needs the trunk's own context in the dialplan. One OwnPBX install
     * ended up with an extensions_inbound.conf written at a moment when no active trunk was
     * visible: the contexts were gone, and from then on the carrier's every call was answered
     * "404 Not Found" - no inbound calls at all, with nothing in the portal to show it. The
     * file is rebuilt when a context is missing, at most once every ten minutes.
     *
     * @return list<string> the trunks whose routing was rebuilt
     */
    public static function checkInboundContexts(?int $now = null): array
    {
        $now = $now ?? time();
        $missing = [];
        try {
            $names = getDB()->query('SELECT trunk_name FROM pbx_trunks WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($names ?: [] as $name) {
            $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $name);
            if ($clean === '') {
                continue;
            }
            $out = self::sh('asterisk -rx ' . escapeshellarg('dialplan show from-trunk-' . $clean . '-route'));
            // Empty output means Asterisk did not answer at all: that is not a missing context.
            if (trim($out) !== '' && stripos($out, 'no existence') !== false) {
                $missing[] = $clean;
            }
        }
        if ($missing === []) {
            return [];
        }
        $state = self::state();
        if ($now - (int) ($state['inbound_fixed'] ?? 0) < self::COOLDOWN) {
            return [];
        }
        $state['inbound_fixed'] = $now;
        self::saveState($state);

        // The cron runs as root, the portal writes these files as the web user: keep whichever
        // owner the file already had, or the next "Apply" in the portal could not write it.
        $path = (defined('ASTERISK_PBX_DIR') ? ASTERISK_PBX_DIR : '') . '/extensions_inbound.conf';
        $before = @stat($path);
        try {
            require_once dirname(__DIR__) . '/asterisk_sync.php';
            syncInboundDialplan();
        } catch (\Throwable $e) {
            error_log('trunk watchdog: rebuilding the inbound dialplan failed: ' . $e->getMessage());
            return [];
        }
        if (is_array($before) && is_file($path)) {
            @chown($path, (int) $before['uid']);
            @chgrp($path, (int) $before['gid']);
        }
        if (function_exists('writeAuditLog')) {
            writeAuditLog(null, 'trunks', 'watchdog', 'Inbound routing was missing, rebuilt: ' . implode(', ', $missing), 'update', null);
        }
        return $missing;
    }

    /** Reloading the module starts every outbound registration again, including stopped ones. */
    private static function nudge(): void
    {
        self::sh('asterisk -rx "module reload res_pjsip_outbound_registration.so"');
    }

    /** @return array<string, mixed> */
    private static function state(): array
    {
        $raw = (string) getSystemSetting(self::STATE_KEY, '');
        if ($raw === '') {
            return [];
        }
        $v = json_decode($raw, true);
        return is_array($v) ? $v : [];
    }

    /** @param array<string, mixed> $state */
    private static function saveState(array $state): void
    {
        setSystemSetting(self::STATE_KEY, $state === [] ? '' : (string) json_encode($state));
    }

    private static function sh(string $cmd): string
    {
        return (string) shell_exec('timeout 15 ' . $cmd . ' 2>/dev/null');
    }
}
