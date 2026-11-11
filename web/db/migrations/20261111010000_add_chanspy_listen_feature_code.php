<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * ChanSpy listen-in (*555): seed the 'spy' feature code.
 *
 * Listens in on another extension's active call (listen-only, no whisper
 * or barge). Dial *555<ext> in one go (e.g. *5551000) or dial *555 alone
 * and enter the extension when prompted. Admin-only: only users with the
 * admin role may listen in (matches the old OwnPBX *555, which was
 * admin-only). The role gate is enforced by SyncFeatureCodes from the
 * allowed_roles column; widen it in the Features admin page if needed.
 */
final class AddChanSpyListenFeatureCode extends AbstractMigration
{
    public function up(): void
    {
        // The upstream default for the listen feature is *90 (roles
        // admin,cc_manager). This fork uses *555, admin-only, so move the
        // existing row to *555 (and tighten the roles) instead of leaving
        // the upstream *90 in place; insert it if it is not there at all.
        $exists = $this->query("SELECT id FROM pbx_feature_codes WHERE feature_key = 'spy'")->fetchAll();
        if (!empty($exists)) {
            $this->execute("UPDATE pbx_feature_codes SET code = '*555', allowed_roles = 'admin', is_active = 1 WHERE feature_key = 'spy'");
        } else {
            $this->execute("INSERT INTO pbx_feature_codes (feature_key, title, code, allowed_roles, is_active) VALUES ('spy', 'ChanSpy listen-in', '*555', 'admin', 1)");
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pbx_feature_codes WHERE feature_key = 'spy'");
    }
}
