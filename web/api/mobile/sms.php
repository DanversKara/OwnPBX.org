<?php
/**
 * SMS / MMS for the mobile apps (Android / iOS).
 *
 *   GET  ?action=config
 *        → {success, numbers: [{id, number, label, provider}]}
 *   GET  ?action=threads
 *        → {success, threads: [{number_id, number, peer, name, body,
 *           direction, created_at, unread}]}
 *   GET  ?action=thread&number_id=&peer=
 *        → {success, messages: [{id, peer, direction, body, status, error,
 *           created_at, is_read, media: [{id, content_type, name, url}]}]}
 *        (opening a thread marks its inbound messages read, like the portal)
 *   GET  ?action=poll
 *        → {success, last} (newest message id of the user's numbers)
 *   POST multipart {action: "send", number_id, peer, text} + file parts media[]
 *        → {success, message_id}
 *   POST {"action": "contact", "number_id": ..., "peer": ..., "name": ...}
 *        → {success} (names a peer for everyone on that number)
 *   POST {"action": "delete", "number_id": ..., "message_id": ...} → {success}
 *   POST {"action": "delete_thread", "number_id": ..., "peer": ...} → {success, deleted}
 *        (removes messages from the PBX only; a text the peer already has is not unsent)
 *   GET  ?action=gifs&q= → {success, gifs: [{id, preview, url}]} (GIPHY; config says gif_search)
 *   "send" also takes reply_to (message id it answers) and gif_url (a GIF from ?action=gifs).
 *
 * Authentication is the HMAC Bearer <redacted> (requireMobileAuth), exactly like
 * the other mobile endpoints — there is no web session and no CSRF token from
 * the app.
 *
 * This endpoint reuses the SMS community plugin's Sms class
 * (plugins/community/sms, lib/Sms.php) for every read and write: the numbers
 * the user may use, the conversations, the send path (provider + storage),
 * and the contact names. It does not re-implement the provider calls or the
 * message storage. The plugin must be installed and enabled; when it is not,
 * every action answers 503.
 *
 * MMS: pictures go through the same Sms::send() path as the portal, so an
 * MMS from the app behaves exactly like one from the portal (including the
 * provider's own media validation — VoIP.ms rejects some pictures as
 * "invalid_media"; that is the provider, not this endpoint).
 */
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/core/PluginManager.php';

$user = requireMobileAuth();
$userId = (int) ($user['id'] ?? 0);
$userRole = (string) ($user['role'] ?? 'user');

/**
 * Loads the SMS plugin's Sms class (and its settings) or answers 503.
 * The plugin lives in the plugins directory PluginManager discovers; the
 * class is not in the core autoloader.
 *
 * @return array{settings: array<string, mixed>}
 */
function mobileSmsPlugin(): array
{
    $plugin = PluginManager::getPlugin('sms');
    $manifests = PluginManager::discoverManifests();
    $dir = (string) ($manifests['sms']['dir'] ?? '');
    if ($plugin === null || empty($plugin['enabled']) || $dir === '' || !is_file($dir . '/lib/Sms.php')) {
        mobileError(t('mobile_sms.err_plugin'), 503);
    }
    require_once $dir . '/lib/Sms.php';
    if (!class_exists('Sms')) {
        mobileError(t('mobile_sms.err_plugin'), 503);
    }
    return ['settings' => PluginManager::getAllSettings('sms', false)];
}

/** The user's numbers as id => row (Sms::numbersFor: given to them; admins read all). */
function mobileSmsNumbers(int $userId, string $userRole): array
{
    $out = [];
    foreach (Sms::numbersFor(['id' => $userId, 'role' => $userRole]) as $n) {
        $out[(int) $n['id']] = $n;
    }
    return $out;
}

