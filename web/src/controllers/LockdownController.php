<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/SecurityLockdownService.php';
require_once __DIR__ . '/../services/SmsGuardService.php';
require_once __DIR__ . '/../services/TwoFactorService.php';

/**
 * System -> Emergency lockdown (/lockdown): the doors, and the text message that shuts them.
 *
 * Switching a lockdown on can be done from anywhere, because the day you need it you may be
 * nowhere near the building. Lifting one cannot: it is refused unless the request comes from the
 * local network, which leaves an attacker who has taken an administrator account with no way to
 * undo it. The console (bin/unlock.php) is the other way back, and the only one that works when
 * even the local network cannot be trusted.
 */
class LockdownController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireRole('admin');

        $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $isLocal = SecurityLockdownService::isLocalIp((string) $ip);
        $notices = ['message' => '', 'error' => ''];
        $newSecret = null;

        if (static::isPost()) {
            if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
                $notices['error'] = t('common.invalid_csrf');
            } else {
                [$notices, $newSecret] = static::act((string) ($_POST['action'] ?? ''), (string) $ip, $isLocal);
            }
        }

        static::render('lockdown/index', [
            'page_title' => t('lockdown.title'),
            'state' => SecurityLockdownService::state(),
            'locked_users' => SecurityLockdownService::lockedUsers(),
            'is_local' => $isLocal,
            'client_ip' => $ip,
            'guard_enabled' => getSystemSetting(SmsGuardService::SETTING_ENABLED, '0') === '1',
            'guard_numbers' => (string) getSystemSetting(SmsGuardService::SETTING_NUMBERS, ''),
            'guard_ready' => SmsGuardService::enabled(),
            'new_secret' => $newSecret,
            'notices' => $notices,
        ]);
    }

    /**
     * @return array{0: array{message: string, error: string}, 1: string|null}
     */
    protected static function act(string $action, string $ip, bool $isLocal): array
    {
        $notices = ['message' => '', 'error' => ''];
        $by = (string) ($_SESSION['username'] ?? 'admin');

        // Everything that opens a door, including changing the settings that guard it, is refused
        // from outside. An attacker signed in as an administrator can seal the PBX; they cannot
        // unseal it, and they cannot quietly re-point the text channel at their own phone.
        $loosening = ['lockdown_off', 'unlock_user', 'save_guard', 'new_secret'];
        if (in_array($action, $loosening, true) && !$isLocal) {
            return [['message' => '', 'error' => t('lockdown.err_local_only')], null];
        }

        switch ($action) {
            case 'lockdown_on':
                SecurityLockdownService::enable($by, $ip);
                $notices['message'] = t('lockdown.msg_on');
                break;

            case 'lockdown_off':
                SecurityLockdownService::disable($by, $ip);
                $notices['message'] = t('lockdown.msg_off');
                break;

            case 'kick_all':
                $n = SecurityLockdownService::kickAll();
                $notices['message'] = sprintf(t('lockdown.msg_kicked_all'), $n);
                break;

            case 'kick_user':
            case 'lock_user':
            case 'unlock_user':
                $who = trim((string) ($_POST['who'] ?? ''));
                $user = SecurityLockdownService::findUser($who);
                if ($user === null) {
                    $notices['error'] = t('lockdown.err_no_user');
                    break;
                }
                if ($action === 'kick_user') {
                    SecurityLockdownService::kickUser((int) $user['id']);
                    $notices['message'] = t('lockdown.msg_kicked_user');
                } elseif ($action === 'lock_user') {
                    SecurityLockdownService::lockUser((int) $user['id'], 'portal:' . $by);
                    $notices['message'] = t('lockdown.msg_locked_user');
                } else {
                    SecurityLockdownService::unlockUser((int) $user['id'], $by);
                    $notices['message'] = t('lockdown.msg_unlocked_user');
                }
                break;

            case 'save_guard':
                return [static::saveGuard(), null];

            case 'new_secret':
                $secret = TwoFactorService::generateSecret();
                setSystemSetting(SmsGuardService::SETTING_SECRET, $secret);
                $notices['message'] = t('lockdown.msg_new_secret');
                return [$notices, $secret];
        }

        return [$notices, null];
    }

    /** @return array{message: string, error: string} */
    protected static function saveGuard(): array
    {
        $numbers = trim((string) ($_POST['guard_numbers'] ?? ''));
        $pass = (string) ($_POST['guard_passphrase'] ?? '');
        $on = isset($_POST['guard_enabled']) ? '1' : '0';

        if ($pass !== '') {
            if (mb_strlen($pass) < 8) {
                return ['message' => '', 'error' => t('lockdown.err_passphrase_short')];
            }
            // The message is read as "<passphrase> <code> <command>", so a passphrase with a
            // space in it would swallow the code and every command would be refused - at the
            // worst possible moment, with no way to tell why.
            if (preg_match('/\s/u', $pass)) {
                return ['message' => '', 'error' => t('lockdown.err_passphrase_spaces')];
            }
            setSystemSetting(SmsGuardService::SETTING_PASSPHRASE, password_hash($pass, PASSWORD_DEFAULT));
        }
        setSystemSetting(SmsGuardService::SETTING_NUMBERS, $numbers);
        setSystemSetting(SmsGuardService::SETTING_ENABLED, $on);
        writeAuditLog('security', 'lockdown', 'sms_guard', 'Text command channel settings changed', 'update', null);

        if ($on === '1' && !SmsGuardService::enabled()) {
            return ['message' => '', 'error' => t('lockdown.err_guard_incomplete')];
        }
        return ['message' => t('lockdown.msg_guard_saved'), 'error' => ''];
    }
}
