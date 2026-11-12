package main

import (
	"encoding/json"
	"net/http"
)

// Deleting a conversation: per-user, like hiding a thread. The conversation
// row and its messages stay, the other participants keep their copy, and a
// new message revives the thread for everyone (see SaveMessage). Nothing is
// broadcast: the other side has nothing to learn — their list is unchanged.
// This is deliberately not the SMS delete_thread behaviour, which
// hard-deletes the shared thread's messages for everyone on the number.

// DeleteConversationForUser hides convID from ext's Chat list. Deleting twice
// is a success: the thread is already gone from your list. A conversation
// that does not exist and one you are not a participant of answer the same
// way (404), so probing ids cannot tell whether a conversation exists.
func DeleteConversationForUser(convID int, ext string) *sendError {
	if convID <= 0 || ext == "" {
		return &sendError{http.StatusBadRequest, "Invalid conversation_id."}
	}
	if db == nil {
		return &sendError{http.StatusServiceUnavailable, "Database unavailable."}
	}

	// The same participant check as reading and sending: a member who left a
	// group (or a deleted group) can no longer change its history.
	isPart, err := IsParticipant(convID, ext)
	if err != nil {
		return &sendError{http.StatusInternalServerError, "The conversation could not be deleted."}
	}
	if !isPart {
		return &sendError{http.StatusNotFound, "Conversation not found."}
	}

	_, err = db.Exec(`
		INSERT INTO chat_conversation_deletions (conversation_id, user_ext, deleted_at)
		VALUES (?, ?, NOW())
		ON DUPLICATE KEY UPDATE deleted_at = NOW()
	`, convID, ext)
	if err != nil {
		return &sendError{http.StatusInternalServerError, "The conversation could not be deleted."}
	}
	return nil
}

// DeleteConversation is the one delete path behind POST /api/conversations/delete.
func (h *Hub) DeleteConversation(user *User, convID int) (map[string]interface{}, *sendError) {
	if e := DeleteConversationForUser(convID, user.Extension); e != nil {
		return nil, e
	}
	return map[string]interface{}{
		"conversation_id": convID,
	}, nil
}

// POST /chat/api/conversations/delete {"conversation_id": 123}
func (s *Server) HandleDeleteConversation(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int `json:"conversation_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid conversation_id.")
		return
	}

	data, e := s.hub.DeleteConversation(user, body.ConversationID)
	if e != nil {
		writeJSONError(w, e.status, e.msg)
		return
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data":    data,
	})
}
