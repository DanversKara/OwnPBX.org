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
