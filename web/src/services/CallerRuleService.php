<?php

declare(strict_types=1);

require_once __DIR__ . '/../repositories/CallerRuleRepository.php';

/**
 * Caller Rules: first-match-wins inbound routing.
 *
 * A rule matches when the called number (did_number) and the caller
 * number (caller_number) both match; NULL on either side means "any".
 * Rules for one specific number are checked before rules for any number
 * (see CallerRuleRepository::findActiveOrdered); the first match wins
 * and routes the call to dest_type/dest_id.
 */
class CallerRuleService
{
    /** Destinations a caller rule may route to. */
    public const DEST_TYPES = ['extension', 'voicemail', 'ring_group', 'hangup', 'announcement', 'ivr', 'queue'];

    /** Destination types that need no target value. */
    public const DEST_NO_TARGET = ['hangup'];

    public static function listAll(): array
    {
        return CallerRuleRepository::allOrdered();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getById(int $id): ?array
    {
        return CallerRuleRepository::findById($id);
    }

    /**
     * Normalize a number for storage / matching: digits only, or NULL
     * for "any" (empty input).
     */
    public static function normalizeNumber(?string $raw): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', trim((string)$raw));
        return ($clean === '' || $clean === null) ? null : $clean;
    }

    /**
     * @return array{success: bool, id?: int, error?: string}
     */
    public static function add(?string $did, ?string $caller, string $destType, string $destId, ?string $ownerExtension = null, int $sortOrder = 0, bool $isActive = true): array
    {
        if (!in_array($destType, self::DEST_TYPES, true)) {
            return ['success' => false, 'error' => t('caller_rules.err_dest_type', 'Invalid destination.')];
        }
        if (!in_array($destType, self::DEST_NO_TARGET, true) && trim($destId) === '') {
            return ['success' => false, 'error' => t('caller_rules.err_dest_id', 'A destination is required.')];
        }

        $id = CallerRuleRepository::create([
            'did_number' => self::normalizeNumber($did),
            'caller_number' => self::normalizeNumber($caller),
            'dest_type' => $destType,
            'dest_id' => trim($destId),
            'owner_extension' => $ownerExtension !== null && $ownerExtension !== '' ? preg_replace('/[^0-9]/', '', $ownerExtension) : null,
            'sort_order' => $sortOrder,
            'is_active' => $isActive ? 1 : 0,
        ]);

        if (function_exists('markPendingSync')) {
            markPendingSync('inbound_dialplan', 'caller_rules', (string)$id, "Caller rule #{$id}", 'create', $_SESSION['user_id'] ?? null);
        }

        return ['success' => true, 'id' => $id];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public static function update(int $id, ?string $did, ?string $caller, string $destType, string $destId, ?string $ownerExtension = null, int $sortOrder = 0, bool $isActive = true): array
    {
        $existing = self::getById($id);
        if ($existing === null) {
            return ['success' => false, 'error' => t('caller_rules.err_not_found', 'Rule not found.')];
        }
        if (!in_array($destType, self::DEST_TYPES, true)) {
            return ['success' => false, 'error' => t('caller_rules.err_dest_type', 'Invalid destination.')];
        }
        if (!in_array($destType, self::DEST_NO_TARGET, true) && trim($destId) === '') {
            return ['success' => false, 'error' => t('caller_rules.err_dest_id', 'A destination is required.')];
        }

        CallerRuleRepository::updateById($id, [
            'did_number' => self::normalizeNumber($did),
            'caller_number' => self::normalizeNumber($caller),
            'dest_type' => $destType,
            'dest_id' => trim($destId),
            'owner_extension' => $ownerExtension !== null && $ownerExtension !== '' ? preg_replace('/[^0-9]/', '', $ownerExtension) : null,
            'sort_order' => $sortOrder,
            'is_active' => $isActive ? 1 : 0,
        ]);

        if (function_exists('markPendingSync')) {
            markPendingSync('inbound_dialplan', 'caller_rules', (string)$id, "Caller rule #{$id}", 'update', $_SESSION['user_id'] ?? null);
        }

        return ['success' => true];
    }

    public static function delete(int $id): bool
    {
        $ok = CallerRuleRepository::deleteById($id);
        if ($ok && function_exists('markPendingSync')) {
            markPendingSync('inbound_dialplan', 'caller_rules', (string)$id, "Caller rule #{$id} (deleted)", 'delete', $_SESSION['user_id'] ?? null);
        }
        return $ok;
    }

    public static function toggleActive(int $id): bool
    {
        $row = self::getById($id);
        if ($row === null) {
            return false;
        }
        $new = empty($row['is_active']) ? 1 : 0;
        CallerRuleRepository::updateById($id, [
            'did_number' => $row['did_number'],
            'caller_number' => $row['caller_number'],
            'dest_type' => $row['dest_type'],
            'dest_id' => $row['dest_id'],
            'owner_extension' => $row['owner_extension'],
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'is_active' => $new,
        ]);
        if (function_exists('markPendingSync')) {
            markPendingSync('inbound_dialplan', 'caller_rules', (string)$id, "Caller rule #{$id} (status changed)", 'update', $_SESSION['user_id'] ?? null);
        }
        return true;
    }
}
