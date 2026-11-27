<?php
require_once __DIR__ . '/../services/AdminPinService.php';
require_once __DIR__ . '/../services/VaultSealService.php';

/**
 * Sudo-mode PIN (/admin-pin): an already signed-in admin re-enters the PIN
 * before an admin-only settings page, good for 15 minutes. requireAdminPin()
 * in auth.php sends the admin here with ?next=<the page they wanted>.
 *
 * Wrong PINs count toward the same 3-strikes counter as the login step:
 * the 3rd wrong PIN seals the OpenBao vault and destroys the session.
 * Logic: AdminPinService.
 */
class AdminPinController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $row = $userId > 0 ? AdminPinService::loadPinRow($userId) : null;
        if (!$row || (int) ($row['is_active'] ?? 0) !== 1 || ($row['role'] ?? '') !== 'admin') {
            static::redirect('/');
            return;
        }

        // An admin signed in before this feature (or with the PIN removed)
        // has no PIN hash: send them through setup, then back here.
        if (empty($row['admin_pin_hash'])) {
            $_SESSION['pending_pin_setup_user_id'] = $userId;
            $_SESSION['pending_pin_setup_next'] = self::safeNext($_GET['next'] ?? '');
            static::redirect('/admin-pin-setup');
            return;
        }

        $next = self::safeNext($_GET['next'] ?? ($_POST['next'] ?? ''));
        $error = '';
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (static::isPost()) {
            $action = $_POST['action'] ?? 'verify';
            if ($action === 'cancel') {
                static::redirect($next !== '' ? $next : '/dashboard');
                return;
            }
            $csrf = $_POST['csrf_token'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                $error = t('login.csrf_error');
            } else {
                $pin = trim($_POST['admin_pin'] ?? '');
                if (!AdminPinService::isValidPinFormat($pin)) {
                    $error = t('admin_pin.enter_valid_pin');
                } else {
                    $res = AdminPinService::verifyPin($userId, $pin, $clientIp);
                    if (!empty($res['ok'])) {
                        AdminPinService::markSudoFresh();
                        static::redirect($next !== '' ? $next : '/dashboard');
                        return;
                    }
                    if (!empty($res['sealed']) || !empty($res['locked'])) {
                        // 3rd wrong PIN (or already locked): the vault is
                        // sealed (or the seal call failed — still locked
                        // out, fail closed). Destroy the session. notify()
                        // starts a fresh session for the message, so it
                        // survives the redirect to /login.
                        $_SESSION = [];
                        session_destroy();
                        notify(t('admin_pin.sealed'), 'danger');
                        static::redirect('/login');
                        return;
                    }
                    if (!empty($res['needs_setup'])) {
                        $_SESSION['pending_pin_setup_user_id'] = $userId;
                        $_SESSION['pending_pin_setup_next'] = $next;
                        static::redirect('/admin-pin-setup');
                        return;
                    }
                    $error = t('admin_pin.invalid_pin');
                    $remaining = AdminPinService::MAX_FAILURES - (int) ($res['failures'] ?? 0);
                    if ($remaining === 2) {
                        $error .= ' ' . t('admin_pin.attempts_remaining_2');
                    } elseif ($remaining === 1) {
                        $error .= ' ' . t('admin_pin.attempts_remaining_1');
                    }
                }
            }
        }

        $csrf_token = getCSRFToken();
        $site_title = getSystemSetting('site_title', 'OwnPBX');
        $brand_title = getSystemSetting('brand_title', 'OwnPBX');
        $brand_sub = getSystemSetting('brand_sub', 'PBX & Call Center');
        $site_logo_type = getSystemSetting('site_logo_type', 'image');
        $site_logo_icon = getSystemSetting('site_logo_icon', 'fa-network-wired');
        $site_logo_image = getSystemSetting('site_logo_image', BRAND_DEFAULT_LOGO_URL);
        $site_logo_image_dark = getSystemSetting('site_logo_image_dark', '');
        $site_logo_wide = $site_logo_type === 'image' && !empty($site_logo_image) && getSystemSetting('site_logo_style', 'square') === 'wide';
        $site_favicon_url = getSystemSetting('site_favicon_url', '');

        static::renderAuthPage('login/admin_pin', [
            'error' => $error,
            'user' => $row,
            'csrf_token' => $csrf_token,
            'action' => '/admin-pin',
            'next' => $next,
            'site_title' => $site_title,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
            'site_logo_type' => $site_logo_type,
            'site_logo_icon' => $site_logo_icon,
            'site_logo_image' => $site_logo_image,
            'site_logo_image_dark' => $site_logo_image_dark,
            'site_logo_wide' => $site_logo_wide,
            'site_favicon_url' => $site_favicon_url,
        ], ['title' => t('admin_pin.sudo_page_title')]);
    }

    /**
     * A return URL is only honoured when it is a plain local path — never
     * an absolute URL or a protocol-relative one (open-redirect guard).
     */
    private static function safeNext(string $next): string
    {
        $next = trim($next);
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
            return '';
        }
        if (str_contains($next, '://')) {
            return '';
        }
        return $next;
    }
}
