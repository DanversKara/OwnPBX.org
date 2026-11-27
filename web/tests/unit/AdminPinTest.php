<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/services/AdminPinService.php';
require_once dirname(__DIR__, 2) . '/src/controllers/AdminPinController.php';

/**
 * The admin PIN: the third sign-in step, and the tripwire that seals the vault.
 *
 * This shipped without tests, which for a control whose failure mode is "sealing the production
 * secret store" is the wrong way round. The properties below are the ones worth holding still:
 * a wrong PIN costs exactly one try, the third seals and locks, a correct PIN clears the slate,
 * the window is what lets a locked-out admin back in, and only an admin is ever asked.
 */
final class AdminPinTest extends TestCase
{
    private int $adminId = 0;
    private int $userId = 0;
    private const PIN = '482915';
    /**
     * Not 127.0.0.1. A wrong PIN is recorded as a failed sign-in against the client IP - which is
     * right, and gives PIN guessing the same five-tries-per-IP ceiling as the password - but it
     * means a dozen deliberate wrong PINs here would lock out the address every other test signs
     * in from. A documentation address of its own keeps this test's noise to itself.
     */
    private const IP = '203.0.113.77';

    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE username IN ('zz_pin_admin', 'zz_pin_user')");
        $stmt = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, role, extension, is_active) VALUES (?, ?, ?, ?, ?, 1)');
        $stmt->execute(['zz_pin_admin', password_hash('x', PASSWORD_DEFAULT), 'PIN Admin', 'admin', '7951']);
        $this->adminId = (int) $db->lastInsertId();
        $stmt->execute(['zz_pin_user', password_hash('x', PASSWORD_DEFAULT), 'PIN User', 'user', '7952']);
        $this->userId = (int) $db->lastInsertId();
        AdminPinService::setPin($this->adminId, self::PIN);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE username IN ('zz_pin_admin', 'zz_pin_user')");
        $db->exec("DELETE FROM sys_login_logs WHERE ip_address = '" . self::IP . "'");
        $db->exec("DELETE FROM sys_audit_log WHERE action IN ('admin_pin_failed', 'admin_pin_lockout')"
            . " AND entity_id = '" . (int) $this->adminId . "'");
        $_SESSION = [];
    }

    /** @return list<array<string,mixed>> the PIN audit rows for this test's admin, oldest first */
    private function pinAuditRows(string $action): array
    {
        $q = getDB()->prepare(
            'SELECT entity_label, ip_address FROM sys_audit_log
              WHERE action = ? AND entity_id = ? ORDER BY id'
        );
        $q->execute([$action, (string) $this->adminId]);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function failures(): int
    {
        $q = getDB()->prepare('SELECT admin_pin_failures FROM sys_users WHERE id = ?');
        $q->execute([$this->adminId]);
        return (int) $q->fetchColumn();
    }

    // ------------------------------------------------------------- the format

    public function testOnlySixToTwelveDigitsAreAPin(): void
    {
        $this->assertTrue(AdminPinService::isValidPinFormat('123456'));
        $this->assertTrue(AdminPinService::isValidPinFormat('123456789012'));
        $this->assertFalse(AdminPinService::isValidPinFormat('12345'), 'too short');
        $this->assertFalse(AdminPinService::isValidPinFormat('1234567890123'), 'too long');
        $this->assertFalse(AdminPinService::isValidPinFormat('12345a'), 'not digits');
        $this->assertFalse(AdminPinService::isValidPinFormat(''));
        $this->assertFalse(AdminPinService::isValidPinFormat('123 456'));
    }

    public function testAPinIsStoredHashedAndNeverInTheClear(): void
    {
        $q = getDB()->prepare('SELECT admin_pin_hash FROM sys_users WHERE id = ?');
        $q->execute([$this->adminId]);
        $hash = (string) $q->fetchColumn();
        $this->assertNotSame(self::PIN, $hash);
        $this->assertStringNotContainsString(self::PIN, $hash);
        $this->assertTrue(password_verify(self::PIN, $hash));
    }

    // -------------------------------------------------------------- the tries

    public function testTheRightPinOpensItAndClearsTheCount(): void
    {
        AdminPinService::verifyPin($this->adminId, '000000', self::IP);
        $this->assertSame(1, $this->failures());

        $res = AdminPinService::verifyPin($this->adminId, self::PIN, self::IP);
        $this->assertTrue($res['ok']);
        $this->assertSame(0, $this->failures(), 'A correct PIN wipes the slate.');
    }

    public function testEachWrongPinCostsExactlyOneTry(): void
    {
        $first = AdminPinService::verifyPin($this->adminId, '000000', self::IP);
        $this->assertSame(1, $first['failures']);
        $second = AdminPinService::verifyPin($this->adminId, '000001', self::IP);
        $this->assertSame(2, $second['failures']);
        $this->assertSame(2, $this->failures());
    }

    public function testTheThirdWrongPinSealsAndThenRefusesEvenTheRightOne(): void
    {
        AdminPinService::verifyPin($this->adminId, '000000', self::IP);
        AdminPinService::verifyPin($this->adminId, '000001', self::IP);
        $third = AdminPinService::verifyPin($this->adminId, '000002', self::IP);

        $this->assertTrue($third['sealed'] ?? false, 'The third wrong PIN must trip the seal.');
        $this->assertFalse($third['ok']);

        // Fail closed: with no seal token configured the seal cannot be confirmed, and the
        // lockout must apply anyway - otherwise an unreachable vault would mean endless tries.
        $this->assertArrayHasKey('seal_ok', $third);
        $locked = AdminPinService::verifyPin($this->adminId, self::PIN, self::IP);
        $this->assertTrue($locked['locked'] ?? false, 'Even the correct PIN is refused once locked.');
        $this->assertFalse($locked['ok'] ?? false);
    }

    public function testTheWindowExpiringGivesTheAdminTheirTriesBack(): void
    {
        AdminPinService::verifyPin($this->adminId, '000000', self::IP);
        AdminPinService::verifyPin($this->adminId, '000001', self::IP);
        AdminPinService::verifyPin($this->adminId, '000002', self::IP);
        $this->assertTrue(AdminPinService::verifyPin($this->adminId, self::PIN, self::IP)['locked'] ?? false);

        // Age the window out: this is the whole recovery path, so it has to work. The database
        // does the arithmetic on its own clock - handing it a timestamp PHP formatted would put
        // the row hours out wherever the two disagree about the time zone, which is the very
        // thing this window got wrong.
        $q = getDB()->prepare('UPDATE sys_users SET admin_pin_failures_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?');
        $q->execute([AdminPinService::FAILURE_WINDOW_SECONDS + 60, $this->adminId]);

        $res = AdminPinService::verifyPin($this->adminId, self::PIN, self::IP);
        $this->assertTrue($res['ok'] ?? false, 'Once the window has passed the PIN works again.');
    }

    // --------------------------------------------------------------- who asks

    public function testANonAdminIsNeverAskedForAPin(): void
    {
        $res = AdminPinService::verifyPin($this->userId, self::PIN, self::IP);
        $this->assertFalse($res['ok'] ?? false);
        $this->assertSame('not_admin', $res['error'] ?? '');
    }

    public function testAnAdminWithNoPinYetIsSentToSetUpOne(): void
    {
        getDB()->prepare('UPDATE sys_users SET admin_pin_hash = NULL WHERE id = ?')->execute([$this->adminId]);
        $res = AdminPinService::verifyPin($this->adminId, self::PIN, self::IP);
        $this->assertTrue($res['needs_setup'] ?? false);
        $this->assertFalse($res['ok'] ?? false);
    }

    public function testADisabledAccountIsRefused(): void
    {
        getDB()->prepare('UPDATE sys_users SET is_active = 0 WHERE id = ?')->execute([$this->adminId]);
        $res = AdminPinService::verifyPin($this->adminId, self::PIN, self::IP);
        $this->assertSame('invalid', $res['error'] ?? '');
    }

    // ------------------------------------------------------------- sudo mode

    public function testSudoModeGoesStaleAfterItsWindow(): void
    {
        $this->assertFalse(AdminPinService::sudoFresh(), 'Nothing entered yet.');
        AdminPinService::markSudoFresh();
        $this->assertTrue(AdminPinService::sudoFresh());

        $_SESSION['admin_pin_at'] = time() - AdminPinService::SUDO_WINDOW_SECONDS - 1;
        $this->assertFalse(AdminPinService::sudoFresh(), 'Past the window the PIN is asked again.');
    }

    // ---------------------------------------------------- the way back after

    /**
     * Sudo mode returns the admin to the page they wanted, and ?next= is theirs to set, so it
     * must not be able to send them off this site.
     */
    public function testTheReturnPathCannotLeaveTheSite(): void
    {
        $m = new ReflectionMethod(AdminPinController::class, 'safeNext');
        $m->setAccessible(true);
        foreach (['https://evil.example', '//evil.example', 'http://evil.example/x', 'javascript:alert(1)'] as $bad) {
            $this->assertSame('', $m->invoke(null, $bad), "{$bad} must not be accepted");
        }
        $this->assertSame('/system-users', $m->invoke(null, '/system-users'));
        $this->assertSame('/plugins?tab=catalog', $m->invoke(null, '/plugins?tab=catalog'));
    }

    // ------------------------------------------------------- the trail it leaves

    /**
     * A wrong PIN must not feed the password throttle - and must still be recorded.
     *
     * Those are two separate requirements and the fix for the first one removed the second.
     * checkBruteForceLockout() runs before verifyPin() is ever reached, so logging a wrong PIN
     * as a failed login locked the admin out of the PIN page as well: the correct PIN then
     * bounced too, and the only way back in was to wait. Right to stop doing that. But the
     * lockout record is written only on the third try, so with nothing here an attacker could
     * guess two PINs an hour indefinitely and leave the owner nothing to find.
     */
    public function testEveryWrongPinIsRecordedWithoutTouchingThePasswordThrottle(): void
    {
        AdminPinService::verifyPin($this->adminId, '999999', self::IP);
        AdminPinService::verifyPin($this->adminId, '888888', self::IP);

        $rows = $this->pinAuditRows('admin_pin_failed');
        $this->assertCount(2, $rows, 'Each wrong PIN leaves one audit entry.');
        $this->assertStringContainsString(self::IP, (string) $rows[0]['entity_label'], 'It records where from.');
        $this->assertStringContainsString('attempt 1', (string) $rows[0]['entity_label']);
        $this->assertStringContainsString('attempt 2', (string) $rows[1]['entity_label']);

        // And nothing in the login log, which is what the password throttle and fail2ban read.
        $q = getDB()->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE ip_address = ?");
        $q->execute([self::IP]);
        $this->assertSame(
            0,
            (int) $q->fetchColumn(),
            'A wrong PIN must not reach the password throttle, or the right PIN cannot get in either.'
        );
    }

    /** The third try keeps its own, louder record on top of the per-attempt one. */
    public function testTheLockoutIsRecordedSeparatelyFromTheAttempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            AdminPinService::verifyPin($this->adminId, '777777', self::IP);
        }
        $this->assertCount(3, $this->pinAuditRows('admin_pin_failed'));
        $this->assertCount(1, $this->pinAuditRows('admin_pin_lockout'));
    }

    /**
     * The wait shown on the PIN page is computed from the window start, so that value has to be
     * an epoch. It is the field that caused the original bug: read with strtotime() against a
     * database eight hours off PHP's clock, the window looked expired on every attempt and the
     * third strike never arrived. A DATETIME string here would cast to its year and the page
     * would promise "try again in 0 minutes" forever.
     */
    public function testTheWindowStartIsAnEpochSoTheWaitCanBeShown(): void
    {
        AdminPinService::verifyPin($this->adminId, '999999', self::IP);
        $row = AdminPinService::loadPinRow($this->adminId);
        $startedAt = (int) ($row['admin_pin_failures_at'] ?? 0);

        $this->assertGreaterThan(time() - 300, $startedAt, 'The window start must be a recent epoch.');
        $this->assertLessThanOrEqual(time() + 5, $startedAt);

        $remaining = $startedAt + AdminPinService::FAILURE_WINDOW_SECONDS - time();
        $this->assertGreaterThan(0, $remaining, 'The page must have a positive wait to show.');
        $this->assertLessThanOrEqual(AdminPinService::FAILURE_WINDOW_SECONDS, $remaining);
    }
}
