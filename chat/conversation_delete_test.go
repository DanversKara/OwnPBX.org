package main

import (
	"bytes"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// Deleting a whole conversation in the internal chat: it hides the thread
// from the deleting user's list only. The row and its messages stay, the
// other participant keeps their copy, and a new message revives the thread
// for the deleter.
func TestDeleteConversationEndToEnd(t *testing.T) {
	a, b := testExtensions(t)

	res, err := db.Exec(`INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
		VALUES ('direct', ?, ?, NOW(), NOW(), 0)`, fmt.Sprintf("test-delconv-%d", time.Now().UnixNano()), a)
	if err != nil {
		t.Fatalf("create test conversation: %v", err)
	}
	id64, _ := res.LastInsertId()
	convID := int(id64)
	defer removeTestConversation(convID)
	defer func() {
		_, _ = db.Exec("DELETE FROM chat_conversation_deletions WHERE conversation_id = ?", convID)
	}()
	if _, err := db.Exec(`INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
		VALUES (?, ?, 'member', NOW()), (?, ?, 'member', NOW())`, convID, a, convID, b); err != nil {
		t.Fatalf("add participants: %v", err)
	}
	if _, err := SaveMessage(convID, a, "text", "hello", "", "", 0, "", 0); err != nil {
		t.Fatalf("SaveMessage: %v", err)
	}

	visible := func(ext string) bool {
		convs, err := GetConversations(ext)
		if err != nil {
			t.Fatalf("GetConversations(%s): %v", ext, err)
		}
		for _, c := range convs {
			if c.ID == convID {
				return true
			}
		}
		return false
	}
	if !visible(a) || !visible(b) {
		t.Fatal("both participants must see the conversation before the delete")
	}

	hub := NewHub()
	hub.uploadDir = t.TempDir()

	// A non-participant learns nothing: same answer as a missing conversation.
	// (Skipped when the test database has no third user to probe with.)
	var outsider string
	_ = db.QueryRow("SELECT extension FROM sys_users WHERE is_active = 1 AND extension IS NOT NULL AND extension != '' AND role != 'fax_user' AND extension NOT IN (?, ?) ORDER BY id ASC LIMIT 1", a, b).Scan(&outsider)
	if outsider != "" {
		if _, e := hub.DeleteConversation(&User{Extension: outsider}, convID); e == nil || e.status != http.StatusNotFound {
			t.Fatalf("a non-participant must get 404, got %v", e)
		}
	}

	// The deleter's list hides the thread; the other participant keeps it.
	if _, e := hub.DeleteConversation(&User{Extension: a}, convID); e != nil {
		t.Fatalf("the delete was refused: %s", e.msg)
	}
	if visible(a) {
		t.Fatal("the conversation must be hidden from the deleter's list")
	}
	if !visible(b) {
		t.Fatal("the other participant must still see the conversation")
	}

	// Nothing was destroyed: the row and its message are still there.
	var msgCount, convDeleted int
	_ = db.QueryRow("SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ?", convID).Scan(&msgCount)
	_ = db.QueryRow("SELECT is_deleted FROM chat_conversations WHERE id = ?", convID).Scan(&convDeleted)
	if msgCount != 1 || convDeleted != 0 {
		t.Fatalf("delete must not touch messages or the row: messages=%d is_deleted=%d", msgCount, convDeleted)
	}

	// Deleting twice is a success: the thread is already gone from your list.
	if _, e := hub.DeleteConversation(&User{Extension: a}, convID); e != nil {
		t.Fatalf("a second delete must succeed: %s", e.msg)
	}

	// A new message revives the thread for the deleter — and the message
	// itself still lands.
	if _, e := hub.SendMessage(&User{Extension: b}, &InMessage{
		Action: "send_message", ConversationID: convID, MsgType: "text", Message: "are you there?",
	}); e != nil {
		t.Fatalf("the reviving message was refused: %s", e.msg)
	}
	if !visible(a) {
		t.Fatal("a new message must revive the conversation for the deleter")
	}
	var markers int
	_ = db.QueryRow("SELECT COUNT(*) FROM chat_conversation_deletions WHERE conversation_id = ?", convID).Scan(&markers)
	if markers != 0 {
		t.Fatalf("the send must clear the deletion markers, %d left", markers)
	}

	// The other participant can delete their copy independently.
	if _, e := hub.DeleteConversation(&User{Extension: b}, convID); e != nil {
		t.Fatalf("the other participant's delete was refused: %s", e.msg)
	}
	if visible(b) {
		t.Fatal("the conversation must be hidden from the other participant's list too")
	}
	if !visible(a) {
		t.Fatal("one side deleting must not hide the thread from the other side")
	}
}

func TestHandleDeleteConversationRejectsBadRequests(t *testing.T) {
	srv := &Server{hub: NewHub()}
	user := &User{ID: 1, Extension: "19000", FullName: "Test"}

	req := httptest.NewRequest(http.MethodGet, "/api/conversations/delete", nil)
	rr := httptest.NewRecorder()
	srv.HandleDeleteConversation(rr, req, user)
	if rr.Code != http.StatusMethodNotAllowed {
		t.Fatalf("GET: got HTTP %d", rr.Code)
	}

	for _, body := range []string{`{}`, `{"conversation_id":0}`, `{"conversation_id":-4}`, `not json`} {
		req := httptest.NewRequest(http.MethodPost, "/api/conversations/delete", bytes.NewBufferString(body))
		rr := httptest.NewRecorder()
		srv.HandleDeleteConversation(rr, req, user)
		if rr.Code != http.StatusBadRequest {
			t.Fatalf("body %s: got HTTP %d, want 400", body, rr.Code)
		}
	}
}
