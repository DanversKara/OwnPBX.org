<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../repositories/InboundBlacklistRepository.php';

/**
 * Service managing Inbound Caller ID Blacklist & Spam Blocking.
 */
class InboundBlacklistService
{
    public const VALID_ACTIONS = ['busy', 'congestion', 'drop', 'announcement'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(): array
    {
        return InboundBlacklistRepository::allOrdered();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getById(int $id): ?array
    {
        return InboundBlacklistRepository::findById($id);
    }

    /**
     * Normalize caller ID for storage / matching.
     */
    public static function normalizeNumber(string $raw): string
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '_')) {
            // Pattern format: keep dialplan pattern characters
            return '_' . preg_replace('/[^0-9XZN.!*#+-]/', '', substr($trimmed, 1));
        }
        // Standard number: keep + and digits
        return preg_replace('/[^0-9+*#]/', '', $trimmed);
    }

    /**
     * Find an existing entry matching $cleanNumber or its E.164 +/- variant
     * so '+90850...' and '90850...' cannot collide on the same AstDB key.
     *
     * @return array<string, mixed>|null
     */
    private static function findExistingOrVariant(string $cleanNumber, ?string $ownerExtension = null): ?array
    {
        $existing = InboundBlacklistRepository::findByNumber($cleanNumber, $ownerExtension);
        if ($existing !== null) {
            return $existing;
        }
        if (!str_starts_with($cleanNumber, '_')) {
            $alt = str_starts_with($cleanNumber, '+') ? substr($cleanNumber, 1) : '+' . $cleanNumber;
            if ($alt !== '' && $alt !== '+') {
                return InboundBlacklistRepository::findByNumber($alt, $ownerExtension);
            }
        }
        return null;
    }

    /**
     * Add a caller number or pattern to blacklist.
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    public static function add(string $number, ?string $title = null, string $action = 'busy', bool $isActive = true, bool $deferSync = false, ?string $ownerExtension = null): array
    {
        $cleanNumber = self::normalizeNumber($number);
        if ($cleanNumber === '' || $cleanNumber === '_') {
            return ['success' => false, 'error' => t('blacklist.err_invalid_number', 'Geçersiz arayan numara.')];
        }

        if (!in_array($action, self::VALID_ACTIONS, true)) {
            $action = 'busy';
        }

        $existing = self::findExistingOrVariant($cleanNumber, $ownerExtension);
        if ($existing !== null) {
            return ['success' => false, 'error' => t('blacklist.err_duplicate', 'Bu numara zaten kara listede kayıtlı.')];
        }

        $id = InboundBlacklistRepository::create([
            'number' => $cleanNumber,
            'title' => $title !== null ? trim($title) : null,
            'block_action' => $action,
            'owner_extension' => $ownerExtension !== null && $ownerExtension !== '' ? preg_replace('/[^0-9]/', '', $ownerExtension) : null,
            'is_active' => $isActive ? 1 : 0,
        ]);

        if (!$deferSync) {
            self::syncAstDb($cleanNumber, $action, $isActive, $ownerExtension);
            if (function_exists('markPendingSync')) {
                markPendingSync('inbound_dialplan', 'blacklist', $cleanNumber, "Inbound blacklist: {$cleanNumber}", 'create', $_SESSION['user_id'] ?? null);
            }
        }

        return ['success' => true, 'id' => $id];
    }

    /**
     * Update an existing blacklist entry.
     *
     * @return array{success: bool, error?: string}
     */
    public static function update(int $id, string $number, ?string $title = null, string $action = 'busy', bool $isActive = true): array
    {
        $entry = InboundBlacklistRepository::findById($id);
        if ($entry === null) {
            return ['success' => false, 'error' => t('blacklist.err_not_found', 'Kayıt bulunamadı.')];
        }

        $cleanNumber = self::normalizeNumber($number);
        if ($cleanNumber === '' || $cleanNumber === '_') {
            return ['success' => false, 'error' => t('blacklist.err_invalid_number', 'Geçersiz arayan numara.')];
        }

        if (!in_array($action, self::VALID_ACTIONS, true)) {
            $action = 'busy';
        }

        $duplicate = self::findExistingOrVariant($cleanNumber, (string) ($entry['owner_extension'] ?? '') !== '' ? (string) $entry['owner_extension'] : null);
        if ($duplicate !== null && (int)$duplicate['id'] !== $id) {
            return ['success' => false, 'error' => t('blacklist.err_duplicate', 'Bu numara zaten kara listede kayıtlı.')];
        }

        // Clean up old AstDB key if number changed
        if ($entry['number'] !== $cleanNumber) {
            self::removeFromAstDb($entry['number'], (string) ($entry['owner_extension'] ?? ''));
        }

        InboundBlacklistRepository::update($id, [
            'number' => $cleanNumber,
            'title' => $title !== null ? trim($title) : null,
            'block_action' => $action,
            'is_active' => $isActive ? 1 : 0,
        ]);

        self::syncAstDb($cleanNumber, $action, $isActive, (string) ($entry['owner_extension'] ?? ''));
        if (function_exists('markPendingSync')) {
            markPendingSync('inbound_dialplan', 'blacklist', $cleanNumber, "Inbound blacklist: {$cleanNumber}", 'update', $_SESSION['user_id'] ?? null);
        }

        return ['success' => true];
    }

    /**
     * Delete an entry.
     */
    public static function delete(int $id): bool
    {
        $entry = InboundBlacklistRepository::findById($id);
        if ($entry !== null) {
            self::removeFromAstDb($entry['number'], (string) ($entry['owner_extension'] ?? ''));
            InboundBlacklistRepository::delete($id);
            if (function_exists('markPendingSync')) {
                markPendingSync('inbound_dialplan', 'blacklist', (string)$entry['number'], "Inbound blacklist: {$entry['number']} (deleted)", 'delete', $_SESSION['user_id'] ?? null);
            }
            return true;
        }
        return false;
    }

    /**
     * Toggle active state.
     */
    public static function toggleActive(int $id): bool
    {
        $entry = InboundBlacklistRepository::findById($id);
        if ($entry === null) {
            return false;
        }

        InboundBlacklistRepository::toggleActive($id);
        $updated = InboundBlacklistRepository::findById($id);
        $newActive = (bool)($updated['is_active'] ?? 0);

        self::syncAstDb($entry['number'], (string)$entry['block_action'], $newActive, (string) ($entry['owner_extension'] ?? ''));
        if (function_exists('markPendingSync')) {
            markPendingSync('inbound_dialplan', 'blacklist', (string)$entry['number'], "Inbound blacklist: {$entry['number']} (status changed)", 'update', $_SESSION['user_id'] ?? null);
        }

        return true;
    }

    // ------------------------------------------------- a user's own blocked callers

    /**
     * May this extension block callers (*86 and the Blocked callers page)? An administrator
     * can take it away per user; a user who is not allowed keeps the numbers already blocked
     * but cannot add more, and *86 does nothing on their calls.
     */
    public static function userMayBlock(string $extension): bool
    {
        $ext = preg_replace('/\D+/', '', $extension) ?? '';
        if ($ext === '') {
            return false;
        }
        try {
            $q = getDB()->prepare('SELECT may_block_callers FROM sys_users WHERE extension = ? LIMIT 1');
            $q->execute([$ext]);
            $v = $q->fetchColumn();
            return $v === false ? true : (bool) $v;   // before the migration: allowed
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** One person's own blocked numbers. Nobody else's list is ever visible to them. */
    public static function listForUser(string $extension): array
    {
        $ext = preg_replace('/\D+/', '', $extension) ?? '';
        return $ext === '' ? [] : InboundBlacklistRepository::findByOwner($ext);
    }

    /**
     * Block a caller for this extension only.
     * @return array{success: bool, id?: int, error?: string}
     */
    public static function addForUser(string $extension, string $number, ?string $title = null, string $action = 'announcement'): array
    {
        $ext = preg_replace('/\D+/', '', $extension) ?? '';
        if ($ext === '') {
            return ['success' => false, 'error' => t('blocked.err_no_extension', 'No extension.')];
        }
        if (!self::userMayBlock($ext)) {
            return ['success' => false, 'error' => t('blocked.err_not_allowed', 'An administrator has switched call blocking off for you.')];
        }
        return self::add($number, $title, $action, true, false, $ext);
    }

    /** Unblock one of this extension's own numbers; another person's entry is never touched. */
    public static function deleteForUser(string $extension, int $id): bool
    {
        $ext = preg_replace('/\D+/', '', $extension) ?? '';
        $entry = InboundBlacklistRepository::findById($id);
        if ($ext === '' || $entry === null) {
            return false;
        }
        if ((string) ($entry['owner_extension'] ?? '') !== $ext) {
            return false;
        }
        return self::delete($id);
    }

    /**
     * Sync single entry to AstDB.
     */
    public static function syncAstDb(string $number, string $action, bool $isActive, ?string $ownerExtension = null): void
    {
        if (str_starts_with($number, '_')) {
            // Dialplan pattern, handled via generated extensions in dialplan
            return;
        }
        // A number blocked by a user stops at that person's extension only (*86), so it lives
        // in a family of its own. Only what an administrator adds with no owner is PBX-wide.
        $owner = preg_replace('/\D+/', '', (string) $ownerExtension) ?? '';
        if ($owner !== '') {
            self::syncUserAstDb($owner, $number, $action, $isActive);
            return;
        }

        $cleanKey = preg_replace('/[^0-9]/', '', $number);
        if ($cleanKey === '') {
            return;
        }

        if ($isActive) {
            AsteriskHelper::execCLI("database put blacklist {$cleanKey} 1");
            AsteriskHelper::execCLI("database put blacklist_action {$cleanKey} {$action}");
        } else {
            AsteriskHelper::execCLI("database del blacklist {$cleanKey}");
            AsteriskHelper::execCLI("database del blacklist_action {$cleanKey}");
        }
    }

    /** One person's own block (*86 or their Blocked callers page): blacklist_u/<ext>/<number>. */
    public static function syncUserAstDb(string $ownerExtension, string $number, string $action, bool $isActive): void
    {
        $owner = preg_replace('/\D+/', '', $ownerExtension) ?? '';
        $cleanKey = preg_replace('/[^0-9]/', '', $number) ?? '';
        if ($owner === '' || $cleanKey === '') {
            return;
        }
        if ($isActive) {
            AsteriskHelper::execCLI("database put blacklist_u/{$owner} {$cleanKey} 1");
            AsteriskHelper::execCLI("database put blacklist_u_action/{$owner} {$cleanKey} {$action}");
        } else {
            AsteriskHelper::execCLI("database del blacklist_u/{$owner} {$cleanKey}");
            AsteriskHelper::execCLI("database del blacklist_u_action/{$owner} {$cleanKey}");
        }
    }

    /**
     * Remove entry from AstDB.
     */
    public static function removeFromAstDb(string $number, ?string $ownerExtension = null): void
    {
        $cleanKey = preg_replace('/[^0-9]/', '', $number);
        if ($cleanKey === '') {
            return;
        }
        $owner = preg_replace('/\D+/', '', (string) $ownerExtension) ?? '';
        if ($owner !== '') {
            AsteriskHelper::execCLI("database del blacklist_u/{$owner} {$cleanKey}");
            AsteriskHelper::execCLI("database del blacklist_u_action/{$owner} {$cleanKey}");
            return;
        }
        AsteriskHelper::execCLI("database del blacklist {$cleanKey}");
        AsteriskHelper::execCLI("database del blacklist_action {$cleanKey}");
    }

    /**
     * Full resync of all active blacklist numbers into AstDB.
     */
    public static function rebuildAstDb(): void
    {
        // First clear both families: PBX-wide and the users' own lists.
        AsteriskHelper::execCLI('database deltree blacklist');
        AsteriskHelper::execCLI('database deltree blacklist_action');
        AsteriskHelper::execCLI('database deltree blacklist_u');
        AsteriskHelper::execCLI('database deltree blacklist_u_action');

        $active = InboundBlacklistRepository::findActive();
        foreach ($active as $entry) {
            $num = (string)$entry['number'];
            if (str_starts_with($num, '_')) {
                continue;
            }
            $cleanKey = preg_replace('/[^0-9]/', '', $num);
            if ($cleanKey === '') {
                continue;
            }
            $action = (string)($entry['block_action'] ?? 'busy');
            $owner = preg_replace('/\D+/', '', (string)($entry['owner_extension'] ?? '')) ?? '';
            if ($owner !== '') {
                AsteriskHelper::execCLI("database put blacklist_u/{$owner} {$cleanKey} 1");
                AsteriskHelper::execCLI("database put blacklist_u_action/{$owner} {$cleanKey} {$action}");
                continue;
            }
            AsteriskHelper::execCLI("database put blacklist {$cleanKey} 1");
            AsteriskHelper::execCLI("database put blacklist_action {$cleanKey} {$action}");
        }
    }

    /**
     * Find the last incoming caller number that rang a specific extension.
     */
    public static function getLastCallerForExtension(string $extension): ?string
    {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT src FROM asteriskcdr 
             WHERE (dst = ? OR did = ? OR dstchannel LIKE ?) AND src != '' AND src != ?
             ORDER BY calldate DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$extension, $extension, "PJSIP/{$extension}-%", $extension]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['src'] : null;
    }
}
