<?php

declare(strict_types=1);

/**
 * Database access for Caller Rules (first-match-wins inbound routing).
 * Table: caller_rules.
 */
class CallerRuleRepository extends BaseRepository
{
    protected static string $table = 'caller_rules';

    /**
     * All rules, newest first (portal list).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allOrdered(): array
    {
        return static::findAll('id DESC');
    }

    /**
     * Active rules in match order: rules for one specific number first,
     * then rules for any number; within each group lowest sort_order,
     * then oldest first. The dialplan checks them in this order and the
     * first match wins.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findActiveOrdered(): array
    {
        return static::findWhere('is_active = 1', [], '(did_number IS NULL) ASC, sort_order ASC, id ASC');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $stmt = static::db()->prepare(
            'INSERT INTO caller_rules (did_number, caller_number, dest_type, dest_id, owner_extension, sort_order, is_active, hit_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())'
        );
        $stmt->execute([
            $data['did_number'] ?? null,
            $data['caller_number'] ?? null,
            $data['dest_type'] ?? 'extension',
            $data['dest_id'] ?? '',
            $data['owner_extension'] ?? null,
            (int)($data['sort_order'] ?? 0),
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);
        return (int)static::db()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateById(int $id, array $data): bool
    {
        $stmt = static::db()->prepare(
            'UPDATE caller_rules SET did_number = ?, caller_number = ?, dest_type = ?, dest_id = ?, owner_extension = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?'
        );
        return $stmt->execute([
            $data['did_number'] ?? null,
            $data['caller_number'] ?? null,
            $data['dest_type'] ?? 'extension',
            $data['dest_id'] ?? '',
            $data['owner_extension'] ?? null,
            (int)($data['sort_order'] ?? 0),
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
            $id,
        ]);
    }

    public static function deleteById(int $id): bool
    {
        $stmt = static::db()->prepare('DELETE FROM caller_rules WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function incrementHit(int $id): bool
    {
        $stmt = static::db()->prepare('UPDATE caller_rules SET hit_count = hit_count + 1 WHERE id = ?');
        return $stmt->execute([$id]);
    }
}
