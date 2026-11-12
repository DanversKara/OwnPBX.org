<?php

require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/../asterisk_helper.php';

/**
 * My Phone page (/my-phone).
 * Renders templates/views/my_phone/index.php with assets/js/my_phone.js.
 * Logic: MyPhoneRepository, VoicemailService.
 * Access by role module (getModuleKeyForPage() in auth.php, Admin → Roles).
 */
class MyPhoneController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('my_phone', 'view');

        $user = getCurrentUser();
        $userId = (int)($user['id'] ?? 0);

        if (static::isPost() && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
            if (!static::verifyCsrf()) {
                static::notifyError(t('my_phone.msg_csrf_error'));
            } elseif (!hasModulePermission('my_phone', 'edit')) {
                static::notifyError(t('roles.read_only_badge'));
            } else {
                $dnd = isset($_POST['dnd_enabled']) ? 1 : 0;
                $forwardAlways = preg_replace('/[^0-9+*#]/', '', trim($_POST['call_forward_number'] ?? ''));
                $forwardBusy = preg_replace('/[^0-9+*#]/', '', trim($_POST['cf_busy_number'] ?? ''));
                $forwardNoAnswer = preg_replace('/[^0-9+*#]/', '', trim($_POST['cf_noanswer_number'] ?? ''));
                $noAnswerTimeout = max(5, min(120, intval($_POST['cf_noanswer_timeout'] ?? 20)));

                $currentExtDetails = MyPhoneRepository::getUserExtensionDetails($userId) ?: [];
                $mode = $currentExtDetails['allowed_phone_mode'] ?? ($user['allowed_phone_mode'] ?? 'web');
                if (($_SESSION['user_role'] ?? '') === 'admin') {
                    if (isset($_POST['phone_modes']) && is_array($_POST['phone_modes'])) {
                        $mode = formatPhoneModes($_POST['phone_modes']);
                    } elseif (isset($_POST['allowed_phone_modes']) && is_array($_POST['allowed_phone_modes'])) {
                        $mode = formatPhoneModes($_POST['allowed_phone_modes']);
                    } elseif (isset($_POST['allowed_phone_mode'])) {
                        $mode = formatPhoneModes(parsePhoneModes($_POST['allowed_phone_mode']));
                    }
                }

                require_once dirname(__DIR__) . '/services/GuestService.php';
                foreach ([$forwardAlways, $forwardBusy, $forwardNoAnswer] as $fwd) {
                    if (!GuestService::forwardAllowed($userId, $fwd)) {
                        static::notifyError(t('guest.err_forward'));
                        static::redirect('/my-phone?tab=calls');
                        return;
                    }
                }
                MyPhoneRepository::updatePhoneSettings($userId, $dnd, $forwardAlways, $mode, $forwardBusy, $forwardNoAnswer, $noAnswerTimeout);
                // "Turn off automatically": '' = leave the running timer, 0 = never, minutes otherwise.
                require_once dirname(__DIR__) . '/services/DndTimerService.php';
                $dndMinutes = (string) ($_POST['dnd_minutes'] ?? '');
                DndTimerService::apply($userId, (bool) $dnd, $dndMinutes === '' ? null : (int) $dndMinutes);

                // Update session state for current user
                $_SESSION['allowed_phone_mode'] = $mode;

                // Sync dialplan and voicemail so changes take effect immediately
                try {
                    require_once dirname(__DIR__) . '/sync/SyncGeneralDialplan.php';
                    syncGeneralDialplan();
                    require_once dirname(__DIR__) . '/sync/SyncVoicemail.php';
                    syncVoicemail();
                } catch (\Throwable $e) {
                    // Non-fatal if reload fails; pending sync will catch it
                }

                $logDetail = "DND: {$dnd}, CFA: {$forwardAlways}, CFB: {$forwardBusy}, CFNA: {$forwardNoAnswer} ({$noAnswerTimeout}s), Mode: {$mode}";
                writeAuditLog(null, 'user_settings', 'phone', $logDetail, 'update', $userId);
                static::notifySuccess(t('my_phone.msg_settings_saved'));
            }
            static::redirect('/my-phone?tab=calls');
            return;
        }

        // Sign one of the user's own devices out (removes it from My Phone).
        // No edit-permission gate: any signed-in user may sign out their own
        // device; the user_id scope in deactivateDevice() enforces ownership.
        if (static::isPost() && ($_POST['action'] ?? '') === 'signout_device') {
            if (!static::verifyCsrf()) {
                static::notifyError(t('my_phone.msg_csrf_error'));
            } else {
                $deviceId = (int)($_POST['device_id'] ?? 0);
                $deviceName = '';
                foreach (MyPhoneRepository::getUserMobileDevices($userId) as $d) {
                    if ((int)($d['id'] ?? 0) === $deviceId) {
                        $deviceName = (string)($d['device_name'] ?? '');
                        break;
                    }
                }
                if (MyPhoneRepository::deactivateDevice($userId, $deviceId)) {
                    MyPhoneRepository::revokeDeviceSessions($userId, $deviceId);
                    writeAuditLog(null, 'user_settings', 'mobile', "Signed out device: {$deviceName} (id {$deviceId})", 'update', $userId);
                    static::notifySuccess(t('my_phone.msg_device_signed_out'));
                } else {
                    static::notifyError(t('my_phone.msg_device_signout_failed'));
                }
            }
            static::redirect('/my-phone?tab=calls');
            return;
        }

        // The number outside calls show by default: one of the numbers the administrator gave them.
        if (static::isPost() && ($_POST['action'] ?? '') === 'save_caller_id') {
            if (!static::verifyCsrf()) {
                static::notifyError(t('my_phone.msg_csrf_error'));
            } else {
                require_once dirname(__DIR__) . '/services/CallerIdService.php';
                try {
                    $picked = (string) ($_POST['caller_id'] ?? '');
                    CallerIdService::setDefault($userId, $picked);
                    try {
                        require_once dirname(__DIR__) . '/sync/SyncExtensions.php';
                        syncAllExtensions();
                    } catch (\Throwable $e) {
                        markPendingSync('extensions', 'extension', 'caller-id', 'Default caller ID changed', 'update', $userId);
                    }
                    writeAuditLog(null, 'user_settings', 'phone', 'Default caller ID: ' . ($picked !== '' ? $picked : 'line number'), 'update', $userId);
                    static::notifySuccess(t('my_phone.msg_settings_saved'));
                } catch (\InvalidArgumentException $e) {
                    static::notifyError($e->getMessage());
                }
            }
            static::redirect('/my-phone?tab=calls');
            return;
        }

        if (static::isPost() && ($_POST['action'] ?? '') === 'save_voicemail') {
            if (!static::verifyCsrf()) {
                static::notifyError(t('my_phone.msg_csrf_error'));
            } elseif (!hasModulePermission('my_phone', 'edit')) {
                static::notifyError(t('roles.read_only_badge'));
            } else {
                $vm = ['voicemail_pin' => $_POST['voicemail_pin'] ?? '', 'voicemail_email' => $_POST['voicemail_email'] ?? ''];
                foreach (['voicemail_enabled', 'voicemail_email_notify', 'voicemail_attach_audio', 'vm_on_noanswer', 'vm_on_busy', 'vm_on_unavail', 'vm_always'] as $k) {
                    $vm[$k] = isset($_POST[$k]) ? 1 : 0;
                }
                try {
                    MyPhoneRepository::updateVoicemailSettings($userId, $vm);
                    try {
                        require_once dirname(__DIR__) . '/sync/SyncGeneralDialplan.php';
                        syncGeneralDialplan();
                        require_once dirname(__DIR__) . '/sync/SyncVoicemail.php';
                        syncVoicemail();
                    } catch (\Throwable $e) {
                        // Non-fatal if reload fails; pending sync will catch it
                    }
                    writeAuditLog(null, 'user_settings', 'voicemail',
                        "Voicemail: {$vm['voicemail_enabled']}, mail: {$vm['voicemail_email_notify']}, attach: {$vm['voicemail_attach_audio']}", 'update', $userId);
                    static::notifySuccess(t('my_phone.msg_settings_saved'));
                } catch (\InvalidArgumentException $e) {
                    static::notifyError($e->getMessage());
                }
            }
            static::redirect('/my-phone?tab=voicemail');
            return;
        }

        $extDetails = MyPhoneRepository::getUserExtensionDetails($userId);
        $ext = trim($extDetails['extension'] ?? '');

        require_once dirname(__DIR__) . '/services/VoicemailService.php';
        $voicemailMessages = VoicemailService::getVoicemailMessages($ext);

        $tab = trim($_GET['tab'] ?? 'history');
        if (!in_array($tab, ['history', 'calls', 'settings', 'voicemail'], true)) {
            $tab = 'history';
        }

        $filter = trim($_GET['filter'] ?? 'all');
        if (!in_array($filter, ['all', 'in', 'out', 'missed'], true)) {
            $filter = 'all';
        }

        $search = trim($_GET['q'] ?? '');

        $stats = MyPhoneRepository::getCallStats($ext, $userId);
        $calls = MyPhoneRepository::getRecentCalls($ext, $filter === 'all' ? null : $filter, $search, 100, $userId);
        $directory = MyPhoneRepository::getInternalDirectory();

        $pjsipStatuses = AsteriskHelper::getPJSIPStatuses();
        $sipStatus = $ext !== '' ? ($pjsipStatuses["{$ext}-sip"] ?? null) : null;
        $webrtcStatus = $ext !== '' ? ($pjsipStatuses["{$ext}-webrtc"] ?? null) : null;
        $mobileStatus = $ext !== '' ? ($pjsipStatuses["{$ext}-mob-webrtc"] ?? null) : null;

        $page_title = t('my_phone.title');
        static::renderPage('my_phone/index', [
            'extDetails' => $extDetails,
            'ext' => $ext,
            'stats' => $stats,
            'calls' => $calls,
            'directory' => $directory,
            'filter' => $filter,
            'search' => $search,
            'tab' => $tab,
            'sipStatus' => $sipStatus,
            'webrtcStatus' => $webrtcStatus,
            'mobileStatus' => $mobileStatus,
            'mobileDevices' => MyPhoneRepository::getUserMobileDevices($userId),
            'callerIds' => (function () use ($userId) {
                require_once dirname(__DIR__) . '/services/CallerIdService.php';
                return CallerIdService::assigned($userId) === [] ? [] : CallerIdService::choices($userId);
            })(),
            'voicemailMessages' => $voicemailMessages,
        ], ['title' => $page_title]);
    }
}
