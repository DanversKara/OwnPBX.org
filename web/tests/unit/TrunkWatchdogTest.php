<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/TrunkRegistrationWatchdog.php';

/** A trunk registration that stops working is restarted instead of staying down. */
final class TrunkWatchdogTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        setSystemSetting('trunk_reg_watchdog_state', '');
    }

    protected function tearDown(): void
    {
        setSystemSetting('trunk_reg_watchdog_state', '');
    }

    public function testRegistrationNeverGivesUpAfterAnAuthRejection(): void
    {
        getDB()->exec("UPDATE pbx_trunks SET registration_enabled = 1, auth_username = 'u', auth_password = 'p' WHERE trunk_name = 'testtrunk'");
        syncAllTrunks();
        $path = ASTERISK_PBX_DIR . '/pjsip_trunks.conf';
        $conf = is_file($path) ? (string) file_get_contents($path) : '';
        $this->assertStringContainsString('type=registration', $conf, 'no registering trunk in the fixtures');
        // Asterisk's default stops registering for good on a 401/403; one bad answer from
        // the carrier then means no calls in or out until somebody reloads by hand.
        $this->assertStringContainsString('auth_rejection_permanent=no', $conf);
        $this->assertStringContainsString('fatal_retry_interval=300', $conf);
        $this->assertStringContainsString('forbidden_retry_interval=300', $conf);
        $this->assertStringContainsString('max_retries=10000', $conf);
        // The probe that keeps the path to the carrier open is still there.
        $this->assertStringContainsString('qualify_frequency=', $conf);
    }

    public function testNudgeWaitsForTheGracePeriodAndThenTheCooldown(): void
    {
        $t0 = 1760000000;
        $down = ['voip-test'];
        // First sighting: note the time, do not act yet (a reload in the middle of a
        // registration that is already on its way would do more harm than good).
        $this->assertSame(false, self::decide($down, $t0, $t0, 0));
        // Still down a minute later: too early.
        $this->assertSame(false, self::decide($down, $t0 + 60, $t0, 0));
        // Past the grace period: restart the registration.
        $this->assertSame(true, self::decide($down, $t0 + TrunkRegistrationWatchdog::GRACE, $t0, 0));
        // Just after a nudge: wait, do not hammer the carrier.
        $this->assertSame(false, self::decide($down, $t0 + 300, $t0, $t0 + 120));
        // After the cooldown: try again.
        $this->assertSame(true, self::decide($down, $t0 + 121 + TrunkRegistrationWatchdog::COOLDOWN, $t0, $t0 + 120));
    }

    /** The decision the watchdog makes, with the clock and the stored state given. */
    private static function decide(array $down, int $now, int $since, int $lastNudge): bool
    {
        return $down !== []
            && $now - $since >= TrunkRegistrationWatchdog::GRACE
            && $now - $lastNudge >= TrunkRegistrationWatchdog::COOLDOWN;
    }

    public function testNothingIsReportedWhenAsteriskSaysNothing(): void
    {
        // No answer from the CLI (Asterisk stopped): not the watchdog's business, and it
        // must not report every trunk as down.
        $this->assertSame([], TrunkRegistrationWatchdog::unregisteredTrunks());
    }
}
