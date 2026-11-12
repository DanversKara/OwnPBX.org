<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The chat service wrote its own texts in Turkish (group created / member added / removed / left /
 * info updated, and the photo and voice-message previews). It writes English now; this translates
 * what is already stored, in messages and in the conversation list's last-message preview.
 * Only those exact sentences change; people's own messages are not touched.
 */
final class TranslateTurkishChatTexts extends AbstractMigration
{
    /** MariaDB REGEXP_REPLACE (backreferences \1, \2). */
    private const SYSTEM = [
        ['^(.*) "(.*)" grubunu oluşturdu$', '\\1 created the group "\\2"'],
        ['^(.*?), (.*) kullanıcısını gruba ekledi$', '\\1 added \\2 to the group'],
        ['^(.*?), (.*) kullanıcısını gruptan çıkardı$', '\\1 removed \\2 from the group'],
        ['^(.*) gruptan ayrıldı$', '\\1 left the group'],
        ['^(.*) grup bilgilerini güncelledi$', '\\1 updated the group info'],
    ];

    private const PREVIEWS = [
        ['📷 [Fotoğraf]', '📷 [Photo]'],
        ['🎤 [Ses Kaydı]', '🎤 [Voice message]'],
    ];

    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        if ($this->hasTable('chat_messages')) {
            foreach (self::SYSTEM as [$from, $to]) {
                $this->run($pdo, "UPDATE chat_messages SET message = REGEXP_REPLACE(message, ?, ?) WHERE msg_type = 'system' AND message REGEXP ?", [$from, $to, $from]);
            }
        }
        if ($this->hasTable('chat_conversations') && $this->table('chat_conversations')->hasColumn('last_message_text')) {
            foreach (self::SYSTEM as [$from, $to]) {
                $this->run($pdo, 'UPDATE chat_conversations SET last_message_text = REGEXP_REPLACE(last_message_text, ?, ?) WHERE last_message_text REGEXP ?', [$from, $to, $from]);
            }
            foreach (self::PREVIEWS as [$from, $to]) {
                $this->run($pdo, 'UPDATE chat_conversations SET last_message_text = ? WHERE last_message_text = ?', [$to, $from]);
            }
        }
    }

    private function run(\PDO $pdo, string $sql, array $args): void
    {
        try {
            $pdo->prepare($sql)->execute($args);
        } catch (\PDOException $e) {
            // A database without REGEXP_REPLACE keeps the old texts; new ones are English anyway.
            $this->getOutput()->writeln('translate chat texts: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
    }
}
