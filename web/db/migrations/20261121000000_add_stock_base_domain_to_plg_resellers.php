<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-reseller stock base domain (Reseller plugin v1.0.1).
 *
 * The plugin's schema.sql is CREATE TABLE IF NOT EXISTS only (PluginManager
 * forbids anything else), so adding the column there cannot alter the table
 * of an already-enabled plugin — this migration does it instead, with the
 * same hasTable/hasColumn guards the other migrations use. Rows keep NULL
 * until an admin picks a base for them, which means "use the effective
 * global base" — so the existing Test reseller keeps test.pbx.ownpbx.org.
 * A plugin that was never enabled has no table yet; schema.sql creates the
 * column for it on first enable.
 */
final class AddStockBaseDomainToPlgResellers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('plg_resellers')) {
            return;
        }
        $resellers = $this->table('plg_resellers');
        if (!$resellers->hasColumn('stock_base_domain')) {
            $resellers->addColumn('stock_base_domain', 'string', [
                'limit' => 253,
                'null' => true,
                'default' => null,
                'comment' => 'Per-reseller stock base domain; NULL = the effective global base',
            ])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('plg_resellers')) {
            return;
        }
        $resellers = $this->table('plg_resellers');
        if ($resellers->hasColumn('stock_base_domain')) {
            $resellers->removeColumn('stock_base_domain')->update();
        }
    }
}
