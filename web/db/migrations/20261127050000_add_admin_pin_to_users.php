<?php

use Phinx\Migration\AbstractMigration;

/**
 * Admin PIN columns on sys_users (core 1.75.66).
 *
 * The admin PIN is the third sign-in step for administrators (after the
 * password and, when enabled, the TOTP code) and the sudo-mode PIN for
 * admin-only settings pages. It is per-admin, like the TOTP secret:
 *  - admin_pin_hash: password_hash() of the 6-12 digit PIN, NULL until the
 *    admin sets one (first sign-in after this update forces setup).
 *  - admin_pin_failures: wrong PINs in the current window, persisted so the
 *    count survives a restart.
 *  - admin_pin_failures_at: when the current window started; failures older
 *    than AdminPinService::FAILURE_WINDOW_SECONDS reset the counter, so a
 *    locked-out admin can recover after unsealing the vault.
 *
 * One-way by design: a hash cannot be turned back into the PIN. down()
 * removes the columns; any PINs set are lost with them and admins set a
 * new PIN on their next sign-in.
 */
final class AddAdminPinToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_users')) {
            return;
        }
        $table = $this->table('sys_users');
        if (!$table->hasColumn('admin_pin_hash')) {
            $table->addColumn('admin_pin_hash', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'two_factor_confirmed_at']);
        }
        if (!$table->hasColumn('admin_pin_failures')) {
            $table->addColumn('admin_pin_failures', 'integer', ['limit' => 11, 'default' => 0, 'null' => false, 'after' => 'admin_pin_hash']);
        }
        if (!$table->hasColumn('admin_pin_failures_at')) {
            $table->addColumn('admin_pin_failures_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'admin_pin_failures']);
        }
        $table->save();
    }

    public function down(): void
    {
        if (!$this->hasTable('sys_users')) {
            return;
        }
        $table = $this->table('sys_users');
        if ($table->hasColumn('admin_pin_failures_at')) {
            $table->removeColumn('admin_pin_failures_at');
        }
        if ($table->hasColumn('admin_pin_failures')) {
            $table->removeColumn('admin_pin_failures');
        }
        if ($table->hasColumn('admin_pin_hash')) {
            $table->removeColumn('admin_pin_hash');
        }
        $table->save();
    }
}
