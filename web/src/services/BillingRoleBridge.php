<?php
/**
 * Billing roles (Layer 1): the core side of "a plan unlocks modules, and a
 * subscriber sees only their own records".
 *
 * Who is billing-scoped: a signed-in user who is NOT an admin, NOT a guest,
 * NOT a remote-limited admin, and whose ACTIVE subscription unlocks at least
 * one of the seven plan modules (Billing::userPlanFeatures). Admins and
 * reseller admins are unaffected — their existing scoping stays as-is.
 *
 * What scoping means:
 *   - Lists (Users, Extensions, the seven modules, CDR agent filter) show
 *     only the viewer's own row / rows owned by their extension.
 *   - A billing user may only touch rows whose owner_extension is their own
 *     extension. NULL owner = admin-owned/global: admins only.
 *   - Meetings use the existing host_user_id; boss-secretary uses the
 *     existing boss_extension. No new column for either.
 *
 * Every method is safe to call with the billing plugin absent, disabled, or
 * on an older core: no plan features resolve, so nobody is scoped and no
 * behaviour changes (fail closed for grants, fail open for nothing — a
 * missing plugin simply grants nothing).
 */
require_once __DIR__ . '/BillingBridge.php';

class BillingRoleBridge
{
    /** Tables that carry owner_extension (Layer 1 migration). */
    private const OWNER_TABLES = [
        'pbx_time_conditions',
        'pbx_time_groups',
        'pbx_ivrs',
        'pbx_queues',
        'pbx_conferences',
        'pbx_ring_groups',
        // 1.75.84: a widget can place calls and a card publishes a page, so both are
        // owned rows once a plan user can have them.
        'pbx_web_widgets',
        'pbx_business_cards',
    ];

    /** @var array<int, array<string, mixed>|null> sys_users row per user id */
    private static array $viewerRows = [];
    /** @var array<int, list<string>> plan modules per user id */
    private static array $planFeatures = [];
    /** @var array<string, bool> owner-column presence per table */
    private static array $ownerColumns = [];

    /** Clears the per-request caches (tests change a user's plan mid-run). */
    public static function resetCache(): void
    {
        self::$viewerRows = [];
        self::$planFeatures = [];
        self::$ownerColumns = [];
    }

    /** The signed-in user's sys_users row (id, role, extension, is_guest). */
    public static function viewerRow(): ?array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        if (!array_key_exists($userId, self::$viewerRows)) {
            try {
                $stmt = getDB()->prepare('SELECT id, role, extension, is_guest FROM sys_users WHERE id = ?');
                $stmt->execute([$userId]);
                self::$viewerRows[$userId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                self::$viewerRows[$userId] = null;
            }
        }
        return self::$viewerRows[$userId];
    }

