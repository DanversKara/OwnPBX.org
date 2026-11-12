<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-user chat conversation deletions: deleting a conversation hides it
 * from that user's Chat list only. The conversation row and its messages
 * stay, the other participants keep their copy, and a new message revives
 * the thread for everyone (chat/db.go clears this table in SaveMessage).
 * Unlike SMS delete_thread, which hard-deletes the shared thread's messages,
 * this is a hide — never a destroy.
 */
final class AddChatConversationDeletions extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('chat_conversation_deletions')) {
            $this->table('chat_conversation_deletions', ['id' => false, 'primary_key' => ['conversation_id', 'user_ext']])
                ->addColumn('conversation_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'chat_conversations.id'])
                ->addColumn('user_ext', 'string', ['limit' => 32, 'null' => false, 'comment' => 'Extension that deleted the conversation from their list'])
                ->addColumn('deleted_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['user_ext'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('chat_conversation_deletions')) {
            $this->table('chat_conversation_deletions')->drop()->save();
        }
    }
}
