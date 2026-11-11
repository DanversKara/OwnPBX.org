package main

import (
	"encoding/base64"
	"strings"
	"testing"
	"time"
)

func TestCachedTokenCannotOutliveItsSignedExpiry(t *testing.T) {
	token := base64.StdEncoding.EncodeToString([]byte("1:1:" + strings.Repeat("a", 64)))
	storeAuthCache(token, cachedAuth{user: &User{ID: 1}, expiresAt: time.Now().Add(time.Minute)})
	t.Cleanup(func() { authCacheMu.Lock(); delete(authCache, token); authCacheMu.Unlock() })
	if user, err := ValidateBearerToken(token, "test-secret"); err == nil || user != nil {
		t.Fatal("expired token accepted from cache")
	}
}

func TestExpiredAuthCacheEntryIsEvicted(t *testing.T) {
	const token = "expired-cache-test"
	storeAuthCache(token, cachedAuth{user: &User{ID: 1}, expiresAt: time.Now().Add(-time.Second)})
	if _, ok := loadAuthCache(token); ok {
		t.Fatal("expired entry returned")
	}
	authCacheMu.RLock()
	_, remains := authCache[token]
	authCacheMu.RUnlock()
	if remains {
		t.Fatal("expired entry was not evicted")
	}
}

// Device-bound tokens: rejected before any DB lookup when the row id is
// malformed, so these run without a database like the other tests here.
func TestDeviceBoundTokenWithRowZeroIsRejected(t *testing.T) {
	token := base64.StdEncoding.EncodeToString([]byte("1:0:9999999999:" + strings.Repeat("a", 64)))
	if user, err := ValidateBearerToken(token, "test-secret"); err == nil || user != nil {
		t.Fatal("4-part token with row id 0 accepted")
	}
}

func TestDeviceBoundTokenWithBadRowIdIsRejected(t *testing.T) {
	token := base64.StdEncoding.EncodeToString([]byte("1:abc:9999999999:" + strings.Repeat("a", 64)))
	if user, err := ValidateBearerToken(token, "test-secret"); err == nil || user != nil {
		t.Fatal("4-part token with non-numeric row id accepted")
	}
}

func TestMalformedTokenFormatIsRejected(t *testing.T) {
	token := base64.StdEncoding.EncodeToString([]byte("1:2:3:4:5"))
	if user, err := ValidateBearerToken(token, "test-secret"); err == nil || user != nil {
		t.Fatal("5-part token accepted")
	}
}
