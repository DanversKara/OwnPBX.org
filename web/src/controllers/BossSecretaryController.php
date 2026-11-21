<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/BossSecretaryService.php';
require_once dirname(__DIR__, 2) . '/modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

/**
 * Boss - Secretary page (/boss-secretary).
 * Renders templates/views/boss_secretary/index.php with assets/js/boss_secretary.js.
 * Logic: BossSecretaryService.
 * POST actions: delete_group, save_group.
 * Admins only.
 */
class BossSecretaryController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_group' => fn() => BossSecretaryService::saveGroup($_POST),
            'delete_group' => fn() => BossSecretaryService::deleteGroup($_POST['group_id'] ?? 0, static::csrfToken()),
        ]);

        // Billing roles (Layer 1): a billing user sees only their own groups,
        // and may only pick their own extension as boss or secretary.
        $owner = BillingRoleBridge::listOwnerExtension();
        $groups = BossSecretaryService::getGroups($owner);
        $extensions = BossSecretaryService::getAvailableExtensions($owner);
        $modules = DestinationRegistry::getModuleList();

        $page_title = t('boss_secretary.title');
        static::renderPage('boss_secretary/index', [
            'groups' => $groups,
            'extensions' => $extensions,
            'modules' => $modules,
        ], ['title' => $page_title] + $notices);
    }
}
