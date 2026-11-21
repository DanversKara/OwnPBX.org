<?php
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

/**
 * Database access for the Time conditions page.
 * Tables: pbx_time_conditions, pbx_time_groups.
 * Used by TimeConditionController.
 */
class TimeConditionRepository extends BaseRepository
{
    protected static string $table = 'pbx_time_conditions';

    /**
     * @param string|null $ownerExtension billing-role scope: only this
     *   extension's conditions (null = everyone, as before). Without the
     *   owner column yet, a scoped viewer sees nothing (fail closed).
     */
    public static function allWithGroupInfo(?string $ownerExtension = null): array
    {
        if ($ownerExtension !== null) {
            if (!BillingRoleBridge::ownerColumnExists('pbx_time_conditions')) {
                return [];
            }
            $stmt = static::db()->prepare(
                "SELECT tc.*, tg.title AS group_title, tg.time_start, tg.time_end
                 FROM pbx_time_conditions tc
                 LEFT JOIN pbx_time_groups tg ON tc.time_group_id = tg.id
                 WHERE tc.owner_extension = ?
                 ORDER BY tc.id ASC"
            );
            $stmt->execute([$ownerExtension]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return static::db()->query(
            "SELECT tc.*, tg.title AS group_title, tg.time_start, tg.time_end
             FROM pbx_time_conditions tc
             LEFT JOIN pbx_time_groups tg ON tc.time_group_id = tg.id
             ORDER BY tc.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param string|null $ownerExtension billing-role scope (see allWithGroupInfo) */
    public static function allTimeGroups(?string $ownerExtension = null): array
    {
        if ($ownerExtension !== null) {
            if (!BillingRoleBridge::ownerColumnExists('pbx_time_groups')) {
                return [];
            }
            $stmt = static::db()->prepare('SELECT * FROM pbx_time_groups WHERE owner_extension = ? ORDER BY id ASC');
            $stmt->execute([$ownerExtension]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return static::db()->query('SELECT * FROM pbx_time_groups ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function timeGroupMap(array $timeGroups): array
    {
        $map = [];
        foreach ($timeGroups as $tg) {
            $map[$tg['id']] = $tg;
        }
        return $map;
    }
}
