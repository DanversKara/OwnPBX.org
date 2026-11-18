#!/usr/bin/env php
<?php
/**
 * Block caller (*86) handler.
 *
 * Triggered from the dialplan when the CALLED party presses *86 during a
 * bridged call (see conf/asterisk/features.conf [applicationmap] and the
 * sub-block-caller Gosub). Adds the CALLER's number to the inbound
 * blacklist so future calls from that number hear "not in service".
 *
 * Usage: block_caller.php <caller_number> <owner_extension>
 *   caller_number    the number to block (digits, may include +)
 *   owner_extension  the extension that pressed *86 (the called party)
 *
 * The block action is always 'announcement' (the caller hears the
 * not-in-service sound). The entry belongs to the owner extension: the
 * caller is stopped on their way to that person only, and still reaches
 * everybody else. The owner sees it under Panel -> Blocked callers; an
 * administrator sees every user's list on the Blacklist page.
 *
 * Runs in the background (dialplan System ... &), so it must be fast and
 * must never block the call. CLI only; never over HTTP.
 */
if (PHP_SAPI !== 'cli') {   // dialplan tool; never over HTTP
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/asterisk_sync.php';

$caller = preg_replace('/[^0-9+]/', '', $argv[1] ?? '');
$owner = preg_replace('/[^0-9]/', '', $argv[2] ?? '');

if ($caller === '' || $owner === '') {
    fwrite(STDERR, "Usage: block_caller.php <caller_number> <owner_extension>\n");
    exit(1);
}

// Normalise to digits-only for the AstDB key (same form as the blacklist
// check in sub-inbound-blacklist, so '+90...' and '90...' cannot collide).
$clean = preg_replace('/[^0-9]/', '', $caller);
if ($clean === '') {
    fwrite(STDERR, "Invalid caller number\n");
    exit(1);
}

$db = getDB();

// An administrator can take call blocking away from a user; then *86 does nothing.
require_once dirname(__DIR__) . '/src/services/InboundBlacklistService.php';
if (!InboundBlacklistService::userMayBlock($owner)) {
    fwrite(STDERR, "Call blocking is switched off for {$owner}\n");
    exit(1);
}

// Already on this person's own list? Do not duplicate; just make sure it is active.
// Another user may have the same number on theirs: the two never touch.
$stmt = $db->prepare("SELECT id FROM pbx_inbound_blacklist WHERE number = ? AND owner_extension = ? LIMIT 1");
$stmt->execute([$clean, $owner]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    $db->prepare("UPDATE pbx_inbound_blacklist SET is_active = 1, block_action = 'announcement', updated_at = NOW() WHERE id = ?")
        ->execute([(int) $existing['id']]);
} else {
    $db->prepare("INSERT INTO pbx_inbound_blacklist (number, title, block_action, owner_extension, is_active) VALUES (?, ?, 'announcement', ?, 1)")
        ->execute([$clean, 'Blocked via *86', $owner]);
}

// Put it in AstDB right away so the block is live for the caller's next call without
// waiting for a dialplan sync: this person's own family, not the PBX-wide one.
AsteriskHelper::execCLI("database put blacklist_u/{$owner} {$clean} 1");
AsteriskHelper::execCLI("database put blacklist_u_action/{$owner} {$clean} announcement");

// Regenerate the inbound dialplan so the portal and the pattern rules
// stay in step (cheap; the AstDB put above already enforces the block).
markPendingSync('inbound_dialplan', 'blacklist', $clean, "Blocked for {$owner} via *86: {$clean}", 'create', null);

fwrite(STDOUT, "Blocked {$clean} for {$owner}\n");
exit(0);
