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
        $exists = $this->query("SELECT id FROM pbx_feature_codes WHERE feature_key = 'spy'")->fetchAll();
        if (empty($exists)) {
            $this->execute("INSERT INTO pbx_feature_codes (feature_key, title, code, allowed_roles, is_active) VALUES ('spy', 'ChanSpy listen-in', '*555', 'admin', 1)");
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pbx_feature_codes WHERE feature_key = 'spy'");
    }
}
