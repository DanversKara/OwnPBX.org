<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/auth.php';

/**
 * The page checks render every route as user id 1, which is a real account on a real PBX.
 *
 * Reported from a live system: the sidebar kept warning the administrator that "another admin is
 * currently active". Nobody was - bin/smoke.php was running, and each of its hundreds of page
 * renders updated last_seen_at for that account, which is exactly what the warning watches.
 */
final class SmokeRenderSessionTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function lastSeen(int $id): ?string
    {
        $q = getDB()->prepare('SELECT last_seen_at FROM sys_users WHERE id = ?');
        $q->execute([$id]);
        $v = $q->fetchColumn();
        // A NULL column comes back as null, and "no row" as false: casting either to a string
        // would turn "never seen" into an empty string, which is not the same thing.
        return ($v === false || $v === null) ? null : (string) $v;
    }

    public function testASmokeRenderDoesNotMarkTheAccountActive(): void
    {
        $id = (int) getDB()->query('SELECT id FROM sys_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        $this->assertGreaterThan(0, $id, 'The fixtures should have at least one user.');
        getDB()->prepare('UPDATE sys_users SET last_seen_at = NULL WHERE id = ?')->execute([$id]);

        $_SESSION['_smoke_render'] = true;
        _touchLastSeen($id);
        $this->assertNull($this->lastSeen($id), 'A rendered page is not somebody signing in.');
    }

    public function testARealRequestStillMarksTheAccountActive(): void
    {
        $id = (int) getDB()->query('SELECT id FROM sys_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        getDB()->prepare('UPDATE sys_users SET last_seen_at = NULL WHERE id = ?')->execute([$id]);

        _touchLastSeen($id);
        $this->assertNotNull($this->lastSeen($id), 'A signed-in person must still count as active.');
    }
}
