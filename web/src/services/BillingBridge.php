<?php
/**
 * The core's way to the Billing plugin (plugins/billing): loaded only when the plugin is enabled.
 * Every check here says "allowed" when the plugin is off or limits are not enforced, so billing
 * can never stop calls, texts or faxes on a system that does not use it.
 */
require_once dirname(__DIR__) . '/core/PluginManager.php';

class BillingBridge
{
    /** Loads plugins/billing/lib/Billing.php when the plugin is enabled. */
    public static function load(): bool
    {
        try {
            if (!PluginManager::isEnabled('billing')) {
                return false;
            }
            $dir = (string) (PluginManager::discoverManifests()['billing']['dir'] ?? '');
            if ($dir === '' || !is_file($dir . '/lib/Billing.php')) {
                return false;
            }
            require_once $dir . '/lib/Billing.php';
            return class_exists('Billing');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** May the user use one more text / fax this month (feature: messages, faxes)? */
    public static function allows(int $userId, string $feature): bool
    {
        if (!self::load()) {
            return true;
        }
        try {
            return Billing::allows($userId, $feature);
        } catch (\Throwable $e) {
            error_log('billing allows: ' . $e->getMessage());
            return true;
        }
    }

    public static function record(int $userId, string $feature, int $amount = 1, string $detail = ''): void
    {
        if (!self::load()) {
            return;
        }
        try {
            Billing::record($userId, $feature, $amount, $detail);
        } catch (\Throwable $e) {
            error_log('billing record: ' . $e->getMessage());
        }
    }

    /**
     * Atomically reserves one use of a monthly quota (Billing 1.0.3+): the
     * quota check and the usage insert happen in one statement, so two sends
     * at the same time cannot both pass the check and then both count.
     * Returns the usage row id, 0 when the quota is used up, or null when
     * billing is off or too old for reserve() — the caller then falls back
     * to allows() + record(), exactly like Sms does. A failed send gives its
     * reservation back with releaseReservation(), so only sends that went
     * out count.
     */
    public static function reserve(int $userId, string $feature, int $amount = 1, string $detail = ''): ?int
    {
        if (!self::load()) {
            return null;
        }
        try {
            if (!method_exists('Billing', 'reserve')) {
                return null;
            }
            return Billing::reserve($userId, $feature, $amount, $detail);
        } catch (\Throwable $e) {
            error_log('billing reserve: ' . $e->getMessage());
            return null;
        }
    }

    /** Gives back a reservation (e.g. the send failed after reserving). No-op when billing is off or too old. */
    public static function releaseReservation(int $usageId): void
    {
        if ($usageId <= 0 || !self::load()) {
            return;
        }
        try {
            if (method_exists('Billing', 'releaseReservation')) {
                Billing::releaseReservation($usageId);
            }
        } catch (\Throwable $e) {
            error_log('billing release: ' . $e->getMessage());
        }
    }
}
