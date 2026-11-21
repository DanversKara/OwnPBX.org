<?php
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

/**
 * Database access for the IVR page.
 * Tables: pbx_ivr_entries (menu keys), pbx_announcements.
 * Used by IvrController.
 */
class IvrRepository extends BaseRepository
{
    protected static string $table = 'pbx_ivrs';

    /**
     * @param string|null $ownerExtension billing-role scope: only this
     *   extension's IVRs (null = everyone, as before). Without the owner
     *   column yet, a scoped viewer sees nothing (fail closed).
     */
    public static function allOrderedByTitle(?string $ownerExtension = null): array
    {
        if ($ownerExtension !== null) {
            if (!BillingRoleBridge::ownerColumnExists('pbx_ivrs')) {
                return [];
            }
            $stmt = static::db()->prepare('SELECT * FROM pbx_ivrs WHERE owner_extension = ? ORDER BY title ASC');
            $stmt->execute([$ownerExtension]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return static::findAll('title ASC');
    }

    /**
     * @param int[]|null $ivrIds only entries of these IVRs (null = all).
     *   An empty list returns nothing.
     */
    public static function entriesByIvr(?array $ivrIds = null): array
    {
        $entries_by_ivr = [];
        if ($ivrIds !== null) {
            $ivrIds = array_values(array_unique(array_map('intval', $ivrIds)));
            if ($ivrIds === []) {
                return [];
            }
            $in = implode(',', array_fill(0, count($ivrIds), '?'));
            $stmt = static::db()->prepare("SELECT * FROM pbx_ivr_entries WHERE ivr_id IN ({$in}) ORDER BY digit ASC");
            $stmt->execute($ivrIds);
            $raw_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $raw_entries = static::db()->query('SELECT * FROM pbx_ivr_entries ORDER BY digit ASC')->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($raw_entries as $e) {
            $entries_by_ivr[$e['ivr_id']][] = $e;
        }
        return $entries_by_ivr;
    }

    public static function activeAnnouncements(): array
    {
        return static::db()->query('SELECT id, title, audio_file FROM pbx_announcements ORDER BY title ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Turns a dest_type/dest_id pair into a coloured, readable badge (instead
     * of the raw "type [id]" form). It used to be a standalone function inside
     * ivrs.php.
     */
    public static function destBadge(?string $destType, $destId, array &$cache): string
    {
        if (empty($destType)) return '<span style="color: var(--text-muted); font-size: 12px;">-</span>';
        $mod = DestinationRegistry::getModule($destType);
        $modName = $mod ? $mod->getName() : $destType;
        $label = DestinationRegistry::resolveLabel($destType, $destId, $cache);
        $cls = DestinationRegistry::badgeClassFor($destType);
        if ($label !== null) {
            return '<span class="badge ' . $cls . '" style="font-size: 10px;">' . htmlspecialchars($modName) . ': ' . htmlspecialchars($label) . '</span>';
        }
        return '<span class="badge badge-danger" style="font-size: 10px;" title="' . htmlspecialchars(sprintf(t('ivr.target_not_found_tooltip'), $destId)) . '"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($modName) . ' ' . htmlspecialchars(t('ivr.target_not_found')) . '</span>';
    }
}
