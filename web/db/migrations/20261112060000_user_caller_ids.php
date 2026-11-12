<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Outgoing numbers an extension may show on outside calls (pbx_user_caller_ids): the
 * administrator ticks DIDs per extension, one DID may go to many extensions. The user picks
 * one of them as the default (sys_users.cid_external, as before) and may pick another per call
 * (the web phone and the app send it; the dialplan accepts only a number on this list).
 */
final class UserCallerIds extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_user_caller_ids')) {
            return;
        }
        $this->execute("CREATE TABLE pbx_user_caller_ids (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            number VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_number (user_id, number),
            KEY idx_number (number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS pbx_user_caller_ids');
    }
}
