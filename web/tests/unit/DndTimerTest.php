<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/DndTimerService.php';

/** Do not disturb that switches itself off after a chosen time. */
final class DndTimerTest extends TestCase
{
    private int $uid = 0;

    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE username = 'dndtimer'");
        $db->exec("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active, dnd_enabled) VALUES ('dndtimer', 'x', 'Dnd', 'user', '7811', 'sip', 1, 1)");
        $this->uid = (int) $db->lastInsertId();
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM sys_users WHERE username = 'dndtimer'");
    }

    public function testTimerIsSetKeptAndCleared(): void
    {
        DndTimerService::apply($this->uid, true, 480);
        $this->assertSame(480, DndTimerService::minutesLeft($this->uid));
        DndTimerService::apply($this->uid, true, null); // other settings saved: timer untouched
        $this->assertSame(480, DndTimerService::minutesLeft($this->uid));
        DndTimerService::apply($this->uid, true, 0);    // "never"
        $this->assertSame(0, DndTimerService::minutesLeft($this->uid));
        DndTimerService::apply($this->uid, true, 30);
        DndTimerService::apply($this->uid, false, null); // DND off: no timer left behind
        $this->assertSame('', (string) DBHelper::fetchColumn('SELECT dnd_until FROM sys_users WHERE id = ?', [$this->uid]));
    }

    public function testExpireTurnsDndOffOnlyWhenTheTimeHasPassed(): void
    {
        DndTimerService::apply($this->uid, true, 10);
        $this->assertSame([], array_values(array_intersect(DndTimerService::expire(), ['7811'])));
        $this->assertSame(1, (int) DBHelper::fetchColumn('SELECT dnd_enabled FROM sys_users WHERE id = ?', [$this->uid]));

        getDB()->prepare('UPDATE sys_users SET dnd_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')->execute([$this->uid]);
        $this->assertTrue(in_array('7811', DndTimerService::expire(), true));
        $this->assertSame(0, (int) DBHelper::fetchColumn('SELECT dnd_enabled FROM sys_users WHERE id = ?', [$this->uid]));
        $this->assertSame([], DndTimerService::expire()); // nothing twice
    }

    public function testLabels(): void
    {
        $this->assertSame('30 min', DndTimerService::label(30));
        $this->assertSame('8 h', DndTimerService::label(480));
        $this->assertSame('7 h 20 min', DndTimerService::label(440));
    }
}
