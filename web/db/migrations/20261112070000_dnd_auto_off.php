<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Do not disturb can switch itself off: sys_users.dnd_until is when (NULL = until the user
 * turns it off). task_dispatcher.php (cron, every minute) clears DND once the time has passed.
 */
final class DndAutoOff extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('sys_users');
        if (!$t->hasColumn('dnd_until')) {
            $t->addColumn('dnd_until', 'datetime', ['null' => true, 'default' => null, 'after' => 'dnd_enabled'])->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('sys_users');
        if ($t->hasColumn('dnd_until')) {
            $t->removeColumn('dnd_until')->update();
        }
    }
}
