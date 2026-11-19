<?php
/**
 * System -> Emergency lockdown. Available: $state, $locked_users, $is_local, $client_ip,
 * $guard_enabled, $guard_numbers, $guard_ready, $new_secret, $notices.
 */
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
?>

<div class="card u-mb-20">
    <?php echo uiPageHeaderOpen('fa-lock', t('lockdown.title'), 'lockdownHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
<?php echo uiPageHeaderClose(); ?>
        <?php echo uiHelpBoxOpen('lockdownHelpBox', t('lockdown.sms_title')); ?>
        <?php echo $h(t('lockdown.sms_help')); ?>
        <?php echo uiHelpBoxClose(); ?>
</div>

<?php if (($notices['error'] ?? '') !== ''): ?>
    <div class="alert alert-danger"><?php echo $h($notices['error']); ?></div>
<?php endif; ?>
<?php if (($notices['message'] ?? '') !== ''): ?>
    <div class="alert alert-success"><?php echo $h($notices['message']); ?></div>
<?php endif; ?>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-lock"></i> <?php echo $h(t('lockdown.state_title')); ?></div></div>
    <div class="u-p-16">
        <?php if ($state['active']): ?>
            <p><strong><?php echo $h(t('lockdown.state_on')); ?></strong>
               <?php echo $h(t('lockdown.state_since')); ?> <?php echo $h($state['at']); ?>
               (<?php echo $h($state['by']); ?>)</p>
        <?php else: ?>
            <p><?php echo $h(t('lockdown.state_off')); ?></p>
        <?php endif; ?>
        <p class="u-hint"><?php echo $h(t('lockdown.local_networks')); ?> <code><?php echo $h($state['cidrs']); ?></code></p>
        <p class="u-hint">
            <?php echo $h(t('lockdown.your_address')); ?> <code><?php echo $h($client_ip); ?></code> —
            <?php echo $h($is_local ? t('lockdown.you_are_local') : t('lockdown.you_are_remote')); ?>
        </p>

        <form method="post" class="u-mt-10">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <?php if (!$state['active']): ?>
                <input type="hidden" name="action" value="lockdown_on">
                <button type="submit" class="btn btn-danger"
                        data-confirm="<?php echo $h(t('lockdown.confirm_on')); ?>">
                    <i class="fas fa-lock"></i> <?php echo $h(t('lockdown.btn_on')); ?>
                </button>
            <?php else: ?>
                <input type="hidden" name="action" value="lockdown_off">
                <button type="submit" class="btn btn-secondary" <?php echo $is_local ? '' : 'disabled'; ?>>
                    <i class="fas fa-lock-open"></i> <?php echo $h(t('lockdown.btn_off')); ?>
                </button>
                <?php if (!$is_local): ?>
                    <p class="u-hint u-mt-10"><?php echo $h(t('lockdown.err_local_only')); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-user-slash"></i> <?php echo $h(t('lockdown.sessions_title')); ?></div></div>
    <div class="u-p-16">
        <p class="u-hint"><?php echo $h(t('lockdown.sessions_help')); ?></p>
        <form method="post" class="u-mb-20">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="action" value="kick_all">
            <button type="submit" class="btn btn-danger"
                    data-confirm="<?php echo $h(t('lockdown.confirm_kick_all')); ?>">
                <i class="fas fa-power-off"></i> <?php echo $h(t('lockdown.btn_kick_all')); ?>
            </button>
        </form>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <div class="form-group">
                <label class="form-label" for="who"><?php echo $h(t('lockdown.who_label')); ?></label>
                <input type="text" class="form-control" id="who" name="who" placeholder="611">
            </div>
            <button type="submit" name="action" value="kick_user" class="btn btn-secondary">
                <?php echo $h(t('lockdown.btn_kick_user')); ?>
            </button>
            <button type="submit" name="action" value="lock_user" class="btn btn-danger">
                <?php echo $h(t('lockdown.btn_lock_user')); ?>
            </button>
            <button type="submit" name="action" value="unlock_user" class="btn btn-secondary" <?php echo $is_local ? '' : 'disabled'; ?>>
                <?php echo $h(t('lockdown.btn_unlock_user')); ?>
            </button>
        </form>
    </div>
</div>

<?php if ($locked_users !== []): ?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-ban"></i> <?php echo $h(t('lockdown.locked_title')); ?></div></div>
    <div class="u-p-16">
        <?php echo uiTableOpen([
            t('lockdown.col_user'),
            t('lockdown.col_since'),
            t('lockdown.col_reason'),
        ]); ?>
            <?php foreach ($locked_users as $u): ?>
                <tr>
                    <td><?php echo $h(trim((string) $u['extension'] . ' ' . (string) $u['full_name'])); ?></td>
                    <td><?php echo $h($u['locked_at']); ?></td>
                    <td><?php echo $h($u['locked_reason']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>
<?php endif; ?>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-sms"></i> <?php echo $h(t('lockdown.sms_title')); ?></div></div>
    <div class="u-p-16">
        <p class="u-hint"><?php echo $h(t('lockdown.sms_help')); ?></p>
        <pre class="u-p-16 u-overflow-x-auto u-bg-subtle">&lt;passphrase&gt; &lt;6-digit code&gt; LOCKDOWN
&lt;passphrase&gt; &lt;6-digit code&gt; KICK
&lt;passphrase&gt; &lt;6-digit code&gt; KICK 611
&lt;passphrase&gt; &lt;6-digit code&gt; LOCK 611
&lt;passphrase&gt; &lt;6-digit code&gt; STATUS</pre>
        <p class="u-hint"><?php echo $h(t('lockdown.sms_no_unlock')); ?></p>

        <?php if ($new_secret !== null): ?>
            <div class="alert alert-warning">
                <p><strong><?php echo $h(t('lockdown.secret_shown_once')); ?></strong></p>
                <?php if ($new_secret_qr !== ''): ?>
                    <p><img src="<?php echo $h($new_secret_qr); ?>" alt="<?php echo $h(t('lockdown.secret_qr_alt')); ?>" width="200" height="200"></p>
                <?php endif; ?>
                <p class="u-hint"><?php echo $h(t('lockdown.secret_type_instead')); ?></p>
                <p><code><?php echo $h($new_secret); ?></code></p>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <div class="form-group">
                <label class="form-label" for="guard_numbers"><?php echo $h(t('lockdown.sms_numbers')); ?></label>
                <input type="text" class="form-control" id="guard_numbers" name="guard_numbers"
                       value="<?php echo $h($guard_numbers); ?>" placeholder="+15555550123, +15555550124">
            </div>
            <div class="form-group">
                <label class="form-label" for="guard_passphrase"><?php echo $h(t('lockdown.sms_passphrase')); ?></label>
                <input type="password" class="form-control" id="guard_passphrase" name="guard_passphrase"
                       autocomplete="new-password" placeholder="<?php echo $h(t('lockdown.sms_passphrase_ph')); ?>" pattern="\S+">
            </div>
            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="guard_enabled" value="1" <?php echo $guard_enabled ? 'checked' : ''; ?>>
                    <?php echo $h(t('lockdown.sms_enable')); ?>
                </label>
            </div>
            <button type="submit" name="action" value="save_guard" class="btn btn-primary" <?php echo $is_local ? '' : 'disabled'; ?>>
                <i class="fas fa-save"></i> <?php echo $h(t('common.save')); ?>
            </button>
            <button type="submit" name="action" value="new_secret" class="btn btn-secondary" <?php echo $is_local ? '' : 'disabled'; ?>
                    data-confirm="<?php echo $h(t('lockdown.confirm_new_secret')); ?>">
                <i class="fas fa-key"></i> <?php echo $h(t('lockdown.btn_new_secret')); ?>
            </button>
        </form>
        <p class="u-hint u-mt-10">
            <?php echo $h($guard_ready ? t('lockdown.sms_ready') : t('lockdown.sms_not_ready')); ?>
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-terminal"></i> <?php echo $h(t('lockdown.console_title')); ?></div></div>
    <div class="u-p-16">
        <p class="u-hint"><?php echo $h(t('lockdown.console_help')); ?></p>
        <pre class="u-p-16 u-overflow-x-auto u-bg-subtle">php /opt/aipbx/web/bin/unlock.php status
php /opt/aipbx/web/bin/unlock.php lockdown off
php /opt/aipbx/web/bin/unlock.php user 611
php /opt/aipbx/web/bin/unlock.php all</pre>
    </div>
</div>

<script src="<?php echo asset('/assets/js/lockdown.js'); ?>"></script>
