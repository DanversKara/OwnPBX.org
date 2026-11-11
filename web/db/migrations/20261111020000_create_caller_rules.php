<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Caller rules: first-match-wins inbound routing.
 *
 * Checked first on every incoming call (before the blacklist). A rule
 * matches when the called number (did_number) and the caller number
 * (caller_number) both match; an empty did_number means "any number" and
 * an empty caller_number means "any caller". Rules for one specific
 * number are checked before rules for any number; within each group the
 * lowest sort_order wins, then the oldest rule. The first match routes
 * the call to its destination (dest_type/dest_id) and stops.
 *
 * owner_extension attributes a rule to the user who made it (their own
 * list under Call filtering); NULL means a global/admin rule.
 */
final class CreateCallerRules extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('caller_rules')) {
            $this->table('caller_rules', ['signed' => false])
                ->addColumn('did_number', 'string', ['limit' => 64, 'null' => true, 'default' => null, 'comment' => 'Called number (digits); NULL = any number'])
                ->addColumn('caller_number', 'string', ['limit' => 64, 'null' => true, 'default' => null, 'comment' => 'Caller number (digits); NULL = any caller'])
                ->addColumn('dest_type', 'string', ['limit' => 32, 'null' => false, 'default' => 'extension'])
                ->addColumn('dest_id', 'string', ['limit' => 64, 'null' => false, 'default' => ''])
                ->addColumn('owner_extension', 'string', ['limit' => 32, 'null' => true, 'default' => null])
                ->addColumn('sort_order', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
                ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 1])
                ->addColumn('hit_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['is_active'])
                ->addIndex(['owner_extension'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('caller_rules')) {
            $this->table('caller_rules')->drop()->save();
        }
    }
}