    /** The modules the user's ACTIVE plan unlocks (empty when none). */
    public static function planFeaturesForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (!array_key_exists($userId, self::$planFeatures)) {
            $features = [];
            try {
                if (BillingBridge::load()) {
                    $features = Billing::userPlanFeatures($userId);
                }
            } catch (\Throwable $e) {
                $features = [];
            }
            // userPlanFeatures() is declared : array, and the catch above sets [].
            self::$planFeatures[$userId] = array_values($features);
        }
        return self::$planFeatures[$userId];
    }

    /**
     * Does the signed-in user's plan grant $module (in addition to their role
     * permissions, never instead of them)? Admins keep everything as before
     * through the role path, so they never need this; guests and
     * remote-limited admins never get it. A read-only viewer may look but
     * never edit or delete.
     */
    public static function planGrantsModule(string $module, string $action = 'access'): bool
    {
        $row = self::viewerRow();
        if ($row === null) {
            return false;
        }
        if (($row['role'] ?? '') === 'admin' || !empty($row['is_guest'])) {
            return false;
        }
        if (function_exists('isRemoteLimitedAdmin') && isRemoteLimitedAdmin()) {
            return false;
        }
        if (($row['role'] ?? '') === 'read_only_admin' && ($action === 'edit' || $action === 'delete')) {
            return false;
        }
        return in_array($module, self::planFeaturesForUser((int) $row['id']), true);
    }

    /** Is the signed-in user a billing-role user (plan features, not admin/guest)? */
    public static function isBillingScoped(): bool
    {
        $row = self::viewerRow();
        if ($row === null) {
            return false;
        }
        if (($row['role'] ?? '') === 'admin' || !empty($row['is_guest'])) {
            return false;
        }
        if (function_exists('isRemoteLimitedAdmin') && isRemoteLimitedAdmin()) {
            return false;
        }
        return self::planFeaturesForUser((int) $row['id']) !== [];
    }

    /** The billing-scoped viewer's user id, or 0 when nobody is scoped. */
    public static function scopedUserId(): int
    {
        return self::isBillingScoped() ? (int) (self::viewerRow()['id'] ?? 0) : 0;
    }

    /** The billing-scoped viewer's extension ('' when they have none). */
    public static function scopedExtension(): string
    {
        if (!self::isBillingScoped()) {
            return '';
        }
        return trim((string) (self::viewerRow()['extension'] ?? ''));
    }

    /**
     * The owner filter for list queries: null = unscoped (everyone, as
     * before); a string (possibly '') = only rows owned by that extension.
     * An empty extension matches nothing — a plan user without an extension
     * sees empty lists, never everyone else's rows.
     */
    public static function listOwnerExtension(): ?string
    {
        return self::isBillingScoped() ? self::scopedExtension() : null;
    }

    /** The owner to stamp on a row the viewer creates (null = admin-owned). */
    public static function creatorOwnerExtension(): ?string
    {
        if (!self::isBillingScoped()) {
            return null;
        }
        return self::scopedExtension();
    }

    /** Throws unless every time group id in $ids belongs to the viewer. */
    public static function assertTimeGroupsOwned(array $ids): void
    {
        foreach ($ids as $id) {
            self::assertMayTouchRow('pbx_time_groups', (int) $id);
        }
    }

    /** Throws unless the viewer may use $bossExtension as a boss (their own). */
    public static function assertMayTouchBossExtension(string $bossExtension): void
    {
        if (!self::isBillingScoped()) {
            return;
        }
        if ($bossExtension === '' || $bossExtension !== self::scopedExtension()) {
            throw new \Exception(t('billing_role.err_not_yours'));
        }
    }

    /** May the viewer touch a row owned by $owner (null = admin-owned)? */
    public static function mayTouchOwner(?string $owner): bool
    {
        if (!self::isBillingScoped()) {
            return true;
        }
        return $owner !== null && $owner !== '' && $owner === self::scopedExtension();
    }

    /** May the viewer touch the sys_users row $userId (Extensions page)? */
    public static function mayTouchUserId(int $userId): bool
    {
        if (!self::isBillingScoped()) {
            return true;
        }
        return $userId > 0 && $userId === self::scopedUserId();
    }

    /** Throws unless the viewer may touch the sys_users row $userId. */
    public static function assertMayTouchUserId(int $userId): void
    {
        if (!self::mayTouchUserId($userId)) {
            throw new \Exception(t('billing_role.err_not_yours'));
        }
    }

    /** Throws unless the viewer may create a row (a plan user needs an extension). */
    public static function assertMayCreate(): void
    {
        if (self::isBillingScoped() && self::scopedExtension() === '') {
            throw new \Exception(t('blocked.err_no_extension'));
        }
    }

    /**
     * Throws unless the viewer may touch the row $id in $table. Unscoped
     * viewers (admins, role users) pass; a billing user passes only when the
     * row's owner_extension is their own extension. A missing row, a
     * NULL-owned (admin) row, or a table without the column yet all deny.
     */
    public static function assertMayTouchRow(string $table, int $id): void
    {
        if (!in_array($table, self::OWNER_TABLES, true)) {
            throw new \InvalidArgumentException('Unknown owner table.');
        }
        if (!self::isBillingScoped()) {
            return;
        }
        $owner = null;
        $found = false;
        try {
            if (self::ownerColumnExists($table)) {
                $stmt = getDB()->prepare("SELECT owner_extension FROM {$table} WHERE id = ?");
                $stmt->execute([$id]);
                $owner = $stmt->fetchColumn();
                $found = $owner !== false;
            }
        } catch (\Throwable $e) {
            $found = false;
        }
        // Past the short-circuit $found is true, so $owner is the column's value: a string,
        // or null for a row that has no owner.
        if (!$found || !self::mayTouchOwner($owner === null ? null : (string) $owner)) {
            throw new \Exception(t('billing_role.err_not_yours'));
        }
    }

    /** Throws unless every extension in $extensions is the viewer's own. */
    public static function assertMembersSelfOnly(array $extensions): void
    {
        if (!self::isBillingScoped()) {
            return;
        }
        $own = self::scopedExtension();
        foreach ($extensions as $ext) {
            $ext = (string) preg_replace('/\D/', '', (string) $ext);
            if ($ext === '' || $ext !== $own) {
                throw new \Exception(t('billing_role.err_not_yours'));
            }
        }
    }

    /** Throws unless the viewer hosts the meeting $meetingId. */
    public static function assertMayTouchMeeting(int $meetingId): void
    {
        if (!self::isBillingScoped()) {
            return;
        }
        $host = null;
        $found = false;
        try {
            $stmt = getDB()->prepare('SELECT host_user_id FROM pbx_meetings WHERE id = ?');
            $stmt->execute([$meetingId]);
            $host = $stmt->fetchColumn();
            $found = $host !== false;
        } catch (\Throwable $e) {
            $found = false;
        }
        if (!$found || (int) $host !== self::scopedUserId()) {
            throw new \Exception(t('billing_role.err_not_yours'));
        }
    }

    /** May the viewer see live members of the conference room $roomNumber? */
    public static function mayTouchRoom(string $roomNumber): bool
    {
        if (!self::isBillingScoped()) {
            return true;
        }
        $roomNumber = (string) preg_replace('/\D/', '', $roomNumber);
        if ($roomNumber === '') {
            return false;
        }
        try {
            if (!self::ownerColumnExists('pbx_conferences')) {
                return false;
            }
            $stmt = getDB()->prepare('SELECT owner_extension FROM pbx_conferences WHERE room_number = ?');
            $stmt->execute([$roomNumber]);
            $owner = $stmt->fetchColumn();
            return $owner !== false && self::mayTouchOwner($owner === null ? null : (string) $owner);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Does $table have the owner_extension column (1.75.61 migration)? */
    public static function ownerColumnExists(string $table): bool
    {
        if (!in_array($table, self::OWNER_TABLES, true)) {
            return false;
        }
        if (!array_key_exists($table, self::$ownerColumns)) {
            try {
                $stmt = getDB()->query("SHOW COLUMNS FROM {$table} LIKE 'owner_extension'");
                self::$ownerColumns[$table] = $stmt !== false && $stmt->fetch() !== false;
            } catch (\Throwable $e) {
                self::$ownerColumns[$table] = false;
            }
        }
        return self::$ownerColumns[$table];
    }
}
