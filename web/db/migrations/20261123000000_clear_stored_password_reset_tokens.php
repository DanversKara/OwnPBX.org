<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reset tokens hashed at rest (core 1.75.63).
 *
 * From this version the portal stores only the SHA-256 hash of a password
 * reset token in sys_users.reset_token (see ForgotPasswordService), so a
 * database read can no longer hand out a usable reset link. Tokens issued
 * before the upgrade are stored verbatim and would never match the new
 * hash comparison in ResetPasswordRepository — and they must NOT be
 * "migrated" to hashes, because hashing a stolen plaintext token keeps it
 * usable. They are cleared instead: live tokens last 30 minutes (invites
 * 48 hours), so anyone mid-reset just asks for a new link.
 */
final class ClearStoredPasswordResetTokens extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_users')) {
            return;
        }
        $users = $this->table('sys_users');
        if (!$users->hasColumn('reset_token') || !$users->hasColumn('reset_token_expires')) {
            return;
        }
        $this->execute('UPDATE sys_users SET reset_token = NULL, reset_token_expires = NULL WHERE reset_token IS NOT NULL OR reset_token_expires IS NOT NULL');
    }

    public function down(): void
    {
        // One-way by design: cleared tokens cannot be restored, and
        // re-creating them would hand out reset links nobody asked for.
    }
}
