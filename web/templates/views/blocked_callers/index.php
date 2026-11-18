<?php
/**
 * Panel -> Blocked callers: this person's own blocked numbers, nobody else's.
 * Available: $entries, $extension, $mayBlock, $lastCaller, $notices.
 */
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-user-slash', t('blocked.title'), 'blockedHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
        <?php if ($mayBlock): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('blockNumberForm').classList.toggle('u-d-none');">
                <i class="fas fa-plus-circle"></i> <?php echo t('blocked.block_a_number'); ?>
            </button>
        <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('blockedHelpBox', t('blocked.help_title')); ?>
        <?php echo t('blocked.help_body'); ?><br>
        - <strong><?php echo t('blocked.help_star86'); ?></strong><br>
        - <strong><?php echo t('blocked.help_private'); ?></strong>
<?php echo uiHelpBoxClose(); ?>

    <?php if ($extension === ''): ?>
        <div class="alert alert-info u-mt-16"><?php echo t('blocked.no_extension'); ?></div>
    <?php else: ?>

        <?php if (!$mayBlock): ?>
            <div class="alert alert-warning u-mt-16"><i class="fas fa-lock"></i> <?php echo t('blocked.err_not_allowed'); ?></div>
        <?php endif; ?>

        <?php if ($mayBlock): ?>
            <form method="POST" id="blockNumberForm" class="u-d-none u-p-16">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="block">
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('blocked.field_number'); ?></label>
                        <input type="text" name="number" class="form-control" required
                               placeholder="<?php echo $h($lastCaller ?: t('blocked.field_number_ph')); ?>"
                               value="<?php echo $h($lastCaller ?: ''); ?>">
                        <?php if ($lastCaller): ?>
                            <small class="u-hint"><?php echo t('blocked.last_caller_hint'); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('blocked.field_note'); ?></label>
                        <input type="text" name="title" class="form-control" maxlength="100" placeholder="<?php echo t('blocked.field_note_ph'); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('blocked.field_what_they_hear'); ?></label>
                    <select name="block_action" class="form-control">
                        <option value="announcement"><?php echo t('blocked.action_announcement'); ?></option>
                        <option value="busy"><?php echo t('blocked.action_busy'); ?></option>
                        <option value="congestion"><?php echo t('blocked.action_congestion'); ?></option>
                        <option value="drop"><?php echo t('blocked.action_drop'); ?></option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-slash"></i> <?php echo t('blocked.block_btn'); ?></button>
                </div>
            </form>
        <?php endif; ?>

        <?php if (empty($entries)): ?>
            <div class="u-p-24 u-text-center text-muted"><i class="fas fa-user-check u-fs-24 u-mb-8"></i><?php echo t('blocked.empty'); ?></div>
        <?php else: ?>
            <?php echo uiTableOpen([
                ['label' => t('blocked.col_number')],
                ['label' => t('blocked.col_note'), 'class' => 'col-hide-mobile'],
                ['label' => t('blocked.col_what_they_hear'), 'class' => 'col-hide-mobile'],
                ['label' => t('blocked.col_blocked_calls'), 'class' => 'col-hide-mobile'],
                ['label' => '', 'class' => 'u-text-right'],
            ]); ?>
                <?php foreach ($entries as $e): ?>
                    <tr>
                        <td><strong><?php echo $h($e['number']); ?></strong></td>
                        <td class="col-hide-mobile"><?php echo $h($e['title'] ?? ''); ?></td>
                        <td class="col-hide-mobile"><?php echo $h(t('blocked.action_' . ($e['block_action'] ?? 'busy'))); ?></td>
                        <td class="col-hide-mobile"><?php echo (int) ($e['hit_count'] ?? 0); ?></td>
                        <td class="u-text-right">
                            <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo jsArg(t('blocked.confirm_unblock')); ?>);">
                                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                <input type="hidden" name="action" value="unblock">
                                <input type="hidden" name="entry_id" value="<?php echo (int) $e['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> <?php echo t('blocked.unblock_btn'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
