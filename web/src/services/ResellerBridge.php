<?php
/**
 * The core's way to the Reseller plugin (plugins/reseller): loaded only when the plugin is enabled.
 *
 * Tenant identity, two ways:
 *   - viewerTenantId(): the signed-in user's reseller, from sys_users.reseller_id. This reads the
 *     CORE column the 1.75.58 migration adds, so it works whether or not the plugin is enabled —
 *     and it keeps scoping a reseller's lists even if the plugin is switched off (fail closed:
 *     switching a plugin off must never suddenly show a reseller another tenant's users).
 *   - tenantForHost(): the reseller that owns a request host (custom domain or stock subdomain).
 *     This DOES need the plugin — it is the plugin's resolveTenantByHost() — and returns null
 *     when the plugin is absent or disabled, so public pages behave exactly as before.
 *
 * Every method here is safe to call with the plugin absent or disabled: no fatal errors, no
 * behavior change for the main admin's users (reseller_id NULL = no tenant = unfiltered).
 */
require_once dirname(__DIR__) . '/core/PluginManager.php';

class ResellerBridge
{
    /** @var int|null|false null = not resolved yet this request; false = no tenant */
    private static $viewerTenant = null;

    /** Loads plugins/reseller/lib/Reseller.php when the plugin is enabled. */
    public static function load(): bool
    {
        try {
            if (!PluginManager::isEnabled('reseller')) {
                return false;
            }
            $dir = (string) (PluginManager::discoverManifests()['reseller']['dir'] ?? '');
            if ($dir === '' || !is_file($dir . '/lib/Reseller.php')) {
                return false;
            }
            require_once $dir . '/lib/Reseller.php';
            return class_exists('Reseller');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The signed-in user's tenant id, or null for the main admin's users (and
     * for signed-out requests). Resolved once per request from the live
     * sys_users row, so a user's tenant follows them if an admin moves them.
     */
    public static function viewerTenantId(): ?int
    {
        if (self::$viewerTenant !== null) {
            return self::$viewerTenant === false ? null : self::$viewerTenant;
        }
        self::$viewerTenant = false;
        try {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                return null;
            }
            $stmt = getDB()->prepare('SELECT reseller_id FROM sys_users WHERE id = ?');
            $stmt->execute([$userId]);
            $v = $stmt->fetchColumn();
            if ($v !== false && $v !== null && (int) $v > 0) {
                self::$viewerTenant = (int) $v;
            }
        } catch (\Throwable $e) {
            // sys_users.reseller_id arrives with the 1.75.58 migration; before
            // that, nobody has a tenant.
            return null;
        }
        return self::$viewerTenant === false ? null : self::$viewerTenant;
    }

    /** The reseller that owns this request host, or null (unknown host, plugin off, suspended). */
    public static function tenantForHost(string $host): ?array
    {
        if (!self::load()) {
            return null;
        }
        try {
            return Reseller::resolveTenantByHost($host);
        } catch (\Throwable $e) {
            error_log('reseller tenantForHost: ' . $e->getMessage());
            return null;
        }
    }
}
