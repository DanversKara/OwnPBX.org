<?php
/**
 * Fax for the mobile apps (Android / iOS).
 *
 *   GET  ?action=list&type=received&page=&limit=&search=&date_from=&date_to=
 *        → {success, faxes: [...], total_count, total_pages}
 *   GET  ?action=list&type=sent
 *        → {success, faxes: [...]}
 *   GET  ?action=download&id=&kind=received|sent[&view=1]
 *        → the stored PDF (application/pdf; inline when view=1)
 *   GET  ?action=config
 *        → {success, sendable_numbers, cover_mode, cover_company,
 *           cover_notice, cover_logo, templates: [...]}
 *   POST multipart {action: "send", dest_number, compose_mode: "pdf"|"text",
 *        sender_did, cover_enabled, cover_to, cover_subject, cover_message,
 *        template_id} + file part pdf_file (or fax_text_content for text mode)
 *        → {success, fax_id, pages}
 *   POST {"action": "mark_read", "id": ...}
 *        → {success} (no mark-read operation existed in the web UI; new here)
 *   POST {"action": "delete", "id": ..., "kind": "received"|"sent"}
 *        → {success}
 *   POST {"action": "resend", "id": ...}  (FAILED sent faxes only)
 *        → {success, fax_id} (fax_id is the NEW queued row)
 *
 * Authentication is the HMAC Bearer token (requireMobileAuth), exactly like
 * the other mobile endpoints — there is no web session and no CSRF token from
 * the app. The fax services below still demand a CSRF token and read the
 * caller's identity from $_SESSION, so this endpoint mints a one-request
 * token and seeds $_SESSION from the already-authenticated mobile user
 * before calling them (see mobileFaxServiceContext()). That is the mobile
 * equivalent of the web session + CSRF pair, not a bypass of the checks:
 * the services still run their own ownership, sender-number, hourly-limit
 * and dialplan checks.
 *
 * No fax-specific logo upload: the cover logo (when enabled) is the system
 * Appearance logo, reused by FaxCoverService::logoPath().
 */
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/FaxInboxRepository.php';
require_once __DIR__ . '/../../src/repositories/FaxSentRepository.php';
require_once __DIR__ . '/../../src/services/FaxSendService.php';
require_once __DIR__ . '/../../src/services/FaxInboxService.php';
require_once __DIR__ . '/../../src/services/FaxSentService.php';
require_once __DIR__ . '/../../src/services/FaxCoverService.php';
require_once __DIR__ . '/../../src/services/FaxStorage.php';
require_once __DIR__ . '/../../src/services/BucketFiles.php';

$user = requireMobileAuth();
$userId = (int) ($user['id'] ?? 0);
$userExt = preg_replace('/[^0-9]/', '', (string) ($user['extension'] ?? ''));
$userRole = (string) ($user['role'] ?? 'user');
$isAdmin = $userRole === 'admin';

/**
 * Seeds the $_SESSION identity the fax services read (user_id / user_role,
 * used for the sender-number choice, the admin hourly-limit exemption and
 * the audit log) and mints a one-request CSRF token for the service call.
 * config.php already started the session; the guard is only for safety.
 *
 * @return string the minted CSRF token to pass to the service
 */
function mobileFaxServiceContext(int $userId, string $role): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = $role;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf_token'];
}

/** Truthy values as the app may send them: true, 1, "1", "true", "yes", "on". */
function mobileFaxBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/** A YYYY-MM-DD date, or '' when the value is empty; null when malformed. */
function mobileFaxDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $value;
}

/**
 * The fax id out of a service message.
 *
 * FaxSendService returns the id only inside a translated message
 * (srv_fax.queued: "Fax queued for sending! (Job ID: #12, pages: 3)").
 * The "#<digits>" fragment is present in every shipped translation
 * (checked en/tr/de/fr/ru), so "#12" parses in any app language — but a
 * future translation could drop it, so a failed parse is NOT fatal: the
 * caller falls back to mobileFaxFindQueued(). The pages figure is NOT
 * parsed from the message (its label is translated); it is read from the
 * fax_sent row instead.
 */
function mobileFaxIdFromMessage(?string $message): ?int
{
    if ($message === null || $message === '') {
        return null;
    }
    return preg_match('/#(\d+)/', $message, $m) ? (int) $m[1] : null;
}

