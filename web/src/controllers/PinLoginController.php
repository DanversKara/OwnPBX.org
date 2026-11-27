<?php
require_once __DIR__ . '/../services/AdminPinService.php';
require_once __DIR__ . '/../services/VaultSealService.php';

/**
 * Third sign-in step (/login-pin): the admin PIN (6-12 digits) after the
 * password and, when enabled, the TOTP code. Only admin-role users reach
 * this step. Logic: AdminPinService.
 *
 * A first-time admin with no PIN hash is sent to /admin-pin-setup instead.
 * Three wrong PINs in the failure window seal the OpenBao vault (and lock
 * this account out of the PIN until the window expires); the admin recovers
 * by unsealing via BaoLock and signing in again.
 */
class PinLoginController extends BaseController
{
    public static function index(): void
    {
        // Back to login if the session has no user waiting for the PIN
        if (empty($_SESSION['pending_pin_user_id'])) {
            static::redirect('/login');
            return;
        }

        $userId = (int) $_SESSION['pending_pin_user_id'];
        $row = AdminPinService::loadPinRow($userId);
        if (!$row || (int) ($row['is_active'] ?? 0) !== 1 || ($row['role'] ?? '') !== 'admin') {
            unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
            static::redirect('/login');
            return;
        }

        // A password reset flagged between the password and this step wins
        // over the PIN, like the 2FA step does.
        if (!empty($row['must_reset_password'])) {
            unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
            $_SESSION['pending_reset_user_id'] = $row['id'];
            $_SESSION['pending_reset_username'] = $row['username'];
            static::redirect('/force-reset');
            return;
        }

        // No PIN set yet: force setup first.
        if (empty($row['admin_pin_hash'])) {
            $_SESSION['pending_pin_setup_user_id'] = $userId;
            unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
            static::redirect('/admin-pin-setup');
            return;
        }

        $error = '';
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (static::isPost()) {
            $action = $_POST['action'] ?? 'verify';

            if ($action === 'cancel') {
                unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
                static::redirect('/login');
                return;
            }

            $csrf = $_POST['csrf_token'] ?? '';
            // The same IP/user lockout as the web login.
            if (checkBruteForceLockout($clientIp, $row['username'])) {
                unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
                notify(t('login.too_many_attempts'), 'danger');
                static::redirect('/login');
                return;
            }
            if (!verifyCSRFToken($csrf)) {
                $error = t('login.csrf_error');
            } else {
                $pin = trim($_POST['admin_pin'] ?? '');
                if (!AdminPinService::isValidPinFormat($pin)) {
                    $error = t('admin_pin.enter_valid_pin');
                } else {
                    $res = AdminPinService::verifyPin($userId, $pin, $clientIp);
                    if (!empty($res['ok'])) {
                        // Regenerate the session ID
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
                        // The PIN was just entered: sudo-mode is fresh.
                        $_SESSION['admin_pin_at'] = time();

                        unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);

                        if (function_exists('logLoginAttempt')) {
                            logLoginAttempt($clientIp, $row['username'], 'SUCCESS');
                        }
                        if (function_exists('writeAuditLog')) {
                            writeAuditLog('security', 'sys_users', (string) $row['id'], "User '{$row['username']}' signed in with admin PIN.", 'admin_pin_login', (int) $row['id']);
                        }

                        static::redirect(roleHomePath($row['role']));
                        return;
                    }
                    if (!empty($res['sealed'])) {
                        // 3rd wrong PIN: the vault is sealed (or the seal
                        // call failed — still locked out, fail closed).
                        // Destroy the pending session; the admin recovers by
                        // unsealing via BaoLock and signing in again.
                        unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
                        notify(t('admin_pin.sealed'), 'danger');
                        static::redirect('/login');
                        return;
                    }
                    if (!empty($res['locked'])) {
                        unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
                        notify(t('admin_pin.too_many_failures'), 'danger');
                        static::redirect('/login');
                        return;
                    }
                    if (!empty($res['needs_setup'])) {
                        $_SESSION['pending_pin_setup_user_id'] = $userId;
                        unset($_SESSION['pending_pin_user_id'], $_SESSION['pending_pin_username'], $_SESSION['pending_pin_full_name']);
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
            'action' => '/login-pin',
            'next' => '',
            'site_title' => $site_title,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
            'site_logo_type' => $site_logo_type,
            'site_logo_icon' => $site_logo_icon,
            'site_logo_image' => $site_logo_image,
            'site_logo_image_dark' => $site_logo_image_dark,
            'site_logo_wide' => $site_logo_wide,
            'site_favicon_url' => $site_favicon_url,
        ], ['title' => t('admin_pin.page_title')]);
    }
}
