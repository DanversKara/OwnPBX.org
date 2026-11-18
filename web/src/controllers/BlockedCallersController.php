<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/InboundBlacklistService.php';

/**
 * Panel -> Blocked callers (/blocked-callers): the numbers this person has blocked.
 *
 * Each user sees their own list and nobody else's, which is the point: who somebody blocks
 * is their business. An administrator sees every list on the Blacklist page, because they
 * answer for the PBX. A blocked caller is stopped on the way to this extension only, and
 * still reaches everyone else.
 */
class BlockedCallersController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();

        require_once dirname(__DIR__) . '/repositories/MyPhoneRepository.php';
        $details = MyPhoneRepository::getUserExtensionDetails((int) ($_SESSION['user_id'] ?? 0));
        $ext = preg_replace('/\D+/', '', trim((string) ($details['extension'] ?? ''))) ?? '';
        $mayBlock = $ext !== '' && InboundBlacklistService::userMayBlock($ext);
        $notices = ['message' => '', 'error' => ''];

        if (static::isPost()) {
            if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
                $notices['error'] = t('common.invalid_csrf');
            } elseif ($ext === '') {
                $notices['error'] = t('blocked.err_no_extension');
            } else {
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'block' && $mayBlock) {
                    $res = InboundBlacklistService::addForUser(
                        $ext,
                        (string) ($_POST['number'] ?? ''),
                        trim((string) ($_POST['title'] ?? '')) ?: null,
                        (string) ($_POST['block_action'] ?? 'announcement')
                    );
                    if (!empty($res['success'])) {
                        $notices['message'] = t('blocked.msg_blocked');
                    } else {
                        $notices['error'] = (string) ($res['error'] ?? t('common.unknown_error'));
                    }
                } elseif ($action === 'unblock') {
                    $notices = InboundBlacklistService::deleteForUser($ext, (int) ($_POST['entry_id'] ?? 0))
                        ? ['message' => t('blocked.msg_unblocked'), 'error' => '']
                        : ['message' => '', 'error' => t('blocked.err_not_yours')];
                } elseif (!$mayBlock) {
                    $notices['error'] = t('blocked.err_not_allowed');
                }
            }
        }

        static::renderPage('blocked_callers/index', [
            'entries' => $ext !== '' ? InboundBlacklistService::listForUser($ext) : [],
            'extension' => $ext,
            'mayBlock' => $mayBlock,
            'lastCaller' => $ext !== '' ? InboundBlacklistService::getLastCallerForExtension($ext) : null,
            'notices' => $notices,
        ], ['title' => t('blocked.title')]);
    }
}