/**
 * Fallback for a message that carried no "#id": the newest PENDING row this
 * user queued for this destination in the last 5 minutes. The send/resend
 * inserts the row (PENDING) immediately before spooling the call file, so
 * the row the service just made is the newest one — unless two faxes to the
 * same number were queued inside the same window, in which case this can
 * return the other one's id. That is why the message parse above is tried
 * first and this is only the fallback.
 */
function mobileFaxFindQueued(int $userId, string $destNumber): ?array
{
    $stmt = getDB()->prepare(
        "SELECT id, pages FROM fax_sent WHERE user_id = ? AND destination_number = ?"
        . " AND status = 'PENDING' AND created_at > (NOW() - INTERVAL 5 MINUTE)"
        . " ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$userId, $destNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** The queued row (id + pages) for a fax id the message parse produced. */
function mobileFaxQueuedRow(int $faxId, int $userId): ?array
{
    $stmt = getDB()->prepare('SELECT id, pages FROM fax_sent WHERE id = ? AND user_id = ?');
    $stmt->execute([$faxId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Turns a service result (['success' => bool, 'message' => ?, 'error' => ?])
 * into the mobile answer. Service errors are already translated strings;
 * they go out verbatim as {"success": false, "error": ...}.
 */
function mobileFaxServiceError(array $result): never
{
    $error = (string) ($result['error'] ?? '');
    if ($error === '') {
        $error = t('srv_fax.err_to');
    }
    // err_access_nf ("cannot access or not found") vs err_access ("cannot
    // access"): keep the distinction the web UI makes.
    $status = 400;
    if ($error === t('srv_fax.err_access_nf')) {
        $status = 404;
    } elseif ($error === t('srv_fax.err_access')) {
        $status = 403;
    }
    mobileError($error, $status);
}

/* ------------------------------------------------------------------ POST */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // JSON actions (mark_read / delete / resend) arrive as a JSON body;
    // "send" arrives as multipart/form-data, for which php://input is not
    // JSON — then $_POST holds the fields and $_FILES the PDF.
    $in = mobileInput();
    if ($in === [] && $_POST !== []) {
        $in = $_POST;
    }
    $action = (string) ($in['action'] ?? '');

    switch ($action) {
        case 'send': {
            $destNumber = preg_replace('/[^0-9]/', '', trim((string) ($in['dest_number'] ?? '')));
            if ($destNumber === '') {
                mobileError(t('srv_fax.err_to'), 400);
            }
            $composeMode = ((string) ($in['compose_mode'] ?? 'pdf') === 'text') ? 'text' : 'pdf';
            $templateId = (int) ($in['template_id'] ?? 0);

            // The service reads $post['cover_template']; the app sends
            // template_id. FaxCoverService::settingsFor() only honours a
            // template that belongs to the sender (admins excepted) and
            // otherwise falls back to the organisation settings.
            $post = [
                'dest_number' => $destNumber,
                'compose_mode' => $composeMode,
                'sender_did' => preg_replace('/[^0-9]/', '', trim((string) ($in['sender_did'] ?? ''))),
                'fax_text_content' => (string) ($in['fax_text_content'] ?? ''),
                // A literal "false" string is truthy in PHP, so normalise
                // before FaxCoverService::wanted() sees it.
                'cover_enabled' => mobileFaxBool($in['cover_enabled'] ?? null) ? '1' : '',
                'cover_to' => (string) ($in['cover_to'] ?? ''),
                'cover_subject' => (string) ($in['cover_subject'] ?? ''),
                'cover_message' => (string) ($in['cover_message'] ?? ''),
                'cover_template' => $templateId > 0 ? (string) $templateId : '',
                'csrf_token' => mobileFaxServiceContext($userId, $userRole),
            ];
            $result = FaxSendService::sendFax($post, $_FILES, $userId, $userExt);
            if (empty($result['success'])) {
                mobileFaxServiceError($result);
            }

            $faxId = mobileFaxIdFromMessage($result['message'] ?? null);
            $row = $faxId !== null
                ? mobileFaxQueuedRow($faxId, $userId)
                : mobileFaxFindQueued($userId, $destNumber);
            mobileJson([
                'success' => true,
                // null only when BOTH the message parse and the DB fallback
                // failed; the fax itself is still queued in that case.
                'fax_id' => $row !== null ? (int) $row['id'] : $faxId,
                'pages' => $row !== null ? (int) $row['pages'] : null,
            ]);
        }

        case 'mark_read': {
            // New logic — FaxInboxService is delete-only, there is no
            // mark-read operation to reuse. Ownership is checked BEFORE the
            // update, the same way deleteFax() checks it.
            $faxId = (int) ($in['id'] ?? 0);
            if ($faxId <= 0) {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            $stmt = getDB()->prepare('SELECT did_extension FROM fax_received WHERE id = ?');
            $stmt->execute([$faxId]);
            $fax = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fax === false) {
                mobileError(t('srv_fax.err_access_nf'), 404);
            }
            if (!$isAdmin) {
                $allowedDids = FaxInboxRepository::getAllowedDIDs($userId, $userExt);
                if (!in_array((string) $fax['did_extension'], $allowedDids, true)) {
                    mobileError(t('srv_fax.err_access'), 403);
                }
            }
            getDB()->prepare('UPDATE fax_received SET is_read = 1 WHERE id = ?')->execute([$faxId]);
            mobileJson(['success' => true, 'id' => $faxId]);
        }

        case 'delete': {
            $faxId = (int) ($in['id'] ?? 0);
            if ($faxId <= 0) {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            $kind = (string) ($in['kind'] ?? 'received');
            if (!in_array($kind, ['received', 'sent'], true)) {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            $csrf = mobileFaxServiceContext($userId, $userRole);
            $result = $kind === 'sent'
                ? FaxSentService::deleteSentFax($faxId, $csrf, $userRole, $userId)
                : FaxInboxService::deleteFax($faxId, $csrf, $userRole, $userExt, $userId);
            if (empty($result['success'])) {
                mobileFaxServiceError($result);
            }
            mobileJson(['success' => true, 'id' => $faxId]);
        }

        case 'resend': {
            // FAILED rows only — FaxSentService::resendFax() enforces that
            // and opens a NEW fax_sent row (the original stays as history).
            $faxId = (int) ($in['id'] ?? 0);
            if ($faxId <= 0) {
                mobileError(t('mobile_api.invalid_request'), 400);
            }
            $csrf = mobileFaxServiceContext($userId, $userRole);
            $result = FaxSentService::resendFax($faxId, $csrf, $userRole, $userId);
            if (empty($result['success'])) {
                mobileFaxServiceError($result);
            }
            $newFaxId = mobileFaxIdFromMessage($result['message'] ?? null);
            if ($newFaxId === null) {
                $stmt = getDB()->prepare(
                    "SELECT id FROM fax_sent WHERE user_id = ? AND status = 'PENDING'"
                    . " AND created_at > (NOW() - INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 1"
                );
                $stmt->execute([$userId]);
                $newFaxId = ($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false ? (int) $row['id'] : null;
            }
            mobileJson(['success' => true, 'fax_id' => $newFaxId, 'original_id' => $faxId]);
        }
    }
    mobileError(t('mobile_api.invalid_request'), 400);
}

/* ------------------------------------------------------------------- GET */

$action = (string) ($_GET['action'] ?? 'list');

if ($action === 'download') {
    $faxId = (int) ($_GET['id'] ?? 0);
    if ($faxId <= 0) {
        mobileError(t('mobile_api.invalid_request'), 400);
    }
    $kind = (string) ($_GET['kind'] ?? 'received');
    if (!in_array($kind, ['received', 'sent'], true)) {
        mobileError(t('mobile_api.invalid_request'), 400);
    }
    $isView = !empty($_GET['view']); // empty() is also true for "0"

    $db = getDB();
    if ($kind === 'sent') {
        $stmt = $db->prepare('SELECT id, user_id, pdf_path FROM fax_sent WHERE id = ?');
        $stmt->execute([$faxId]);
        $fax = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fax === false) {
            mobileError(t('api.err_fax_not_found'), 404);
        }
        if (!$isAdmin && (int) $fax['user_id'] !== $userId) {
            mobileError(t('api.err_unauthorized'), 403);
        }
    } else {
        $stmt = $db->prepare('SELECT id, did_extension, pdf_path FROM fax_received WHERE id = ?');
        $stmt->execute([$faxId]);
        $fax = $stmt->fetch(PDO::FETCH_ASSOC);
        // A fax that failed before its first page is recorded without a file.
        if ($fax === false || (string) ($fax['pdf_path'] ?? '') === '') {
            mobileError(t('api.err_fax_not_found'), 404);
        }
        if (!$isAdmin) {
            $allowedDids = FaxInboxRepository::getAllowedDIDs($userId, $userExt);
            if (!in_array((string) $fax['did_extension'], $allowedDids, true)) {
                mobileError(t('api.err_unauthorized'), 403);
            }
        }
        // The web download (/api/fax_download.php) marks a received fax read.
        $db->prepare('UPDATE fax_received SET is_read = 1 WHERE id = ?')->execute([$faxId]);
    }

    $pdfPath = (string) ($fax['pdf_path'] ?? '');
    if ($pdfPath === '') {
        mobileError(t('api.err_fax_not_found'), 404);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $disposition = ($isView ? 'inline' : 'attachment') . '; filename="fax_' . $faxId . '.pdf"';

    // In the S3 bucket (#69): streamed through the chat service, after the
    // checks above — same as /api/fax_download.php.
    if (BucketFiles::isRemote($pdfPath)) {
        if (!BucketFiles::passthru(BucketFiles::key($pdfPath), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ])) {
            mobileError(t('api.err_fax_file'), 404);
        }
        exit;
    }

    $filePath = realpath($pdfPath);
    $allowedDir = realpath(FAX_STORAGE_PATH);
    // Trailing '/': a plain prefix comparison would also accept sibling
    // directories such as "/var/www/faxes2/...".
    if (!$filePath || !$allowedDir || strpos($filePath, rtrim($allowedDir, '/') . '/') !== 0 || !is_file($filePath)) {
        mobileError(t('api.err_fax_file'), 404);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, no-store');
    readfile($filePath);
    exit;
}

if ($action === 'config') {
    $cover = FaxCoverService::settings();
    $templates = [];
    foreach (FaxCoverService::templatesFor($userId) as $tpl) {
        $templates[] = [
            'id' => (int) $tpl['id'],
            'name' => (string) $tpl['name'],
            'company' => (string) $tpl['company'],
            'address' => (string) $tpl['address'],
            'phone' => (string) $tpl['phone'],
            'email' => (string) $tpl['email'],
            'website' => (string) $tpl['website'],
            'notice' => (string) $tpl['notice'],
            'logo' => (int) $tpl['logo'] === 1,
        ];
    }
    mobileJson([
        'success' => true,
        'sendable_numbers' => FaxInboxRepository::sendableNumbers($userId),
        'cover_mode' => $cover['mode'],
        'cover_company' => $cover['company'],
        'cover_address' => $cover['address'],
        'cover_phone' => $cover['phone'],
        'cover_email' => $cover['email'],
        'cover_website' => $cover['website'],
        'cover_notice' => $cover['notice'],
        'cover_logo' => (bool) $cover['logo'],
        'templates' => $templates,
    ]);
}

if ($action === 'list') {
    $type = (string) ($_GET['type'] ?? 'received');
    if (!in_array($type, ['received', 'sent'], true)) {
        mobileError(t('mobile_api.invalid_request'), 400);
    }

    if ($type === 'sent') {
        $faxes = [];
        foreach (FaxSentRepository::listForUser($userRole, $userId) as $row) {
            $faxes[] = [
                'id' => (int) $row['id'],
                'sender_extension' => (string) $row['sender_extension'],
                'destination_number' => (string) $row['destination_number'],
                'pages' => (int) $row['pages'],
                'status' => (string) $row['status'],
                'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
                'created_at' => (string) $row['created_at'],
                'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            ];
        }
        mobileJson(['success' => true, 'faxes' => $faxes]);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(max((int) ($_GET['limit'] ?? 50), 1), 50);
    $search = trim((string) ($_GET['search'] ?? ''));
    $dateFrom = mobileFaxDate((string) ($_GET['date_from'] ?? ''));
    $dateTo = mobileFaxDate((string) ($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null) {
        mobileError(t('mobile_api.invalid_request'), 400);
    }
    $result = FaxInboxRepository::search(
        $userRole, $userExt, $search, $dateFrom, $dateTo, $limit, ($page - 1) * $limit, $userId
    );
    $faxes = [];
    foreach ($result['faxes'] as $row) {
        $faxes[] = [
            'id' => (int) $row['id'],
            'did_extension' => (string) $row['did_extension'],
            'caller_id' => (string) ($row['caller_id'] ?? ''),
            'pages' => (int) $row['pages'],
            'file_size' => (int) ($row['file_size'] ?? 0),
            'status' => (string) $row['status'],
            'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'is_read' => (int) $row['is_read'] === 1,
            'received_at' => (string) $row['received_at'],
        ];
    }
    mobileJson([
        'success' => true,
        'faxes' => $faxes,
        'total_count' => (int) $result['total_count'],
        'total_pages' => (int) $result['total_pages'],
    ]);
}

mobileError(t('mobile_api.invalid_request'), 400);
