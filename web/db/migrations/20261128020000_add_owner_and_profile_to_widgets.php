<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Sub-accounts for web widgets and business cards, and the widget/profile choice (1.75.84).
 *
 * owner_extension is the same column the billing role bridge already uses on time
 * conditions, time groups, IVRs, queues, conferences and ring groups: NULL means the row
 * belongs to the system (an admin made it, everyone with the module sees it), and an
 * extension means it belongs to that account and nobody else's list may show it. Adding it
 * here is what lets a reseller or a plan user keep their own widgets and cards without
 * seeing anyone else's.
 *
 * Nullable with no default and no backfill, deliberately. Every widget and card that exists
 * today was made by an admin, and NULL is exactly what "admin-owned" means to
 * BillingRoleBridge - so this migration cannot change who can see an existing row. Stamping
 * them with some current user's extension would hand one account ownership of everything
 * that came before it.
 *
 * profile_enabled answers "just a call widget, or a call widget with a profile?". Default 0,
 * so nothing that exists today starts publishing anything: an admin turns it on for the
 * widgets that should have cards issued against them. Business cards remain one per person
 * with their own token - this flag only decides which widgets the card editor will offer.
 */
final class AddOwnerAndProfileToWidgets extends AbstractMigration
{
    public function up(): void
    {
        $widgets = $this->table('pbx_web_widgets');
        if (!$widgets->hasColumn('owner_extension')) {
            $widgets->addColumn('owner_extension', 'string', ['limit' => 32, 'null' => true, 'default' => null, 'after' => 'name'])
                ->addIndex(['owner_extension'])
                ->update();
        }
        if (!$widgets->hasColumn('profile_enabled')) {
            $widgets->addColumn('profile_enabled', 'boolean', ['null' => false, 'default' => 0, 'after' => 'is_active'])
                ->update();
        }

        $cards = $this->table('pbx_business_cards');
        if ($cards->exists() && !$cards->hasColumn('owner_extension')) {
            $cards->addColumn('owner_extension', 'string', ['limit' => 32, 'null' => true, 'default' => null, 'after' => 'label'])
                ->addIndex(['owner_extension'])
                ->update();
        }

        // A card issued against a widget that already has cards means its owner wanted a
        // profile there, so the flag follows what is already true rather than being switched
        // off under a working card. Runs only when both tables are present.
        if ($cards->exists()) {
            $this->execute(
                'UPDATE pbx_web_widgets w
                    SET w.profile_enabled = 1
                  WHERE EXISTS (SELECT 1 FROM pbx_business_cards c WHERE c.widget_id = w.id)'
            );
        }
    }

    public function down(): void
    {
        $widgets = $this->table('pbx_web_widgets');
        foreach (['owner_extension', 'profile_enabled'] as $col) {
            if ($widgets->hasColumn($col)) {
                $widgets->removeColumn($col)->update();
            }
        }
        $cards = $this->table('pbx_business_cards');
        if ($cards->exists() && $cards->hasColumn('owner_extension')) {
            $cards->removeColumn('owner_extension')->update();
        }
    }
}
