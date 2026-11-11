<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Guest accounts: a restricted account type (ported from the old OwnPBX).
 * Internal calls always work; outside calls, outside callers ringing in,
 * SMS and fax are each OFF for a guest unless the admin turns them on.
 * Emergency numbers are ALWAYS blocked for guests (no column: not a choice).
 * Which devices a guest may use (web phone, app, SIP, video) is the existing
 * allowed_phone_mode, enforced strictly for guests (see SyncExtensions).
 */
final class AddGuestAccounts extends AbstractMigration
{
    private const COLUMNS = ['is_guest', 'guest_outbound', 'guest_inbound', 'guest_sms', 'guest_fax'];

    public function up(): void
    {
        $table = $this->table('sys_users');
        $after = 'allowed_phone_mode';
        foreach (self::COLUMNS as $col) {
            if (!$table->hasColumn($col)) {
                $table->addColumn($col, 'boolean', ['default' => 0, 'null' => false, 'after' => $after]);
            }
            $after = $col;
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('sys_users');
        foreach (self::COLUMNS as $col) {
            if ($table->hasColumn($col)) {
                $table->removeColumn($col);
            }
        }
        $table->update();
    }
}
