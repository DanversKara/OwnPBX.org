<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tenant identity for reseller accounts (Reseller plugin v1).
 *
 * sys_users.reseller_id ties a portal user to one reseller (plg_resellers.id).
 * NULL means the main admin's own users. Extensions ARE users in this codebase
 * (ExtensionRepository reads sys_users), so this one column covers both the
 * Users list and the Extensions list. DIDs, routes and plans gain their own
 * reseller_id in later phases.
 */
final class AddResellerIdToUsers extends AbstractMigration
{
    public function up(): void
    {
        $users = $this->table('sys_users');
        if (!$users->hasColumn('reseller_id')) {
            $users->addColumn('reseller_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => 'Reseller tenant (plg_resellers.id); NULL = the main admin\'s own users',
            ])->update();
        }
        if (!$users->hasIndex(['reseller_id'])) {
            $users->addIndex(['reseller_id'], ['name' => 'idx_users_reseller'])->update();
        }
    }

    public function down(): void
    {
        $users = $this->table('sys_users');
        if ($users->hasIndex(['reseller_id'])) {
            $users->removeIndex(['reseller_id'])->update();
        }
        if ($users->hasColumn('reseller_id')) {
            $users->removeColumn('reseller_id')->update();
        }
    }
}
