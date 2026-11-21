<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';

/**
 * The privilege-escalation CIRCUIT BREAKER (auth.php::hasModulePermission).
 *
 * The 'roles' / 'system_users' / 'firewall' / 'fail2ban' modules must be open
 * only to admin WHATEVER sys_role_permissions says. The reason is a real hole
 * (2026-08-21): the roles.php permission matrix offered these modules like any
 * other and the read_only_admin role was configured in the DB with
 * can_access=1 for 'roles' — so that role could make its own role admin and
 * escalate to full privileges.
 *
 * WHY HERE AND NOT IN THE SMOKE TEST: on the live database no role has
 * can_access=1 for these modules, so the page is closed through the normal
 * permission path too — the smoke test CANNOT ISOLATE the circuit breaker
 * (verified by measuring on bin/smoke.php). Here we can write the permission
 * rows ourselves, so the real test is possible.
 */
final class RbacTest extends TestCase
{
    private const LOCKED = [
        'roles',
        'system_users',
        'firewall',
        'fail2ban',
        'mail_settings',
        'system_update',
        'backup',
        'scheduled_tasks',
        'certificates',
        'privacy',
        'phones',
        'web_widgets',
        'plugins',
        'api_keys',
        'webhooks',
        'external_dbs',
        'google_integration',
        'file_storage',
        'ai_models',
        'ai_cloud',
        'ai_apps',
        'assistant',
        'health',
        'call_quality',
        'custom_dialplan',
        'advanced_config',
    ];

    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('tests must run only on asterisk_test');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $db = getDB();
        $db->exec('TRUNCATE TABLE sys_role_permissions');

