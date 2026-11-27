<?php
require_once __DIR__ . '/../services/AdminPinService.php';

/**
 * First-time admin PIN setup (/admin-pin-setup).
 *
 * An admin with no PIN hash is sent here after the password (and, when
 * enabled, the TOTP code) instead of straight into the portal. The PIN is
 * entered twice, stored only as a password_hash(), and the sign-in then
 * completes — the admin just proved they know the PIN by typing it twice.
 * Logic: AdminPinService.
 */
class AdminPinSetupController extends BaseController
{
    public static function index(): void
    {
        // Back to login if the session has no user waiting for PIN setup
        if (empty($_SESSION['pending_pin_setup_user_id'])) {
            static::redirect('/login');
            return;
        }

        $userId = (int) $_SESSION['pending_pin_setup_user_id'];
        $row = AdminPinService::loadPinRow($userId);
        if (!$row || (int) ($row['is_active'] ?? 0) !== 1 || ($row['role'] ?? '') !== 'admin') {
            unset($_SESSION['pending_pin_setup_user_id']);
            static::redirect('/login');
            return;
        }

        // A PIN is already set: nothing to do here, go enter it.
        if (!empty($row['admin_pin_hash'])) {
            $_SESSION['pending_pin_user_id'] = $userId;
            $_SESSION['pending_pin_username'] = $row['username'];
            $_SESSION['pending_pin_full_name'] = $row['full_name'];
            unset($_SESSION['pending_pin_setup_user_id']);
            static::redirect('/login-pin');
            return;
        }

        $error = '';
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (static::isPost()) {
            $action = $_POST['action'] ?? 'save';
            if ($action === 'cancel') {
                unset($_SESSION['pending_pin_setup_user_id']);
                static::redirect('/login');
                return;
            }
            $csrf = $_POST['csrf_token'] ?? '';
            if (checkBruteForceLockout($clientIp, $row['username'])) {
                unset($_SESSION['pending_pin_setup_user_id']);
                notify(t('login.too_many_attempts'), 'danger');
                static::redirect('/login');
                return;
            }
            if (!verifyCSRFToken($csrf)) {
                $error = t('login.csrf_error');
            } else {
                $pin = trim($_POST['admin_pin'] ?? '');
                $pin2 = trim($_POST['admin_pin_confirm'] ?? '');
                if (!AdminPinService::isValidPinFormat($pin)) {
                    $error = t('admin_pin.enter_valid_pin');
                } elseif ($pin !== $pin2) {
                    $error = t('admin_pin.mismatch');
                } elseif (!AdminPinService::setPin($userId, $pin)) {
                    $error = t('admin_pin.enter_valid_pin');
                } else {
                    // PIN set: complete the sign-in (same fields as the
                    // 2FA step). The PIN was just typed twice, so sudo-mode
                    // is fresh.
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['full_name'] = $row['full_name'];
                    $_SESSION['user_role'] = $row['role'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['extension'] = $row['extension'] ?? '';
                    $_SESSION['last_activity'] = time();
                    $_SESSION['theme'] = $row['theme_preference'] ?? null;
                    $_SESSION['ui_language'] = (defined('UI_LANGUAGES') && isset(UI_LANGUAGES[$row['language_preference'] ?? '']))
                        ? $row['language_preference']
                        : DEFAULT_UI_LANGUAGE;
                    $_SESSION['admin_pin_at'] = time();

                    unset($_SESSION['pending_pin_setup_user_id']);

                    if (function_exists('logLoginAttempt')) {
                        logLoginAttempt($clientIp, $row['username'], 'SUCCESS');
                    }
                    if (function_exists('writeAuditLog')) {
                        writeAuditLog('security', 'sys_users', (string) $row['id'], "User '{$row['username']}' set their admin PIN and signed in.", 'admin_pin_setup', (int) $row['id']);
                    }

                    // An already signed-in admin sent here from sudo-mode
                    // goes back to the page they wanted, not the home page.
                    $setupNext = (string) ($_SESSION['pending_pin_setup_next'] ?? '');
                    unset($_SESSION['pending_pin_setup_next']);
                    if ($setupNext !== '' && $setupNext[0] === '/' && !str_starts_with($setupNext, '//') && !str_contains($setupNext, '://')) {
                        static::redirect($setupNext);
                        return;
                    }

                    static::redirect(roleHomePath($row['role']));
                    return;
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

        static::renderAuthPage('login/admin_pin_setup', [
            'error' => $error,
            'user' => $row,
            'csrf_token' => $csrf_token,
            'site_title' => $site_title,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
            'site_logo_type' => $site_logo_type,
            'site_logo_icon' => $site_logo_icon,
            'site_logo_image' => $site_logo_image,
            'site_logo_image_dark' => $site_logo_image_dark,
            'site_logo_wide' => $site_logo_wide,
            'site_favicon_url' => $site_favicon_url,
        ], ['title' => t('admin_pin.setup_page_title')]);
    }
}
