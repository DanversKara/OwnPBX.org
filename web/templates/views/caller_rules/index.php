<?php
$can_edit = $canEdit ?? false;
$can_delete = $canDelete ?? false;
$dest_types = $destTypes ?? [];
?>

<?php if (!empty($notices['message'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($notices['message']); ?></div>
<?php endif; ?>
<?php if (!empty($notices['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($notices['error']); ?></div>
<?php endif; ?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-route', t('caller_rules.title'), 'callerRulesHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateCallerRuleModal()" title="<?php echo t('common.add_new'); ?>">
                    <i class="fas fa-plus-circle"></i> <?php echo t('caller_rules.new_rule'); ?>
                </button>
            <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('callerRulesHelpBox', t('caller_rules.help_title', 'Caller Rules')); ?>
        <p><?php echo t('caller_rules.help_desc', 'Checked first on every incoming call to your numbers, before the blacklist. The first matching rule wins and routes the call to its destination. Rules for one specific number are checked before rules for any number; within each group the lowest order wins. Leave "Calls to" empty for any number, and "Caller" empty for any caller.'); ?></p>
<?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        ['label' => '#', 'class' => 'col-hide-mobile u-w-50'],
        t('caller_rules.col_calls_to'),
        t('caller_rules.col_caller'),
        t('caller_rules.col_goes_to'),
        ['label' => t('caller_rules.col_used'), 'class' => 'col-hide-mobile'],
        t('common.status'),
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ], ['id' => 'callerRulesTable']); ?>
                <?php if (empty($rules)): ?>
                    <?php echo uiTableEmptyRow(7, t('caller_rules.empty', 'No caller rules yet.'), 'fa-route'); ?>
                <?php else: ?>
                    <?php foreach ($rules as $r): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo (int)$r['id']; ?></td>
                            <td>
                                <?php if (!empty($r['did_number'])): ?>
                                    <code><?php echo htmlspecialchars($r['did_number']); ?></code>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo t('caller_rules.any_number'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['caller_number'])): ?>
                                    <code><?php echo htmlspecialchars($r['caller_number']); ?></code>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo t('caller_rules.any_caller'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?php echo htmlspecialchars((string)($r['dest_type'] ?? '')); ?></span>
                                <?php if (!empty($r['dest_id'])): ?>
                                    <code><?php echo htmlspecialchars($r['dest_id']); ?></code>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile text-muted u-fs-12"><?php echo (int)($r['hit_count'] ?? 0); ?></td>
                            <td>
                                <?php if (!empty($r['is_active'])): ?>
                                    <span class="badge badge-success"><?php echo t('common.active'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo t('common.inactive'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($can_edit): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openEditCallerRuleModal(<?php echo (int)$r['id']; ?>)" title="<?php echo t('common.edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo jsArg(t('caller_rules.confirm_toggle')); ?>);">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo t('caller_rules.toggle'); ?>">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                    <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo jsArg(t('caller_rules.confirm_delete')); ?>);">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="delete_rule">
                                        <input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
    <?php echo uiTableClose(); ?>
</div>

<?php if ($can_edit): ?>
<div class="modal fade" id="callerRuleModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="callerRuleForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="rule_id" id="cr_rule_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="callerRuleModalTitle"><?php echo t('caller_rules.new_rule'); ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="cr_did_number"><?php echo t('caller_rules.col_calls_to'); ?></label>
                        <input type="text" class="form-control" id="cr_did_number" name="did_number" placeholder="<?php echo t('caller_rules.any_number'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="cr_caller_number"><?php echo t('caller_rules.col_caller'); ?></label>
                        <input type="text" class="form-control" id="cr_caller_number" name="caller_number" placeholder="<?php echo t('caller_rules.any_caller'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="cr_dest_type"><?php echo t('caller_rules.col_goes_to'); ?></label>
                        <select class="form-control" id="cr_dest_type" name="dest_type">
                            <?php foreach ($dest_types as $dt): ?>
                                <option value="<?php echo htmlspecialchars($dt); ?>"><?php echo htmlspecialchars($dt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cr_dest_id"><?php echo t('caller_rules.dest_target'); ?></label>
                        <input type="text" class="form-control" id="cr_dest_id" name="dest_id" placeholder="1000">
                        <small class="form-text text-muted"><?php echo t('caller_rules.dest_target_help'); ?></small>
                    </div>
                    <div class="form-group">
                        <label for="cr_owner_extension"><?php echo t('caller_rules.owner'); ?></label>
                        <input type="text" class="form-control" id="cr_owner_extension" name="owner_extension" placeholder="<?php echo t('caller_rules.owner_help'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="cr_sort_order"><?php echo t('caller_rules.sort_order'); ?></label>
                        <input type="number" class="form-control" id="cr_sort_order" name="sort_order" value="0" min="0">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="cr_is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="cr_is_active"><?php echo t('common.active'); ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo t('common.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo t('common.save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var callerRulesData = <?php echo json_encode($rules ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function openCreateCallerRuleModal() {
    document.getElementById('callerRuleModalTitle').textContent = <?php echo jsArg(t('caller_rules.new_rule')); ?>;
    document.getElementById('cr_rule_id').value = '0';
    document.getElementById('cr_did_number').value = '';
    document.getElementById('cr_caller_number').value = '';
    document.getElementById('cr_dest_type').value = 'extension';
    document.getElementById('cr_dest_id').value = '';
    document.getElementById('cr_owner_extension').value = '';
    document.getElementById('cr_sort_order').value = '0';
    document.getElementById('cr_is_active').checked = true;
    jQuery('#callerRuleModal').modal('show');
}
function openEditCallerRuleModal(id) {
    var r = null;
    for (var i = 0; i < callerRulesData.length; i++) {
        if (parseInt(callerRulesData[i].id, 10) === parseInt(id, 10)) { r = callerRulesData[i]; break; }
    }
    if (!r) return;
    document.getElementById('callerRuleModalTitle').textContent = <?php echo jsArg(t('caller_rules.edit_rule')); ?>;
    document.getElementById('cr_rule_id').value = r.id;
    document.getElementById('cr_did_number').value = r.did_number || '';
    document.getElementById('cr_caller_number').value = r.caller_number || '';
    document.getElementById('cr_dest_type').value = r.dest_type || 'extension';
    document.getElementById('cr_dest_id').value = r.dest_id || '';
    document.getElementById('cr_owner_extension').value = r.owner_extension || '';
    document.getElementById('cr_sort_order').value = r.sort_order || '0';
    document.getElementById('cr_is_active').checked = !!parseInt(r.is_active, 10);
    jQuery('#callerRuleModal').modal('show');
}
</script>
<?php endif; ?>
