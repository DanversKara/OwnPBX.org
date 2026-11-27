<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
    <div class="auth-card u-maxw-420">
        <div class="u-text-center u-mb-24">
            <div class="brand-icon u-w-56 u-h-56 u-m-0-auto-16-auto u-fs-24 u-bg-rgba-2-132-199-0-1 u-r-50pc u-d-flex u-ai-center u-jc-center">
                <i class="fas fa-key u-primary u-fs-26"></i>
            </div>
            <h2 class="u-fs-20 u-fw-800"><?php echo t('admin_pin.setup_title'); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6">
                <?php echo t('login_2fa.account_label'); ?> <strong><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></strong>
            </p>
            <p class="u-muted u-fs-13 u-mt-6">
                <?php echo t('admin_pin.setup_help'); ?>
            </p>
        </div>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <form method="POST" action="/admin-pin-setup" id="adminPinSetupForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group u-text-center">
                <label class="form-label u-fs-13 u-muted u-mb-12">
                    <?php echo t('admin_pin.new_pin_prompt'); ?>
                </label>
                <input type="password" name="admin_pin" id="admin_pin" class="form-control u-fs-28 u-fw-700 u-letter-spacing-10 u-ta-center u-h-54 u-r-12"
                       maxlength="12" pattern="[0-9]{6,12}" inputmode="numeric" autocomplete="off"
                       placeholder="••••••"
                       autofocus>
            </div>
            <div class="form-group u-text-center">
                <label class="form-label u-fs-13 u-muted u-mb-12">
                    <?php echo t('admin_pin.confirm_pin_prompt'); ?>
                </label>
                <input type="password" name="admin_pin_confirm" id="admin_pin_confirm" class="form-control u-fs-28 u-fw-700 u-letter-spacing-10 u-ta-center u-h-54 u-r-12"
                       maxlength="12" pattern="[0-9]{6,12}" inputmode="numeric" autocomplete="off"
                       placeholder="••••••">
            </div>

            <button type="submit" class="btn btn-primary u-w-100 u-jc-center u-p-14 u-mt-14 u-fs-15 u-fw-700">
                <i class="fas fa-check-circle u-mr-6"></i> <?php echo t('admin_pin.btn_save'); ?>
            </button>
        </form>

        <div class="u-mt-20 u-ta-center u-fs-13">
            <form method="POST" action="/admin-pin-setup" class="u-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="u-bg-none u-bd-none u-muted u-pointer u-fs-12" type="submit">
                    <i class="fas fa-arrow-left"></i> <?php echo t('admin_pin.btn_cancel'); ?>
                </button>
            </form>
        </div>
    </div>
