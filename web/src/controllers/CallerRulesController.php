<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/CallerRuleService.php';

/**
 * Controller for Caller Rules (/caller-rules).
 * First-match-wins inbound routing, checked before the blacklist.
 * Renders templates/views/caller_rules/index.php.
 */
class CallerRulesController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('did_routes', 'access');

        $canEdit = hasModulePermission('did_routes', 'edit');
        $canDelete = hasModulePermission('did_routes', 'delete');

        $notices = ['message' => '', 'error' => ''];

        if (static::isPost()) {
            $csrf = (string)($_POST['csrf_token'] ?? '');
            if (!verifyCSRFToken($csrf)) {
                $notices['error'] = t('common.invalid_csrf');
            } else {
                $postAction = (string)($_POST['action'] ?? '');

                if ($postAction === 'save_rule' && $canEdit) {
                    $id = intval($_POST['rule_id'] ?? 0);
                    $did = trim((string)($_POST['did_number'] ?? ''));
                    $caller = trim((string)($_POST['caller_number'] ?? ''));
                    $destType = (string)($_POST['dest_type'] ?? 'extension');
                    $destId = trim((string)($_POST['dest_id'] ?? ''));
                    $owner = trim((string)($_POST['owner_extension'] ?? ''));
                    $sortOrder = intval($_POST['sort_order'] ?? 0);
                    $isActive = !empty($_POST['is_active']);

                    if ($id > 0) {
                        $res = CallerRuleService::update($id, $did, $caller, $destType, $destId, $owner, $sortOrder, $isActive);
                        if ($res['success']) {
                            $notices['message'] = t('caller_rules.msg_updated');
                        } else {
                            $notices['error'] = $res['error'] ?? t('common.action_failed');
                        }
                    } else {
                        $res = CallerRuleService::add($did, $caller, $destType, $destId, $owner, $sortOrder, $isActive);
                        if ($res['success']) {
                            $notices['message'] = t('caller_rules.msg_added');
                        } else {
                            $notices['error'] = $res['error'] ?? t('common.action_failed');
                        }
                    }
                } elseif ($postAction === 'delete_rule' && $canDelete) {
                    $id = intval($_POST['rule_id'] ?? 0);
                    if ($id > 0 && CallerRuleService::delete($id)) {
                        $notices['message'] = t('caller_rules.msg_deleted');
                    } else {
                        $notices['error'] = t('common.action_failed');
                    }
                } elseif ($postAction === 'toggle_active' && $canEdit) {
                    $id = intval($_POST['rule_id'] ?? 0);
                    if ($id > 0 && CallerRuleService::toggleActive($id)) {
                        $notices['message'] = t('caller_rules.msg_status_changed');
                    } else {
                        $notices['error'] = t('common.action_failed');
                    }
                }
            }
        }

        $rules = CallerRuleService::listAll();

        static::renderPage('caller_rules/index', [
            'rules' => $rules,
            'destTypes' => CallerRuleService::DEST_TYPES,
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'notices' => $notices,
        ]);
    }
}
