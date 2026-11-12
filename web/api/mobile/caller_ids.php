<?php
/**
 * GET  /api/mobile/caller_ids.php: the numbers this user may call out as ("Call from"), default first.
 * POST {"default": "<number>"}:  makes one of them the default ('' = the line's own number).
 * Per call the app sends the chosen number in the SIP header X-OwnPBX-CID; the PBX accepts it
 * only when it is one of these.
 */
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';
require_once __DIR__ . '/../../src/services/CallerIdService.php';

$user = requireMobileAuth();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = mobileInput();
    try {
        $picked = (string) ($in['default'] ?? $_POST['default'] ?? '');
        CallerIdService::setDefault($userId, $picked);
        try {
            require_once __DIR__ . '/../../src/sync/SyncExtensions.php';
            syncAllExtensions();
        } catch (\Throwable $e) {
            markPendingSync('extensions', 'extension', 'caller-id', 'Default caller ID changed', 'update', $userId);
        }
        writeAuditLog(null, 'user_settings', 'phone', 'Default caller ID (app): ' . ($picked !== '' ? $picked : 'line number'), 'update', $userId);
    } catch (\InvalidArgumentException $e) {
        mobileError($e->getMessage(), 400);
    }
}

$choices = CallerIdService::assigned($userId) === [] ? [] : CallerIdService::choices($userId);
mobileJson([
    'success' => true,
    'header' => CallerIdService::HEADER,
    'numbers' => array_map(fn ($c) => ['number' => $c['number'], 'title' => $c['title'], 'default' => $c['default']], $choices),
]);
