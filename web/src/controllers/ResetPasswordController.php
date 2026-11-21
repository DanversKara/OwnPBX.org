<?php
require_once __DIR__ . '/../services/ResetPasswordService.php';

/**
 * Reset-password page (/reset-password), public: sets a new password with the token of
 * the e-mailed link ("Forgot your password?").
 * Logic: ResetPasswordRepository, ResetPasswordService.
 */
class ResetPasswordController extends BaseController
{
    public static function index(): void
    {
        $error = '';
        $success = false;

        $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        // Token submissions are throttled per IP (sys_login_logs, the same
        // 5-in-15-minutes pattern as checkBruteForceLockout's IP clause): a
        // submission with an invalid or expired token counts as one failure,
        // so guessing tokens from one address stalls quickly. Deliberately
        // per-IP only — checkBruteForceLockout() also counts per username,
        // and a shared throttle username would let a few bad guesses from
        // anywhere lock the reset page for everyone. The fixed label
        // 'reset-password' is logged, never the token itself. (A side effect
        // of reusing the FAILED status: 5 bad submissions also trip the
        // per-IP login lockout for 15 minutes — an address hammering reset
        // tokens is locked out of sign-in too.)
        $throttled = static::isPost() && self::resetSubmissionsThrottled($clientIp);
        $user = $throttled ? null : ResetPasswordRepository::lookupResetUser($token);

        if (static::isPost()) {
            if ($throttled) {
                // Same message as an invalid link: the throttle itself must
                // not tell whether the token was good.
                $error = t('srv_resetpw.err_link');
            } else {
                if (!$user) {
                    logLoginAttempt($clientIp, 'reset-password', 'FAILED');
                }
                $res = ResetPasswordService::resetPassword($_POST, $user);
                $success = $res['success'];
                $error = $res['error'] ?? '';
            }
        }

        $csrf_token = getCSRFToken();
        $site_title = getSystemSetting('site_title', 'OwnPBX Portal');
        $brand_title = getSystemSetting('brand_title', 'OwnPBX');
        $brand_sub = getSystemSetting('brand_sub', 'PBX & Call Center');

        static::renderAuthPage('reset_password/index', [
            'error' => $error,
            'success' => $success,
            'token' => $token,
            'user' => $user,
            'csrf_token' => $csrf_token,
            'site_title' => $site_title,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
        ], ['title' => t('reset_password.page_title')]);
    }

    /** Failed reset-token submissions from this IP in the last 15 minutes (5 = throttled). */
    private static function resetSubmissionsThrottled(string $ip): bool
    {
        $st = getDB()->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE ip_address = ? AND username = 'reset-password' AND status = 'FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $st->execute([$ip]);
        return (int) $st->fetchColumn() >= 5;
    }
}
