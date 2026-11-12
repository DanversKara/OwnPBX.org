<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Replying in the internal chat: a message can answer another message in the
 * same conversation. Only the target id is stored; the quoted sender and text
 * come from the replied-to row at read time (chat/db.go messageSelect).
 */
final class AddReplyToToChatMessages extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('chat_messages')) {
            return;
        }
        $table = $this->table('chat_messages');
        if (!$table->hasColumn('reply_to')) {
            $table->addColumn('reply_to', 'biginteger', ['null' => true, 'after' => 'deleted_at']);
        }
        $table->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('chat_messages')) {
            return;
        }
        $table = $this->table('chat_messages');
        if ($table->hasColumn('reply_to')) {
            $table->removeColumn('reply_to');
        }
        $table->update();
    }
}
