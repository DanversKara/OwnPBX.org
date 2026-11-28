<?php
/**
 * Integrations → Business cards: one card per person you hand a card to.
 *
 * @var array                   $cards      every card, newest first
 * @var array                   $widgets    widgets a card can call through
 * @var array<string,string>|null $fresh    a just-issued token's QR and links, shown once
 * @var array<string,mixed>|null $edit      the card being edited, if any
 * @var array                   $editFields its field rows
 * @var int                     $viewLogFor card id whose log is open
 * @var array                   $viewLog    that log
 * @var list<string>            $kinds      field kinds
 */
$canEdit = hasModulePermission('business_cards', 'edit');
$csrf = getCSRFToken();
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$badges = [
    'view' => 'success', 'call' => 'info', 'pin_failed' => 'warning',
    'call_capped' => 'warning', 'rate_ip' => 'warning', 'rate_pin' => 'warning',
    'bad_token' => 'danger', 'not_found' => 'secondary',
    'revoked' => 'secondary', 'expired' => 'secondary', 'view_cap' => 'secondary',
];
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/business_cards.css'); ?>">

<div class="card">
    <?php echo uiPageHeaderOpen('fa-id-card', t('cards.title'), 'businessCardsHelpBox'); ?>
<span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($cards); ?></span>
<?php echo uiPageHeaderActions(); ?>
<?php if ($canEdit): ?>
    <a href="/business-cards?edit=0" class="btn btn-primary btn-sm"><i class="fas fa-plus-circle"></i> <?php echo t('cards.add'); ?></a>
