package main

import (
	"fmt"
	"testing"
	"time"
)

// Replying in the internal chat: a reply stores the target, history renders
// the quote, a target from another conversation is refused, and a deleted
// target still accepts the reply with the quote shown as deleted.
func TestReplyMessageEndToEnd(t *testing.T) {
	a, b := testExtensions(t)

	mkConv := func(key string) int {
		res, err := db.Exec(`INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
			VALUES ('direct', ?, ?, NOW(), NOW(), 0)`, key, a)
		if err != nil {
			t.Fatalf("create test conversation: %v", err)
		}
		id64, _ := res.LastInsertId()
		convID := int(id64)
		if _, err := db.Exec(`INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
			VALUES (?, ?, 'member', NOW()), (?, ?, 'member', NOW())`, convID, a, convID, b); err != nil {
			t.Fatalf("add participants: %v", err)
		}
		return convID
	}

	convID := mkConv(fmt.Sprintf("test-reply-%d", time.Now().UnixNano()))
	defer removeTestConversation(convID)
	otherConvID := mkConv(fmt.Sprintf("test-reply-other-%d", time.Now().UnixNano()))
	defer removeTestConversation(otherConvID)

	hub := NewHub()
	hub.uploadDir = t.TempDir()

	orig, err := SaveMessage(convID, a, "text", "hello there", "", "", 0, "", 0)
	if err != nil {
		t.Fatalf("SaveMessage: %v", err)
	}
	other, err := SaveMessage(otherConvID, a, "text", "another chat", "", "", 0, "", 0)
	if err != nil {
		t.Fatalf("SaveMessage: %v", err)
	}

	// A reply to a message from another conversation is refused.
	if _, e := hub.SendMessage(&User{Extension: b}, &InMessage{
		Action: "send_message", ConversationID: convID, MsgType: "text",
		Message: "nope", ReplyTo: other.ID,
	}); e == nil || e.status != 400 {
		t.Fatalf("reply to another conversation's message must be refused with 400, got %v", e)
	}

	// A reply in the same conversation stores the target and renders the quote.
	saved, e := hub.SendMessage(&User{Extension: b}, &InMessage{
		Action: "send_message", ConversationID: convID, MsgType: "text",
		Message: "yes, hello", ReplyTo: orig.ID,
	})
	if e != nil {
		t.Fatalf("the reply was refused: %s", e.msg)
	}
	if saved.ReplyTo != orig.ID || saved.ReplySenderExt != a || saved.ReplyMessage != "hello there" {
		t.Fatalf("the reply must carry its quote: %+v", saved)
	}

	// History renders the quote too.
	msgs, err := GetMessages(convID, 10, 0)
	if err != nil {
		t.Fatalf("GetMessages: %v", err)
	}
	if len(msgs) != 2 || msgs[1].ReplyTo != orig.ID || msgs[1].ReplyMessage != "hello there" {
		t.Fatalf("history must render the quote: %+v", msgs)
	}

	// A deleted target still accepts the reply, and the quote shows as deleted.
	if _, e := hub.DeleteMessage(&User{Extension: a}, orig.ID); e != nil {
		t.Fatalf("delete the target: %s", e.msg)
	}
	saved, e = hub.SendMessage(&User{Extension: b}, &InMessage{
		Action: "send_message", ConversationID: convID, MsgType: "text",
		Message: "replying to a deleted message", ReplyTo: orig.ID,
	})
	if e != nil {
		t.Fatalf("replying to a deleted message was refused: %s", e.msg)
	}
	if saved.ReplyTo != orig.ID || !saved.ReplyIsDeleted {
		t.Fatalf("the quote of a deleted message must show as deleted: %+v", saved)
	}
}