/** Truthy values as the app may send them: true, 1, "1", "true", "yes", "on". */
function mobileSmsBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/* ------------------------------------------------------------------ GET */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string) ($_GET['action'] ?? '');
    $plugin = mobileSmsPlugin();

    switch ($action) {
        case 'config': {
            $numbers = [];
            foreach (mobileSmsNumbers($userId, $userRole) as $n) {
                $numbers[] = [
                    'id' => (int) $n['id'],
                    'number' => (string) ($n['number'] ?? ''),
                    'label' => (string) ($n['label'] ?? ''),
                    'provider' => (string) ($n['provider'] ?? ''),
                ];
            }
            mobileJson(['success' => true, 'numbers' => $numbers, 'gif_search' => Sms::gifSearchEnabled($plugin['settings'])]);
        }

        case 'threads': {
            $numbers = mobileSmsNumbers($userId, $userRole);
            $ids = array_keys($numbers);
            $threads = [];
            foreach (Sms::threads($ids) as $t) {
                $nid = (int) $t['number_id'];
                $contacts = Sms::contacts($nid);
                $threads[] = [
                    'number_id' => $nid,
                    'number' => (string) ($numbers[$nid]['number'] ?? ''),
                    'peer' => (string) ($t['peer'] ?? ''),
                    'name' => (string) ($contacts[(string) ($t['peer'] ?? '')] ?? ''),
                    'body' => (string) ($t['body'] ?? ''),
                    'direction' => (string) ($t['direction'] ?? ''),
                    'created_at' => (string) ($t['created_at'] ?? ''),
                    'unread' => (int) ($t['unread'] ?? 0),
                ];
            }
            mobileJson(['success' => true, 'threads' => $threads]);
        }

        case 'thread': {
            $numberId = (int) ($_GET['number_id'] ?? 0);
            $peer = trim((string) ($_GET['peer'] ?? ''));
            $numbers = mobileSmsNumbers($userId, $userRole);
            if ($numberId <= 0 || $peer === '' || !isset($numbers[$numberId])) {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            $messages = [];
            foreach (Sms::thread($numberId, $peer) as $m) {
                $media = [];
                foreach ((array) ($m['media'] ?? []) as $md) {
                    $media[] = [
                        'id' => (int) ($md['id'] ?? 0),
                        'content_type' => (string) ($md['content_type'] ?? ''),
                        'name' => (string) ($md['name'] ?? ''),
                        'url' => Sms::mediaUrl($md),
                    ];
                }
                $messages[] = [
                    'id' => (int) ($m['id'] ?? 0),
                    'peer' => (string) ($m['peer'] ?? ''),
                    'direction' => (string) ($m['direction'] ?? ''),
                    'body' => (string) ($m['body'] ?? ''),
                    'status' => (string) ($m['status'] ?? ''),
                    'error' => (string) ($m['error'] ?? ''),
                    'created_at' => (string) ($m['created_at'] ?? ''),
                    'is_read' => (int) ($m['is_read'] ?? 0) === 1,
                    'media' => $media,
                    'reply_to' => $m['reply_to'] ?? null,
                ];
            }
            mobileJson(['success' => true, 'messages' => $messages]);
        }

        case 'poll': {
            $numbers = mobileSmsNumbers($userId, $userRole);
            mobileJson(['success' => true, 'last' => Sms::lastId(array_keys($numbers))]);
        }

        case 'gifs': {
            try {
                mobileJson(['success' => true, 'gifs' => Sms::gifSearch($plugin['settings'], (string) ($_GET['q'] ?? ''))]);
            } catch (\Throwable $e) {
                mobileError($e->getMessage(), 400);
            }
        }

        default:
            mobileError(t('mobile_api.invalid_request'), 400);
    }
}

/* ------------------------------------------------------------------ POST */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // JSON actions (contact) arrive as a JSON body; "send" arrives as
    // multipart/form-data, for which php://input is not JSON — then $_POST
    // holds the fields and $_FILES the pictures.
    $in = mobileInput();
    if ($in === [] && $_POST !== []) {
        $in = $_POST;
    }
    $action = (string) ($in['action'] ?? '');
    $plugin = mobileSmsPlugin();
    $settings = $plugin['settings'];

    switch ($action) {
        case 'send': {
            $numberId = (int) ($in['number_id'] ?? 0);
            $peer = trim((string) ($in['peer'] ?? ''));
            $text = (string) ($in['text'] ?? '');
            $numbers = mobileSmsNumbers($userId, $userRole);
            $number = $numbers[$numberId] ?? null;
            if ($number === null || $peer === '') {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            // Pictures: $_FILES['media'] as an array of uploads (name,
            // tmp_name, size) — the shape Sms::send() expects.
            $files = [];
            $up = $_FILES['media'] ?? null;
            foreach (is_array($up['name'] ?? null) ? array_keys($up['name']) : [] as $i) {
                if ((int) $up['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ((int) $up['error'][$i] !== UPLOAD_ERR_OK) {
                    mobileError(t('mobile_sms.err_media_upload'), 400);
                }
                $files[] = ['name' => $up['name'][$i], 'tmp_name' => $up['tmp_name'][$i], 'size' => $up['size'][$i]];
            }
            try {
                $extra = [];
                $gifUrl = trim((string) ($in['gif_url'] ?? ''));
                if ($gifUrl !== '') {
                    $extra[] = Sms::fetchGif($gifUrl);
                }
                $messageId = Sms::send($number, $peer, $text, $files, $userId, $settings, (int) ($in['reply_to'] ?? 0), $extra);
            } catch (\Throwable $e) {
                mobileError($e->getMessage(), 400);
            }
            mobileJson(['success' => true, 'message_id' => $messageId]);
        }

        case 'delete': {
            $numbers = mobileSmsNumbers($userId, $userRole);
            $numberId = (int) ($in['number_id'] ?? 0);
            if (!isset($numbers[$numberId]) || !Sms::deleteMessage($numberId, (int) ($in['message_id'] ?? 0))) {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            mobileJson(['success' => true]);
        }

        case 'delete_thread': {
            $numbers = mobileSmsNumbers($userId, $userRole);
            $numberId = (int) ($in['number_id'] ?? 0);
            $peer = SmsProvider::e164(trim((string) ($in['peer'] ?? '')));
            if (!isset($numbers[$numberId]) || $peer === '') {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            mobileJson(['success' => true, 'deleted' => Sms::deleteThread($numberId, $peer)]);
        }

        case 'contact': {
            $numberId = (int) ($in['number_id'] ?? 0);
            $peer = trim((string) ($in['peer'] ?? ''));
            $name = trim((string) ($in['name'] ?? ''));
            $numbers = mobileSmsNumbers($userId, $userRole);
            if ($numberId <= 0 || $peer === '' || !isset($numbers[$numberId])) {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            try {
                Sms::saveContact($numberId, $peer, $name);
            } catch (\Throwable $e) {
                mobileError($e->getMessage(), 400);
            }
            mobileJson(['success' => true]);
        }

        default:
            mobileError(t('mobile_api.invalid_request'), 400);
    }
}

mobileError(t('mobile_api.invalid_request'), 400);