<?php endif; ?>
<?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('businessCardsHelpBox', t('cards.help_title')); ?>
        <p><?php echo t('cards.help_body'); ?></p>
        <ul>
            <li><strong><?php echo t('cards.help_token'); ?></strong> <?php echo t('cards.help_token_desc'); ?></li>
            <li><strong><?php echo t('cards.help_once'); ?></strong> <?php echo t('cards.help_once_desc'); ?></li>
            <li><strong><?php echo t('cards.help_pin'); ?></strong> <?php echo t('cards.help_pin_desc'); ?></li>
            <li><strong><?php echo t('cards.help_spam'); ?></strong> <?php echo t('cards.help_spam_desc'); ?></li>
        </ul>
    <?php echo uiHelpBoxClose(); ?>

    <?php /* The token exists in readable form for exactly this one render. */ ?>
    <?php if ($fresh !== null): ?>
    <div class="alert alert-success u-mb-16">
        <h4 class="u-mt-0"><i class="fas fa-qrcode"></i> <?php echo $e(sprintf(t('cards.fresh_title'), $fresh['label'])); ?></h4>
        <p class="u-mb-12"><strong><?php echo t('cards.fresh_warning'); ?></strong></p>
        <div class="bc-fresh">
            <?php if ($fresh['qr'] !== ''): ?>
                <img src="<?php echo $e($fresh['qr']); ?>" alt="QR" width="190" height="190" class="bc-qr">
            <?php else: ?>
                <p class="u-hint"><?php echo t('cards.fresh_no_qr'); ?></p>
            <?php endif; ?>
            <div class="u-flex-1">
                <label class="u-fs-12 u-hint"><?php echo t('cards.fresh_url'); ?></label>
                <input type="text" class="form-control u-mb-8" readonly onclick="this.select()"
                       value="<?php echo $e($fresh['url']); ?>">
                <?php if ($fresh['nfc'] !== ''): ?>
                    <label class="u-fs-12 u-hint"><?php echo t('cards.fresh_nfc'); ?></label>
                    <input type="text" class="form-control" readonly onclick="this.select()"
                           value="<?php echo $e($fresh['nfc']); ?>">
                <?php else: ?>
                    <p class="u-hint u-fs-12"><?php echo t('cards.fresh_nfc_pin'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ---------------------------------------------------------------- the list */ ?>
    <?php echo uiTableOpen([
        t('cards.col_label'),
        t('cards.col_shows'),
        t('cards.col_call'),
        t('cards.col_views'),
        t('cards.col_state'),
        ['label' => t('common.actions'), 'class' => 'u-text-right'],
    ]); ?>
            <?php if ($cards === []): ?>
                <tr><td colspan="6" class="u-text-center u-hint u-p-24"><?php echo t('cards.none'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($cards as $c): ?>
                <?php
                $revoked = (int) $c['is_active'] !== 1 || $c['revoked_at'] !== null;
                $expired = ($c['expires_at'] ?? '') !== '' && (string) $c['expires_at'] < date('Y-m-d H:i:s');
                $capped = (int) $c['max_views'] > 0 && (int) $c['view_count'] >= (int) $c['max_views'];
                ?>
                <tr<?php echo $revoked ? ' class="bc-off"' : ''; ?>>
                    <td>
                        <strong><?php echo $e($c['label']); ?></strong>
                        <?php if (($c['pin_hash'] ?? '') !== ''): ?>
                            <span class="badge badge-info u-fs-11" title="<?php echo $e(t('cards.has_pin')); ?>"><i class="fas fa-lock"></i> PIN</span>
                        <?php endif; ?>
                        <div class="u-hint u-fs-12"><?php echo $e(t('cards.issued')); ?> <?php echo $e($c['created_at']); ?></div>
                    </td>
                    <td>
                        <?php echo $e($c['display_name']); ?>
                        <?php if (($c['company'] ?? '') !== ''): ?><div class="u-hint u-fs-12"><?php echo $e($c['company']); ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $c['call_enabled'] === 1 && $c['widget_id'] !== null): ?>
                            <?php echo $e($c['widget_name'] ?? ''); ?>
                            <div class="u-hint u-fs-12">
                                <?php echo (int) $c['calls_today']; ?> / <?php echo (int) $c['calls_daily_limit'] === 0 ? '&infin;' : (int) $c['calls_daily_limit']; ?>
                                <?php echo $e(t('cards.today')); ?>
                            </div>
                        <?php else: ?>
                            <span class="u-hint"><?php echo t('cards.no_call'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo (int) $c['view_count']; ?><?php if ((int) $c['max_views'] > 0): ?> / <?php echo (int) $c['max_views']; ?><?php endif; ?>
                        <?php if (($c['last_viewed_at'] ?? null) !== null): ?>
                            <div class="u-hint u-fs-12"><?php echo $e($c['last_viewed_at']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($revoked): ?>
                            <span class="badge badge-secondary"><?php echo t('cards.state_revoked'); ?></span>
                        <?php elseif ($expired): ?>
                            <span class="badge badge-warning"><?php echo t('cards.state_expired'); ?></span>
                        <?php elseif ($capped): ?>
                            <span class="badge badge-warning"><?php echo t('cards.state_capped'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-success"><?php echo t('cards.state_live'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="u-text-right u-nowrap">
                        <a href="/business-cards?log=<?php echo (int) $c['id']; ?>" class="btn btn-secondary btn-sm" title="<?php echo $e(t('cards.view_log')); ?>"><i class="fas fa-list"></i></a>
                        <?php if ($canEdit): ?>
                            <a href="/business-cards?edit=<?php echo (int) $c['id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-pen"></i></a>
                            <form method="post" class="u-inline" onsubmit="return confirm('<?php echo $e(t('cards.confirm_reissue')); ?>')">
                                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                                <input type="hidden" name="card_id" value="<?php echo (int) $c['id']; ?>">
                                <button type="submit" name="reissue_card" value="1" class="btn btn-secondary btn-sm" title="<?php echo $e(t('cards.reissue')); ?>"><i class="fas fa-rotate"></i></button>
                            </form>
                            <?php if (!$revoked): ?>
                            <form method="post" class="u-inline" onsubmit="return confirm('<?php echo $e(t('cards.confirm_revoke')); ?>')">
                                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                                <input type="hidden" name="card_id" value="<?php echo (int) $c['id']; ?>">
                                <button type="submit" name="revoke_card" value="1" class="btn btn-warning btn-sm" title="<?php echo $e(t('cards.revoke')); ?>"><i class="fas fa-ban"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="u-inline" onsubmit="return confirm('<?php echo $e(t('cards.confirm_delete')); ?>')">
                                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                                <input type="hidden" name="card_id" value="<?php echo (int) $c['id']; ?>">
                                <button type="submit" name="delete_card" value="1" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>

<?php /* ---------------------------------------------------------------- the editor */ ?>
<?php if ($canEdit && isset($_GET['edit'])): ?>
<div class="card u-mt-16">
    <div class="card-header"><h3 class="u-m-0"><i class="fas fa-pen"></i>
        <?php echo $edit !== null ? $e(sprintf(t('cards.edit_title'), $edit['label'])) : t('cards.add'); ?></h3></div>
    <div class="card-body">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
        <input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">

        <div class="form-row">
            <div class="form-group u-flex-1">
                <label><?php echo t('cards.f_label'); ?> <span class="u-danger">*</span></label>
                <input type="text" name="label" maxlength="120" required class="form-control"
                       value="<?php echo $e($edit['label'] ?? ''); ?>">
                <small class="u-hint"><?php echo t('cards.f_label_hint'); ?></small>
            </div>
            <div class="form-group u-flex-1">
                <label><?php echo t('cards.f_display_name'); ?></label>
                <input type="text" name="display_name" maxlength="120" class="form-control"
                       value="<?php echo $e($edit['display_name'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group u-flex-1">
                <label><?php echo t('cards.f_job_title'); ?></label>
                <input type="text" name="job_title" maxlength="120" class="form-control"
                       value="<?php echo $e($edit['job_title'] ?? ''); ?>">
            </div>
            <div class="form-group u-flex-1">
                <label><?php echo t('cards.f_company'); ?></label>
                <input type="text" name="company" maxlength="120" class="form-control"
                       value="<?php echo $e($edit['company'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label><?php echo t('cards.f_accent'); ?></label>
                <input type="color" name="accent_color" class="form-control"
                       value="<?php echo $e($edit['accent_color'] ?? '#2563eb'); ?>">
            </div>
        </div>
        <div class="form-group">
            <label><?php echo t('cards.f_tagline'); ?></label>
            <input type="text" name="tagline" maxlength="255" class="form-control"
                   value="<?php echo $e($edit['tagline'] ?? ''); ?>">
        </div>

        <h4><?php echo t('cards.f_lines'); ?></h4>
        <p class="u-hint u-fs-12"><?php echo t('cards.f_lines_hint'); ?></p>
        <div id="cardFields">
        <?php
        $rows = $editFields !== [] ? $editFields : [['kind' => 'phone', 'label' => '', 'value' => '']];
        foreach ($rows as $f): ?>
            <div class="form-row u-mb-8">
                <div class="form-group">
                    <select name="field_kind[]" class="form-control">
                        <?php foreach ($kinds as $k): ?>
                            <option value="<?php echo $e($k); ?>"<?php echo ($f['kind'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $e(t('cards.kind_' . $k)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group u-flex-1">
                    <input type="text" name="field_label[]" maxlength="80" class="form-control"
                           placeholder="<?php echo $e(t('cards.f_line_label')); ?>" value="<?php echo $e($f['label'] ?? ''); ?>">
                </div>
                <div class="form-group bc-wide">
                    <input type="text" name="field_value[]" maxlength="500" class="form-control"
                           placeholder="<?php echo $e(t('cards.f_line_value')); ?>" value="<?php echo $e($f['value'] ?? ''); ?>">
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-sm u-mb-16" id="cardAddLine"><i class="fas fa-plus"></i> <?php echo t('cards.f_add_line'); ?></button>

        <h4><?php echo t('cards.f_calling'); ?></h4>
        <div class="form-row">
            <div class="form-group u-flex-1">
                <label><?php echo t('cards.f_widget'); ?></label>
                <select name="widget_id" class="form-control">
                    <option value="0"><?php echo $e(t('cards.f_widget_none')); ?></option>
                    <?php foreach ($widgets as $w): ?>
                        <option value="<?php echo (int) $w['id']; ?>"<?php echo (int) ($edit['widget_id'] ?? 0) === (int) $w['id'] ? ' selected' : ''; ?>>
                            <?php echo $e($w['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="u-hint"><?php echo t('cards.f_widget_hint'); ?></small>
            </div>
            <div class="form-group">
                <label><?php echo t('cards.f_calls_limit'); ?></label>
                <input type="number" name="calls_daily_limit" min="0" max="1000" class="form-control"
                       value="<?php echo (int) ($edit['calls_daily_limit'] ?? 5); ?>">
            </div>
            <div class="form-group u-flex-center">
                <label class="u-flex-center u-gap-8">
                    <input type="checkbox" name="call_enabled" value="1"<?php echo (int) ($edit['call_enabled'] ?? 1) === 1 ? ' checked' : ''; ?>>
                    <?php echo t('cards.f_call_enabled'); ?>
                </label>
            </div>
        </div>

        <h4><?php echo t('cards.f_limits'); ?></h4>
        <div class="form-row">
            <div class="form-group">
                <label><?php echo t('cards.f_max_views'); ?></label>
                <input type="number" name="max_views" min="0" max="100000" class="form-control"
                       value="<?php echo (int) ($edit['max_views'] ?? 0); ?>">
                <small class="u-hint"><?php echo t('cards.f_zero_unlimited'); ?></small>
            </div>
            <div class="form-group">
                <label><?php echo t('cards.f_expires'); ?></label>
                <input type="datetime-local" name="expires_at" class="form-control"
                       value="<?php echo $e(($edit['expires_at'] ?? null) !== null ? str_replace(' ', 'T', substr((string) $edit['expires_at'], 0, 16)) : ''); ?>">
            </div>
            <div class="form-group">
                <label><?php echo t('cards.f_pin'); ?></label>
                <input type="text" name="pin" inputmode="numeric" maxlength="16" class="form-control"
                       placeholder="<?php echo $e(($edit['pin_hash'] ?? '') !== '' ? t('cards.f_pin_set') : t('cards.f_pin_none')); ?>">
                <small class="u-hint"><?php echo t('cards.f_pin_hint'); ?></small>
            </div>
            <div class="form-group u-flex-center">
                <label class="u-flex-center u-gap-8">
                    <input type="checkbox" name="is_active" value="1"<?php echo (int) ($edit['is_active'] ?? 1) === 1 ? ' checked' : ''; ?>>
                    <?php echo t('cards.f_active'); ?>
                </label>
                <?php if (($edit['pin_hash'] ?? '') !== ''): ?>
                <label class="u-flex-center u-gap-8 u-mt-8">
                    <input type="checkbox" name="remove_pin" value="1"> <?php echo t('cards.f_remove_pin'); ?>
                </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="u-flex-gap u-mt-16">
            <button type="submit" name="save_card" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            <a href="/business-cards" class="btn btn-secondary"><?php echo t('common.cancel'); ?></a>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<?php /* ---------------------------------------------------------------- the view log */ ?>
<?php if ($viewLogFor > 0): ?>
<div class="card u-mt-16">
    <div class="card-header"><h3 class="u-m-0"><i class="fas fa-list"></i> <?php echo t('cards.log_title'); ?></h3></div>
    <?php echo uiTableOpen([
        t('cards.log_when'), t('cards.log_result'), t('cards.log_ip'), t('cards.log_ua'),
    ]); ?>
            <?php if ($viewLog === []): ?>
                <tr><td colspan="4" class="u-text-center u-hint u-p-24"><?php echo t('cards.log_none'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($viewLog as $v): ?>
                <tr>
                    <td class="u-nowrap"><?php echo $e($v['created_at']); ?></td>
                    <td><span class="badge badge-<?php echo $e($badges[$v['result']] ?? 'secondary'); ?>"><?php echo $e($v['result']); ?></span></td>
                    <td><?php echo $e($v['ip']); ?></td>
                    <td class="u-fs-12 u-hint"><?php echo $e(substr((string) $v['user_agent'], 0, 80)); ?></td>
                </tr>
            <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/business_cards.js'); ?>"></script>
