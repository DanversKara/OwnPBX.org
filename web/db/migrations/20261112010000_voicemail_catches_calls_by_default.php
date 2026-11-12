<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A voicemail box is reached when a call is not answered, the line is busy or
 * the phone is offline. These three switches used to start off, so a new
 * extension with voicemail "on" never got a message and callers heard busy.
 * They now start on (every way a user is created: the Extensions form, the
 * API, sign-in by OIDC/SAML, the Store), and boxes that are on but that no call
 * could ever reach are switched to catch all three.
 */
final class VoicemailCatchesCallsByDefault extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_users')) {
            return;
        }
        $t = $this->table('sys_users');
        foreach (['vm_on_noanswer', 'vm_on_busy', 'vm_on_unavail'] as $c) {
            if (!$t->hasColumn($c)) {
                return;
            }
        }
        $this->execute("ALTER TABLE sys_users
            MODIFY vm_on_noanswer TINYINT(1) NOT NULL DEFAULT 1,
            MODIFY vm_on_busy TINYINT(1) NOT NULL DEFAULT 1,
            MODIFY vm_on_unavail TINYINT(1) NOT NULL DEFAULT 1");
        $this->execute("UPDATE sys_users SET vm_on_noanswer = 1, vm_on_busy = 1, vm_on_unavail = 1
            WHERE voicemail_enabled = 1 AND vm_on_noanswer = 0 AND vm_on_busy = 0 AND vm_on_unavail = 0
              AND extension IS NOT NULL AND extension <> '' AND extension_type <> 'fax'");
    }

    public function down(): void
    {
        if (!$this->hasTable('sys_users')) {
            return;
        }
        $this->execute("ALTER TABLE sys_users
            MODIFY vm_on_noanswer TINYINT(1) NOT NULL DEFAULT 0,
            MODIFY vm_on_busy TINYINT(1) NOT NULL DEFAULT 0,
            MODIFY vm_on_unavail TINYINT(1) NOT NULL DEFAULT 0");
    }
}
