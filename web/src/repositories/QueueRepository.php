<?php

/**
 * Database access for the Queues page.
 * Tables: pbx_queues, sys_users (agents), pbx_moh_classes.
 * Used by QueueController.
 */
class QueueRepository extends BaseRepository
{
    protected static string $table = 'pbx_queues';

    /**
     * @param string|null $ownerExtension billing-role scope: only this
     *   extension's queues (null = everyone, as before). Without the owner
     *   column yet, a scoped viewer sees nothing (fail closed).
     */
    public static function allOrderedById(?string $ownerExtension = null): array
    {
        if ($ownerExtension !== null) {
            if (!BillingRoleBridge::ownerColumnExists('pbx_queues')) {
                return [];
            }
            $stmt = static::db()->prepare('SELECT * FROM pbx_queues WHERE owner_extension = ? ORDER BY id ASC');
            $stmt->execute([$ownerExtension]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return static::findAll('id ASC');
    }

    /**
     * @param string|null $onlyExtension billing-role scope: only this
     *   extension (null = everyone, as before).
     */
    public static function extensionAgents(?string $onlyExtension = null): array
    {
        if ($onlyExtension !== null) {
            $stmt = static::db()->prepare(
                "SELECT id, username, full_name, extension, role FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND extension = ? ORDER BY role ASC, extension ASC"
            );
            $stmt->execute([$onlyExtension]);
            return $stmt->fetchAll();
        }
        return static::db()->query(
            "SELECT id, username, full_name, extension, role FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY role ASC, extension ASC"
        )->fetchAll();
    }

    /**
     * Roles shown in the "Agents" list of the queue form.
     */
    public const AGENT_ROLES = ['cc_agent', 'user', 'cc_manager', 'admin'];

    /**
     * The "Agents" list of the queue form: extensions whose role may take queue calls —
     * fax lines, viewer/read-only accounts and inactive users cannot be made queue members.
     * @param string|null $onlyExtension billing-role scope: only this extension.
     */
    public static function queueAgents(?string $onlyExtension = null): array
    {
        $in = implode(',', array_fill(0, count(self::AGENT_ROLES), '?'));
        $params = self::AGENT_ROLES;
        $extra = '';
        if ($onlyExtension !== null) {
            $extra = ' AND extension = ?';
            $params[] = $onlyExtension;
        }
        $stmt = static::db()->prepare(
            "SELECT id, username, full_name, extension, role FROM sys_users WHERE role IN ({$in}) AND (extension_type IS NULL OR extension_type != 'fax') AND (is_active IS NULL OR is_active = 1) AND extension IS NOT NULL AND extension != ''{$extra} ORDER BY extension ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Roles shown in the "Queue managers" list of the queue form.
     */
    public const MANAGER_ROLES = ['cc_manager', 'admin'];

    /**
     * The "Queue managers" list of the queue form: the queue manager and admin roles.
     * @param string|null $onlyExtension billing-role scope: only this extension.
     */
    public static function queueManagers(?string $onlyExtension = null): array
    {
        $in = implode(',', array_fill(0, count(self::MANAGER_ROLES), '?'));
        $params = self::MANAGER_ROLES;
        $extra = '';
        if ($onlyExtension !== null) {
            $extra = ' AND extension = ?';
            $params[] = $onlyExtension;
        }
        $stmt = static::db()->prepare(
            "SELECT id, username, full_name, extension, role FROM sys_users WHERE role IN ({$in}) AND (extension_type IS NULL OR extension_type != 'fax') AND (is_active IS NULL OR is_active = 1) AND extension IS NOT NULL AND extension != ''{$extra} ORDER BY extension ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Users ALREADY assigned to a queue although their role is not on the list
     * (e.g. admins made managers before the role filter existed). The form
     * shows them only when their queue is opened; if they were not shown, they
     * would silently drop off the assignment when the queue is saved.
     * @param string $jsonColumn 'members_json' | 'supervisors_json'
     * @param string[] $roles the list's normal roles (e.g. AGENT_ROLES | MANAGER_ROLES)
     * @param int[]|null $queueIds only these queues (null = all). A billing
     *   user passes only their own queue ids, so nobody else's members leak.
     */
    public static function assignedOutsideRole(string $jsonColumn, array $roles, ?array $queueIds = null): array
    {
        $exts = [];
        $where = '';
        $params = [];
        if ($queueIds !== null) {
            $queueIds = array_values(array_unique(array_map('intval', $queueIds)));
            if ($queueIds === []) {
                return [];
            }
            $where = ' WHERE id IN (' . implode(',', array_fill(0, count($queueIds), '?')) . ')';
            $params = $queueIds;
        }
        $stmt = static::db()->prepare("SELECT {$jsonColumn} AS j, supervisor_extension FROM pbx_queues{$where}");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (json_decode($row['j'] ?? '[]', true) ?: [] as $e) $exts[] = (string)$e;
            if ($jsonColumn === 'supervisors_json' && !empty($row['supervisor_extension'])) $exts[] = (string)$row['supervisor_extension'];
        }
        $exts = array_values(array_unique(array_filter($exts, 'strlen')));
        if (empty($exts)) return [];
        $in = implode(',', array_fill(0, count($exts), '?'));
        $role_in = implode(',', array_fill(0, count($roles), '?'));
        $stmt = static::db()->prepare("SELECT id, username, full_name, extension, role FROM sys_users WHERE extension IN ({$in}) AND role NOT IN ({$role_in}) AND (extension_type IS NULL OR extension_type != 'fax') AND (is_active IS NULL OR is_active = 1) ORDER BY extension ASC");
        $stmt->execute(array_merge($exts, $roles));
        return $stmt->fetchAll();
    }

    public static function activeMohClasses(): array
    {
        $classes = static::db()->query("SELECT name FROM pbx_moh_classes WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        return !empty($classes) ? $classes : ['default', 'custom'];
    }

    public static function supervisorName(string $extension): string
    {
        return DBHelper::fetchColumn("SELECT full_name FROM sys_users WHERE extension = ?", [$extension]) ?: $extension;
    }
}
