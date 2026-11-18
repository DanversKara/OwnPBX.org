<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Blocking a caller with *86 stops them reaching that one person, not the whole PBX.
 *
 * Entries already in the blacklist keep working as they did: one with no owner is a
 * PBX-wide block (what an administrator adds on the Blacklist page), one with an owner
 * now belongs to that extension alone. `may_block_callers` lets an administrator take
 * *86 away from a user.
 */
final class PerUserCallBlocking extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('sys_users');
        if (!$t->hasColumn('may_block_callers')) {
            $t->addColumn('may_block_callers', 'boolean', [
                'default' => true,
                'null' => false,
                'comment' => 'May block callers with *86 (their own list only)',
            ])->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('sys_users');
        if ($t->hasColumn('may_block_callers')) {
            $t->removeColumn('may_block_callers')->update();
        }
    }
}
