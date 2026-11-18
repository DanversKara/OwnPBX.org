<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * *86 (block the caller during a call) becomes a feature code like the others.
 *
 * It lives in features.conf as an Asterisk "dynamic feature" because it is pressed during a
 * call rather than dialled, so it never appeared on the Feature Codes page: an administrator
 * could not see that it existed, change the digits, or switch it off. The row here is what
 * the page shows and what generates the applicationmap.
 */
final class BlockCallerFeatureCode extends AbstractMigration
{
    public function up(): void
    {
        $exists = $this->query("SELECT id FROM pbx_feature_codes WHERE feature_key = 'block_caller'")->fetchAll();
        if (!empty($exists)) {
            return;
        }
        $this->execute("INSERT INTO pbx_feature_codes (feature_key, title, code, allowed_roles, is_active)
            VALUES ('block_caller', 'Block the caller (press during a call)', '*86', 'admin,cc_manager,cc_agent,user', 1)");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pbx_feature_codes WHERE feature_key = 'block_caller'");
    }
}
