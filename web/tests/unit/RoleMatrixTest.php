<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/core/BaseRepository.php';
require_once dirname(__DIR__, 2) . '/src/repositories/RoleRepository.php';
require_once dirname(__DIR__, 2) . '/auth.php';

/**
 * The Roles page's permission matrix must describe the real system: every
 * page is in it, in the sidebar's group and order, with exactly the actions
 * the code checks, and admin-only pages locked. It had drifted (pages
 * missing, call reports under the wrong group, edit/delete boxes on
 * read-only pages, admin-only pages offered to other roles).
 */
final class RoleMatrixTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Pages that are not permission-controlled modules (login flow, own account, OAuth, SAML). */
    private const NOT_MODULES = ['login', 'login_2fa', 'login_pin', 'admin_pin', 'admin_pin_setup', 'logout', 'force_reset', 'reset_password', 'forgot_password', 'mobile_login', 'security', 'google_auth', 'google_auth_callback', 'oidc_auth', 'oidc_auth_callback', 'saml_auth', 'saml_auth_acs', 'saml_auth_metadata'];

    /** Sidebar group id => matrix group. */
    private const SIDEBAR_GROUPS = [
        'group-dashboard' => 'General', 'group-trunks' => 'Outbound Line Management', 'group-pbx' => 'PBX Management', 'group-network' => 'Network & Devices',
        'group-admin' => 'Administration', 'group-security' => 'Security', 'group-integrations' => 'Integrations', 'group-ai' => 'AI',
        'group-fax' => 'Fax System', 'group-cc' => 'Call Center',
    ];

    /** @return array<string, string> route path => module key */
    private static function routeModules(): array
    {
        $routes = require self::ROOT . '/src/routes.php';
        $out = [];
        foreach ($routes as $path => $r) {
            if (is_array($r)) {
                $_SERVER['PHP_SELF'] = '/' . $r['module'];
                $out[$path] = getModuleKeyForPage();
            }
        }
        return $out;
    }

    public function testEveryPageIsInTheMatrix(): void
    {
        $def = RoleRepository::modulesDefinition();
        foreach (self::routeModules() as $path => $module) {
            if (in_array($module, self::NOT_MODULES, true)) {
                continue;
            }
            $this->assertArrayHasKey($module, $def, "{$path} ({$module}) is missing from the roles matrix");
        }
    }

    public function testMatrixFollowsTheSidebar(): void
    {
        $def = RoleRepository::modulesDefinition();
        $byPath = self::routeModules();
        $group = null;
        $order = [];
        foreach (file(self::ROOT . '/templates/sidebar_menu.php') as $line) {
            if (preg_match("/'id' => '([a-z]+)'/", $line, $m)) {
                $group = self::SIDEBAR_GROUPS['group-' . $m[1]] ?? null;
            }
            if ($group && preg_match("#'href' => '(/[a-z0-9/-]+)'#", $line, $m) && isset($byPath[$m[1]], $def[$byPath[$m[1]]])) {
                $module = $byPath[$m[1]];
                $this->assertSame($group, $def[$module]['group'], "{$module} is under \"{$group}\" in the sidebar");
                $order[] = $module;
            }
        }
        $order = array_values(array_unique($order));
        $matrixOrder = array_values(array_intersect(array_keys($def), $order));
        $this->assertSame($order, $matrixOrder, 'matrix rows are in the sidebar order');
        // Groups appear in the sidebar's order too.
        $this->assertSame(array_values(array_unique(array_column($def, 'group'))), array_values(array_unique(array_map(fn($k) => $def[$k]['group'], $order))));
    }

    public function testEverySidebarLinkIsARoute(): void
    {
        $routes = require self::ROOT . '/src/routes.php';
        preg_match_all("#'href' => '([^']+)'#", (string) file_get_contents(self::ROOT . '/templates/sidebar_menu.php'), $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $href) {
            $this->assertArrayHasKey($href, $routes, "sidebar link {$href} has no route");
        }
    }

    public function testActionsMatchWhatTheCodeChecks(): void
    {
        $def = RoleRepository::modulesDefinition();
        $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT)), '/\.php$/');
        foreach ($files as $f) {
            $path = (string) $f;
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            preg_match_all("/(?:hasModulePermission|requireModulePermission|requireModule)\(\s*'([a-z_]+)'\s*,\s*'(edit|delete)'/", (string) file_get_contents($path), $m, PREG_SET_ORDER);
            foreach ($m as [, $module, $action]) {
                if (isset($def[$module])) {
                    $this->assertContains($action, $def[$module]['actions'], "{$module}: '{$action}' is checked in " . basename($path) . ' but has no column');
                }
            }
        }
        foreach ($def as $key => $m) {
            $this->assertContains('view', $m['actions'], $key);
            $this->assertEmpty(array_diff($m['actions'], RoleRepository::ALL_ACTIONS), $key);
        }
    }

    public function testAdminOnlyModulesMatchTheCircuitBreaker(): void
    {
        // Read the list from the function that holds it, rather than scraping auth.php for an
        // array literal: the literal moved into adminOnlyModuleKeys() when sudo-mode arrived,
        // and a test that only knows the old shape reports the refactor as a missing breaker.
        $this->assertTrue(function_exists('adminOnlyModuleKeys'), 'auth.php must define the admin-only list.');
        $locked = adminOnlyModuleKeys();
        sort($locked);
        $flagged = RoleRepository::adminOnlyModules();
        sort($flagged);
        $this->assertSame($locked, $flagged);

        // And the circuit breaker really refuses them to everyone else.
        $prev = $_SESSION['user_role'] ?? null;
        $_SESSION['user_role'] = 'cc_manager';
        foreach ($flagged as $module) {
            $this->assertFalse(hasModulePermission($module, 'view'), $module);
        }
        $_SESSION['user_role'] = $prev;
    }

    /**
     * PBX Settings is admin-only, and a permission row cannot hand it to anybody else.
     *
     * Moved under the breaker on 2026-11-27 at the owner's request. The 'asterisk_settings' key
     * covers PBX Settings, Transports and the UI kit, and what it writes is the call path: PJSIP
     * and RTP settings, the TLS transport, T.38. A role with 'edit' could move signalling onto a
     * plain transport or send RTP somewhere of its choosing, which is every call on the system,
     * and 'view' alone still shows the transport layout and the TLS domain.
     *
     * Written as its own test rather than left to the list comparison above because this one has
     * a consequence worth being deliberate about: the breaker is symmetric, so read_only_admin
     * loses even 'view', which it had before. That is the intended trade and it should fail
     * loudly if someone quietly reverses it.
     */
    public function testPbxSettingsCannotBeDelegatedToAnyNonAdminRole(): void
    {
        $this->assertContains('asterisk_settings', adminOnlyModuleKeys());
        $this->assertContains('asterisk_settings', RoleRepository::adminOnlyModules());

        $db = getDB();
        $ins = $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)
             ON DUPLICATE KEY UPDATE can_view = 1, can_access = 1, can_edit = 1, can_delete = 1'
        );
        $roles = ['zz_matrix_role', 'read_only_admin', 'user', 'cc_agent', 'cc_manager', 'fax_user'];
        foreach ($roles as $role) {
            $ins->execute([$role, 'asterisk_settings']);
        }
        try {
            foreach ($roles as $role) {
                foreach (['view', 'access', 'edit'] as $action) {
                    $this->assertFalse(
                        hasModulePermissionForRole('asterisk_settings', $action, $role),
                        "'{$action}' on PBX Settings must be refused to {$role} even with a row granting it."
                    );
                }
            }
            // Symmetric: the admin can never be locked out of it either.
            $this->assertTrue(hasModulePermissionForRole('asterisk_settings', 'edit', 'admin'));
        } finally {
            $q = $db->prepare("DELETE FROM sys_role_permissions WHERE module_key = 'asterisk_settings' AND role_key = ?");
            foreach ($roles as $role) {
                $q->execute([$role]);
            }
        }
    }
}
