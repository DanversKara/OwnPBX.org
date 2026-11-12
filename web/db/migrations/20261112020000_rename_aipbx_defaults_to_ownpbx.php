<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The product is OwnPBX: names stored as settings that still carry the old default name
 * ("AiPBX", "AI PBX ...") are renamed. Only these settings, and only the old name inside them,
 * change; a title or sender name the administrator typed keeps everything else.
 */
final class RenameAipbxDefaultsToOwnpbx extends AbstractMigration
{
    private const KEYS = [
        'site_title', 'brand_title', 'mail_from_name', 'portal_email_from_name',
        'fax_email_from_name', 'fax_from_name', 'fax_header_info', 'fax_local_station_id',
        'fax_station_id', 'pjsip_user_agent',
    ];

    public function up(): void
    {
        if ($this->hasTable('sys_settings')) {
            $in = "'" . implode("','", self::KEYS) . "'";
            $this->execute("UPDATE sys_settings
                SET setting_value = REPLACE(REPLACE(REPLACE(REPLACE(setting_value,
                    'AI PBX Faks Sistemi', 'OwnPBX Fax'), 'AI PBX', 'OwnPBX'), 'Ai PBX', 'OwnPBX'), 'AiPBX', 'OwnPBX')
                WHERE setting_key IN ({$in})
                  AND (setting_value LIKE '%AiPBX%' OR setting_value LIKE '%AI PBX%' OR setting_value LIKE '%Ai PBX%')");
        }
        if ($this->hasTable('sys_did_mappings') && $this->table('sys_did_mappings')->hasColumn('header_info')) {
            $this->execute("UPDATE sys_did_mappings SET header_info = 'OwnPBX Fax' WHERE header_info = 'AI PBX Fax'");
            $this->execute("ALTER TABLE sys_did_mappings ALTER COLUMN header_info SET DEFAULT 'OwnPBX Fax'");
        }
    }

    public function down(): void
    {
    }
}
