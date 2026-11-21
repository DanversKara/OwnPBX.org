<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Billing roles, Layer 1 (core 1.75.61).
 *
 * The plugin's schema.sql is CREATE TABLE IF NOT EXISTS only (PluginManager
 * forbids anything else), so new columns on the plugin tables of an
 * already-enabled plugin need this migration — same pattern as
 * 20261121000000_add_stock_base_domain_to_plg_resellers. schema.sql still
 * carries the columns for fresh installs.
 *
 *   - plg_billing_plans: one feat_* flag per unlockable module, plus the
 *     optional role_key tie (NULL = any role — the plan doc's "billing role
 *     = a plan + a role", with the any-subscriber grant as the default).
 *   - plg_billing_overrides.feature: 20 -> 32 wide, so the feat_* module
 *     flags fit as exception features ('feat_time_conditions' is 21).
 *   - owner_extension on the six owned-object tables (nullable: NULL =
 *     admin-owned/global, visible to admins only). Meetings use the existing
 *     host_user_id and boss-secretary the existing boss_extension, so those
 *     two tables gain no column.
 *
 * Every add is guarded (hasTable/hasColumn), so re-running the migration is
 * a no-op and a plugin that was never enabled has no table yet.
 */
final class AddBillingRoleColumns extends AbstractMigration
{
    /** @var list<string> */
    private const PLAN_FLAGS = [
        'feat_time_conditions',
        'feat_ivrs',
        'feat_queues',
        'feat_conferences',
        'feat_meetings',
        'feat_boss_secretary',
        'feat_ring_groups',
    ];

    /** @var list<string> */
    private const OWNER_TABLES = [
        'pbx_time_conditions',
        'pbx_time_groups',
        'pbx_ivrs',
        'pbx_queues',
        'pbx_conferences',
        'pbx_ring_groups',
    ];

    public function up(): void
    {
        if ($this->hasTable('plg_billing_plans')) {
            $plans = $this->table('plg_billing_plans');
            foreach (self::PLAN_FLAGS as $flag) {
                if (!$plans->hasColumn($flag)) {
                    $plans->addColumn($flag, 'boolean', ['null' => false, 'default' => false]);
                }
            }
            if (!$plans->hasColumn('role_key')) {
                $plans->addColumn('role_key', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'default' => null,
                    'comment' => 'Role this plan is tied to; NULL = any role',
                ]);
            }
            $plans->update();
        }

        if ($this->hasTable('plg_billing_overrides') && $this->table('plg_billing_overrides')->hasColumn('feature')) {
            $this->table('plg_billing_overrides')
                ->changeColumn('feature', 'string', ['limit' => 32, 'null' => false])
                ->update();
        }

        foreach (self::OWNER_TABLES as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }
            $table = $this->table($tableName);
            if (!$table->hasColumn('owner_extension')) {
                $table->addColumn('owner_extension', 'string', [
                    'limit' => 32,
                    'null' => true,
                    'default' => null,
                    'comment' => 'Owning extension; NULL = admin-owned/global',
                ])->addIndex(['owner_extension'])->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('plg_billing_plans')) {
            $plans = $this->table('plg_billing_plans');
            foreach (self::PLAN_FLAGS as $flag) {
                if ($plans->hasColumn($flag)) {
                    $plans->removeColumn($flag);
                }
            }
            if ($plans->hasColumn('role_key')) {
                $plans->removeColumn('role_key');
            }
            $plans->update();
        }

        if ($this->hasTable('plg_billing_overrides') && $this->table('plg_billing_overrides')->hasColumn('feature')) {
            $this->table('plg_billing_overrides')
                ->changeColumn('feature', 'string', ['limit' => 20, 'null' => false])
                ->update();
        }

        foreach (self::OWNER_TABLES as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }
            $table = $this->table($tableName);
            if ($table->hasColumn('owner_extension')) {
                $table->removeColumn('owner_extension')->update();
            }
        }
    }
}