        // CRITICAL SETUP: explicitly give FULL access in the DB to non-admin
        // roles for the locked modules. If the circuit breaker works, these
        // rows must have no effect.
        $stmt = $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)'
        );
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user', 'user'] as $role_key) {
            foreach (self::LOCKED as $module) {
                $stmt->execute([$role_key, $module]);
            }
        }
        getRolePermissionsMap(null, true);
    }

    public static function lockedModules(): array
    {
        return array_map(static fn(string $m): array => [$m], self::LOCKED);
    }

    public function testAdminOnlyModulesMatchRoleRepositoryDefinition(): void
    {
        require_once dirname(__DIR__, 2) . '/src/repositories/RoleRepository.php';
        $repoLocked = RoleRepository::adminOnlyModules();
        $expected   = self::LOCKED;
        sort($repoLocked);
        sort($expected);
        $this->assertSame($expected, $repoLocked);
    }

    #[DataProvider('lockedModules')]
    public function testNonAdminRolesAreRefusedEvenIfTheDatabaseAllows(string $module): void
    {
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user', 'user'] as $role_key) {
            $_SESSION['user_role'] = $role_key;

            $this->assertFalse(
                hasModulePermission($module, 'access'),
                "role '{$role_key}' can access module '{$module}' — THE CIRCUIT BREAKER IS LOOSE! "
                . 'This allows privilege escalation.'
            );
        }
    }

    #[DataProvider('lockedModules')]
    public function testAdminAlwaysSeesLockedModules(string $module): void
    {
        $_SESSION['user_role'] = 'admin';

        $this->assertTrue(
            hasModulePermission($module, 'access'),
            "admin cannot access module '{$module}' — risk of locking yourself out!"
        );
    }

    public function testUnknownRoleCannotSeeLockedModules(): void
    {
        $_SESSION['user_role'] = '__missing_role__';
        foreach (self::LOCKED as $module) {
            $this->assertFalse(hasModulePermission($module, 'access'));
        }
    }

    public function testUserWithoutSessionCannotSeeLockedModules(): void
    {
        unset($_SESSION['user_role']);
        foreach (self::LOCKED as $module) {
            $this->assertFalse(hasModulePermission($module, 'access'));
        }
    }

    /**
     * Read-only viewer (read_only_admin) rule:
     * it can NEVER edit ('edit') or delete ('delete') in any module, even when
     * the DB explicitly allows it. It must stay a viewer only.
     */
    public function testReadOnlyAdminCannotEditOrDeleteAnywhere(): void
    {
        $_SESSION['user_role'] = 'read_only_admin';
        $modules = ['trunks', 'extensions', 'queues', 'conferences', 'meetings', 'asterisk_settings', 'brand_settings', 'pending_sync', 'push_settings', 'fax_send', 'fax_sent', 'my_phone', 'chat'];

        foreach ($modules as $m) {
            $this->assertFalse(
                hasModulePermission($m, 'edit'),
                "read_only_admin can edit in module '{$m}' — a viewer must not change settings!"
            );
            $this->assertFalse(
                hasModulePermission($m, 'delete'),
                "read_only_admin can delete in module '{$m}' — a viewer must not delete!"
            );
        }
    }

    public function testIsPostDeleteRequestDetection(): void
    {
        $_POST = [];
        $_GET = [];
        $this->assertFalse(isPostDeleteRequest());

        $_POST = ['save_trunk' => '1'];
        $this->assertFalse(isPostDeleteRequest());

        $_POST = ['delete_trunk' => '1'];
        $this->assertTrue(isPostDeleteRequest());

        $_POST = ['remove_extension' => '1'];
        $this->assertTrue(isPostDeleteRequest());

        $_POST = ['cancel_meeting' => '1'];
        $this->assertTrue(isPostDeleteRequest());

        $_POST = [];
        $_POST['action'] = 'delete_mapping';
        $this->assertTrue(isPostDeleteRequest());

        $_POST = [];
        $_GET['action'] = 'delete_mapping';
        $this->assertTrue(isPostDeleteRequest());

        $_POST = [];
        $_GET = [];
    }

    public function testDeleteControlsAreHiddenWithoutDeletePermission(): void
    {
        require_once dirname(__DIR__, 2) . '/src/ui_helpers.php';

        $db = getDB();
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 0)'
        )->execute(['editor_only', 'trunks']);
        getRolePermissionsMap(null, true);

        $_SESSION['user_role'] = 'editor_only';
        $_SERVER['PHP_SELF'] = '/trunks.php';

        $this->assertTrue(hasModulePermission('trunks', 'edit'));
        $this->assertFalse(hasModulePermission('trunks', 'delete'));

        // can_delete=0, so uiDeleteForm must return empty
        $html = uiDeleteForm(1, 'trunk_id', 'delete_trunk');
        $this->assertSame('', $html, 'uiDeleteForm must not print a delete button for a user with can_delete=0!');

        // uiRowActions must not contain the delete button either
        $actionsHtml = uiRowActions(['id' => 1], 'openEditTrunkModal', 'trunk_id', 'delete_trunk');
        $this->assertStringNotContainsString('delete_trunk', $actionsHtml);
    }

    public function testPluginBackedModulesRequireEnabledPluginForNonAdminRoles(): void
    {
        require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';

        $db = getDB();
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)'
        )->execute(['cc_manager', 'ai_tts']);
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)'
        )->execute(['cc_manager', 'ms_teams']);
        getRolePermissionsMap(null, true);

        $csrf = $_SESSION['csrf_token'] = 'test-csrf';
        PluginManager::disable('ai-tts', $csrf);
        PluginManager::disable('ms-teams', $csrf);

        $_SESSION['user_role'] = 'cc_manager';
        $this->assertFalse(hasModulePermission('ai_tts', 'access'));
        $this->assertFalse(hasModulePermission('ms_teams', 'access'));

        $_SESSION['user_role'] = 'read_only_admin';
        $this->assertFalse(hasModulePermission('ai_tts', 'access'));
        $this->assertFalse(hasModulePermission('ms_teams', 'access'));

        PluginManager::enable('ai-tts', $csrf);
        PluginManager::enable('ms-teams', $csrf);

        $_SESSION['user_role'] = 'cc_manager';
        $this->assertTrue(hasModulePermission('ai_tts', 'access'));
        $this->assertTrue(hasModulePermission('ms_teams', 'access'));

        $_SESSION['user_role'] = 'read_only_admin';
        $this->assertTrue(hasModulePermission('ai_tts', 'access'));
        $this->assertFalse(hasModulePermission('ai_tts', 'edit'));
        $this->assertTrue(hasModulePermission('ms_teams', 'access'));
        $this->assertFalse(hasModulePermission('ms_teams', 'edit'));

        PluginManager::disable('ai-tts', $csrf);
        PluginManager::disable('ms-teams', $csrf);
    }

    /**
     * Remote-admin limit (2026-11-20): an admin signed in from a non-local IP
     * keeps only basic user + fax functions. The tests below fake the client
     * IP through $_SERVER['REMOTE_ADDR'] — with no proxy settings in the test
     * DB, getClientIP() returns REMOTE_ADDR as-is (and even with the proxy
     * on, an untrusted REMOTE_ADDR is returned unchanged). isLocalRequest()
     * caches per REMOTE_ADDR fingerprint, so changing the IP between tests
     * recomputes it; the IP is restored in a finally block either way.
     */
    private function grantModule(string $role, string $module): void
    {
        $db = getDB();
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)
             ON DUPLICATE KEY UPDATE can_view = 1, can_access = 1, can_edit = 1, can_delete = 1'
        )->execute([$role, $module]);
        getRolePermissionsMap(null, true);
    }

    private function withRemoteAddr(?string $ip, callable $fn): mixed
    {
        $had = array_key_exists('REMOTE_ADDR', $_SERVER);
        $old = $_SERVER['REMOTE_ADDR'] ?? null;
        try {
            if ($ip === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $ip;
            }
            return $fn();
        } finally {
            if ($had) {
                $_SERVER['REMOTE_ADDR'] = $old;
            } else {
                unset($_SERVER['REMOTE_ADDR']);
            }
        }
    }

    public function testRemoteAdminIsDeniedAdminModulesButKeepsUserAndFax(): void
    {
        // A normal user may use My Phone; a fax user may use the fax inbox.
        $this->grantModule('user', 'my_phone');
        $this->grantModule('fax_user', 'fax_inbox');

        $_SESSION['user_role'] = 'admin';
        $this->withRemoteAddr('203.0.113.5', function (): void {
            $this->assertSame(['user', 'fax_user'], effectiveUserRoles());
            $this->assertFalse(
                hasModulePermission('system_users', 'access'),
                'remote admin can access Users — the limit is not applied!'
            );
            $this->assertFalse(
                hasModulePermission('trunks', 'access'),
                'remote admin can access Trunks — the limit is not applied!'
            );
            $this->assertTrue(
                hasModulePermission('my_phone', 'access'),
                'remote admin lost basic user functions!'
            );
            $this->assertTrue(
                hasModulePermission('fax_inbox', 'access'),
                'remote admin lost fax functions!'
            );
        });
    }

    public function testLocalAdminKeepsFullAccess(): void
    {
        $_SESSION['user_role'] = 'admin';
        $this->withRemoteAddr('192.168.8.50', function (): void {
            $this->assertSame(['admin'], effectiveUserRoles());
            $this->assertTrue(hasModulePermission('system_users', 'access'));
        });
        // And from the loopback address (localhost / VPN endpoint).
        $this->withRemoteAddr('127.0.0.1', function (): void {
            $this->assertTrue(hasModulePermission('system_users', 'access'));
        });
    }

    public function testCliWithoutRemoteAddrCountsAsLocal(): void
    {
        // bin/smoke.php runs under CLI with no REMOTE_ADDR — it must keep
        // seeing the admin role, or the smoke checks break.
        $_SESSION['user_role'] = 'admin';
        $this->withRemoteAddr(null, function (): void {
            $this->assertTrue(isLocalRequest(), 'CLI (no REMOTE_ADDR) must count as local.');
            $this->assertTrue(hasModulePermission('system_users', 'access'));
        });
    }

    public function testRemoteAdminSessionRoleIsRewrittenFromTheDatabase(): void
    {
        $db = getDB();
        // The sys_users role FK needs the admin role row to exist.
        $db->prepare("INSERT IGNORE INTO sys_roles (role_key, role_name) VALUES ('admin', 'Admin')")->execute();
        $db->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, role, is_active)
             VALUES ('remote-admin-test', 'x', 'Remote Admin Test', 'admin', 1)
             ON DUPLICATE KEY UPDATE role = 'admin', is_active = 1"
        )->execute();
        $userId = (int) $db->query("SELECT id FROM sys_users WHERE username = 'remote-admin-test'")->fetchColumn();

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'admin';

        // Remote: the session role drops to 'user' and the marker is set, so
        // the inline `$_SESSION['user_role'] === 'admin'` checks in api/*.php
        // (the pattern requireApiLogin() cannot cover by itself) fail too.
        $this->withRemoteAddr('203.0.113.5', function (): void {
            applyRemoteAdminRestriction();
            $this->assertSame('user', $_SESSION['user_role']);
            $this->assertTrue(isRemoteLimitedAdmin());
        });

        // Back on LAN: the very next request restores 'admin' by itself.
        $this->withRemoteAddr('192.168.8.50', function (): void {
            applyRemoteAdminRestriction();
            $this->assertSame('admin', $_SESSION['user_role']);
            $this->assertFalse(isRemoteLimitedAdmin());
        });

        $db->prepare('DELETE FROM sys_users WHERE username = ?')->execute(['remote-admin-test']);
    }
}

