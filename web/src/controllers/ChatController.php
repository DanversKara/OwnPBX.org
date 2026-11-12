<?php

require_once __DIR__ . '/../../api/mobile/auth_helper.php';
require_once __DIR__ . '/../services/ChatSettingsService.php';

/**
 * Chat page (/chat).
 * Renders templates/views/chat/index.php with assets/js/chat.js.
 * Logic: ChatSettingsService.
 * POST actions: save_chat_settings.
 * Access by role module (getModuleKeyForPage() in auth.php, Admin → Roles).
 */
class ChatController extends BaseController
{
    /**
     * Unread chat messages of an extension, all its conversations together: the same count the
     * chat shows per conversation (chat/db.go GetConversations). The menu shows it next to Chat.
     */
    public static function unreadCount(string $ext): int
    {
        if ($ext === '') {
            return 0;
        }
        try {
            $q = getDB()->prepare("SELECT COUNT(*) FROM chat_participants p
                JOIN chat_conversations c ON c.id = p.conversation_id AND c.is_deleted = 0
                JOIN chat_messages m ON m.conversation_id = p.conversation_id AND m.id > p.last_read_message_id
                    AND m.sender_ext != p.extension AND m.is_deleted = 0
                WHERE p.extension = ? AND p.left_at IS NULL");
            $q->execute([$ext]);
            return (int) $q->fetchColumn();
        } catch (\Throwable $e) {
            return 0; // chat tables not there yet
        }
    }

    public static function index(): void
    {
        requireLogin();
        static::requireModule('chat', 'view');

        $user = getCurrentUser();
        $ext = trim($user['extension'] ?? '');

        if (isset($_GET['badge']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            static::json(['count' => static::unreadCount($ext)]);
        }

        if ($ext === '') {
            static::notifyError(t('chat.err_no_extension'));
            static::redirect('/dashboard');
            return;
        }

        // Chat settings: administrators only.
        $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
        $notices = $isAdmin
            ? static::handlePost([
                'save_chat_settings' => fn() => static::verifyCsrf()
                    ? ChatSettingsService::saveDeleteWindow($_POST['chat_delete_window_minutes'] ?? '')
                    : ['success' => false, 'error' => t('common.invalid_csrf')],
            ])
            : ['message' => '', 'error' => ''];

        $token = generateMobileToken($user, 86400); // valid for 24 hours for the web chat session
        $isSecure = function_exists('isHttpsRequest')
            ? isHttpsRequest()
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443));
        setcookie('chat_token', $token, [
            'expires' => time() + 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isSecure,
        ]);
        $page_title = function_exists('t') ? t('sidebar.item_chat') : 'Sohbet';

        static::renderPage('chat/index', [
            'user' => $user,
            'ext' => $ext,
            'token' => $token,
            'page_title' => $page_title,
            'is_admin' => $isAdmin,
            'delete_window' => ChatSettingsService::deleteWindowMinutes(),
        ], ['title' => $page_title] + $notices);
    }
}
