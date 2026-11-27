<?php
require_once __DIR__ . '/VaultSealService.php';

/**
 * Admin PIN service.
 *
 * A second "something you know" for administrators, after the password and
 * (when enabled) the TOTP code: a 6-12 digit PIN. It is per-admin, stored as
 * a password_hash() in sys_users.admin_pin_hash — the same shape as the
 * per-user TOTP secret — so there is no shared secret and a PIN is never
 * stored or logged in plaintext.
 *
 * Two places use it:
 *  - Login: PinLoginController (/login-pin), the third sign-in step for
 *    admins. A first-time admin with no PIN hash is sent to
 *    /admin-pin-setup instead.
 *  - Sudo mode: requireAdminPin() in auth.php asks for the PIN again before
 *    an admin-only settings module, good for 15 minutes (SUDO_WINDOW).
 *
 * Three wrong PINs within the failure window (FAILURE_WINDOW, 60 minutes)
 * seal the OpenBao vault (VaultSealService::seal) and lock the account out
 * of the PIN until the window expires. The counter lives in the database
 * (admin_pin_failures / admin_pin_failures_at), so it survives restarts.
 * The window is what lets a locked-out admin recover: unseal the vault
 * (BaoLock), wait out the window, sign in again. Fail closed: if the seal
 * call itself fails, the session is still destroyed by the caller and the
 * counter stays at 3, so no more PIN tries are possible in this window.
 */
class AdminPinService
{
    public const PIN_MIN_LENGTH = 6;
    public const PIN_MAX_LENGTH = 12;
    /** Wrong PINs within the failure window before the vault is sealed. */
    public const MAX_FAILURES = 3;
    /** The failure counter resets after this many seconds without a window. */
    public const FAILURE_WINDOW_SECONDS = 3600;
    /** Sudo-mode: the PIN stays fresh for this many seconds. */
    public const SUDO_WINDOW_SECONDS = 900;

    public static function isValidPinFormat(string $pin): bool
    {
        return (bool) preg_match('/^[0-9]{' . self::PIN_MIN_LENGTH . ',' . self::PIN_MAX_LENGTH . '}$/', $pin);
    }

    /**
     * The PIN columns (plus the fields the login step needs for the session)
     * for one user, or null when there is no such user.
     */
    public static function loadPinRow(int $userId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, full_name, role, extension, theme_preference, language_preference, is_active, must_reset_password, admin_pin_hash, admin_pin_failures, admin_pin_failures_at FROM sys_users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Store (or reset) an admin's PIN. Returns false on a bad format. */
    public static function setPin(int $userId, string $pin): bool
    {
        if (!self::isValidPinFormat($pin)) {
            return false;
        }
        $db = getDB();
        $stmt = $db->prepare('UPDATE sys_users SET admin_pin_hash = ?, admin_pin_failures = 0, admin_pin_failures_at = NULL WHERE id = ?');
        return $stmt->execute([password_hash($pin, PASSWORD_DEFAULT), $userId]);
    }

    /**
     * Verify a PIN. Result keys:
     *  ok: the PIN matched (failures reset).
     *  needs_setup: the admin has no PIN hash yet.
     *  locked: 3 failures already in this window — no more tries until it expires.
     *  sealed: this was the 3rd wrong PIN; the vault seal was triggered.
     *  seal_ok: whether the seal call itself succeeded (false only means the
     *    seal could not be confirmed — the lockout still applies).
     *  failures: wrong-PIN count in this window, for the error message.
     *  error: 'invalid' | 'not_admin' when the account itself is unusable.
     *
     * The PIN itself is never logged.
     */
    public static function verifyPin(int $userId, string $pin, string $clientIp): array
    {
        $row = self::loadPinRow($userId);
        if (!$row || (int) ($row['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        if (($row['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'error' => 'not_admin'];
        }
        if (empty($row['admin_pin_hash'])) {
            return ['ok' => false, 'needs_setup' => true];
        }

        $failures = (int) ($row['admin_pin_failures'] ?? 0);
        $failuresAt = !empty($row['admin_pin_failures_at']) ? strtotime((string) $row['admin_pin_failures_at']) : 0;
        $windowExpired = $failuresAt > 0 && (time() - $failuresAt) >= self::FAILURE_WINDOW_SECONDS;
        if ($windowExpired) {
            $failures = 0;
            self::resetFailures($userId);
        }
        if ($failures >= self::MAX_FAILURES) {
            return ['ok' => false, 'locked' => true];
        }

        if (password_verify($pin, (string) $row['admin_pin_hash'])) {
            self::resetFailures($userId);
            return ['ok' => true];
        }

        // Wrong PIN: count it in the database so it survives a restart.
        $failures++;
        $db = getDB();
        if ($failures === 1) {
            $stmt = $db->prepare('UPDATE sys_users SET admin_pin_failures = ?, admin_pin_failures_at = NOW() WHERE id = ?');
            $stmt->execute([$failures, $userId]);
        } else {
            $stmt = $db->prepare('UPDATE sys_users SET admin_pin_failures = ? WHERE id = ?');
            $stmt->execute([$failures, $userId]);
        }
        if (function_exists('logLoginAttempt')) {
            logLoginAttempt($clientIp, (string) $row['username'], 'FAILED');
        }

        if ($failures >= self::MAX_FAILURES) {
            // Third wrong PIN in the window: seal the vault. Even if the
            // seal call itself fails, the caller destroys the session and
            // the counter stays at 3, so no more PIN tries are possible
            // until the window expires.
            $seal = VaultSealService::seal();
            $sealNote = $seal['ok'] ? 'triggered' : 'FAILED: ' . $seal['error'];
            if (function_exists('writeAuditLog')) {
                writeAuditLog('security', 'sys_users', (string) $userId, "Admin PIN entered wrong 3 times for '{$row['username']}'; OpenBao seal {$sealNote}.", 'admin_pin_lockout', $userId);
            }
            error_log('AdminPinService: 3 wrong admin PINs for user ' . $userId . '; OpenBao seal ' . $sealNote);
            return ['ok' => false, 'sealed' => true, 'seal_ok' => $seal['ok']];
        }

        return ['ok' => false, 'failures' => $failures];
    }

    public static function resetFailures(int $userId): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE sys_users SET admin_pin_failures = 0, admin_pin_failures_at = NULL WHERE id = ?');
        $stmt->execute([$userId]);
    }

    /** Sudo-mode: is the admin PIN still fresh (within the window)? */
    public static function sudoFresh(): bool
    {
        $at = (int) ($_SESSION['admin_pin_at'] ?? 0);
        return $at > 0 && (time() - $at) < self::SUDO_WINDOW_SECONDS;
    }

    public static function markSudoFresh(): void
    {
        $_SESSION['admin_pin_at'] = time();
    }
}
