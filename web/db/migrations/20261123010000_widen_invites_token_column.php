<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Widen the invites token column (core 1.75.63).
 *
 * The invites plugin stores its tokens in plg_invites.token CHAR(32)
 * (plugins/invites/schema.sql). A SHA-256 hex hash needs 64 chars, so the
 * column must be CHAR(64) before invite tokens can be hashed at rest like
 * the portal's reset tokens (20261123000000). This migration only widens
 * the column — it is forward-compatible and harmless on its own: the plugin
 * still stores 32-char plaintext tokens until a later plugin change adds
 * the hashing (and updates schema.sql for fresh installs).
 *
 * Guarded (hasTable/hasColumn), so a system that never enabled the plugin
 * — or one where the table is not there yet — migrates as a no-op. Note the
 * ordering gap that guard implies: on a fresh install the plugin creates
 * the table from schema.sql when it is first enabled, which happens after
 * this migration has run, so a fresh install still gets CHAR(32) until the
 * follow-up plugin change updates schema.sql. Only already-enabled
 * installs are widened here.
 */
final class WidenInvitesTokenColumn extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('plg_invites')) {
            return;
        }
        $invites = $this->table('plg_invites');
        if (!$invites->hasColumn('token')) {
            return;
        }
        $invites->changeColumn('token', 'char', ['limit' => 64, 'null' => false])->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('plg_invites')) {
            return;
        }
        $invites = $this->table('plg_invites');
        if (!$invites->hasColumn('token')) {
            return;
        }
        // Refuses (or truncates, outside strict mode) if 64-char tokens are
        // already stored — by design: shrinking must not silently destroy
        // hashed tokens. Only roll back before the hashing change lands.
        $invites->changeColumn('token', 'char', ['limit' => 32, 'null' => false])->update();
    }
}
