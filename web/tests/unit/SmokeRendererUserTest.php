<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
// The grant checks below ask auth.php itself what a role may reach; the bootstrap loads only
// config.php, and the sweep's helpers are used where auth.php is already in memory.
require_once dirname(__DIR__, 2) . '/auth.php';

/**
 * The page sweep must render as an account that exists.
 *
 * Reported from a live PBX: after an administrator deleted the unused `admin` account, every
 * update finished by reporting that the test of every page had found a problem - 398 errors,
 * every route, both languages, all of them "0 bytes" and "does not end with </html>". The pages
 * were fine. bin/_smoke_render.php signed in as a hardcoded user id 1, that row no longer
 * existed, so every render bounced to /login and produced nothing. The sweep then blamed the
 * pages for it.
 */
final class SmokeRendererUserTest extends TestCase
{
    private function renderer(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/bin/_smoke_render.php');
    }

    public function testTheRendererDoesNotHardcodeAUserId(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\$_SESSION\[\s*[\'"]user_id[\'"]\s*\]\s*=\s*\d+\s*;/',
            $this->renderer(),
            'A fixed user id breaks every page the moment that account is deleted.'
        );
    }

    public function testTheApiCallerDoesNotHardcodeAUserId(): void
    {
        $api = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/_smoke_api.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\$_SESSION\[\s*[\'"]user_id[\'"]\s*\]\s*=\s*\d+\s*;/',
            $api,
            'A fixed user id breaks every API check the moment that account is deleted.'
        );
    }

    public function testItPicksAnAccountThatReallyHoldsTheRole(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/_smoke_render_fns.php';
        $db = getDB();

        $adminId = (int) $db->query("SELECT id FROM sys_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId === 0) {
            $this->markTestSkipped('no active admin account to render as');
        }
        $this->assertSame($adminId, smoke_user_id('admin'), 'It should render as a real admin.');

        // And the account it chose really holds that role - the point of the whole change.
        $q = $db->prepare('SELECT role FROM sys_users WHERE id = ? AND is_active = 1');
        $q->execute([smoke_user_id('admin')]);
        $this->assertSame('admin', (string) $q->fetchColumn(), 'The account chosen must hold the role asked for.');
    }

    /**
     * The second half of the same bug, and the dangerous half.
     *
     * Borrowing somebody else's account to stand in for a role looks harmless, because the role
     * is written into the session. It is not: applyRemoteAdminRestriction() reads the role from
     * the sys_users row of the signed-in id on every request and writes it back over the session.
     * So standing in for fax_user with an admin's id renders as an admin. That is why the RBAC
     * sweep reported 42 admin pages as open to fax_user, and why - far worse than the noise - it
     * could no longer have noticed a page that really was open. It must refuse instead.
     */
    public function testNoAccountForTheRoleMeansNoAccountAtAll(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/_smoke_render_fns.php';
        $this->assertSame(
            0,
            smoke_user_id('no_such_role_exists'),
            'With nobody holding the role it must answer 0, never somebody else.'
        );
    }

    public function testBothHarnessesStopWhenNobodyHoldsTheRole(): void
    {
        foreach (['_smoke_render.php', '_smoke_api.php'] as $harness) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/' . $harness);
            $this->assertMatchesRegularExpression(
                '/SMOKE_NO_ROLE_ACCOUNT/',
                $src,
                $harness . ' must report that it could not test the role.'
            );
            $this->assertMatchesRegularExpression(
                '/<=\s*0\s*\)\s*\{/',
                $src,
                $harness . ' must check the id before signing in with it.'
            );
        }
    }

    /**
     * The roles the sweep may stand in for must all be ones with no business on an admin page.
     *
     * read_only_admin is the trap: hasPermission() falls back to letting it VIEW any module that
     * has no explicit permission row, so it renders admin pages quite legitimately. Used as the
     * low-privilege role it would report every page in the list as a permission hole - and the
     * cure for a sweep that cries wolf is for somebody to stop reading it.
     */
    public function testTheStandInRolesCannotLegitimatelySeeAdminPages(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/smoke.php');
        $this->assertSame(
            1,
            preg_match("/define\('SMOKE_LOW_ROLES',\s*\[([^\]]*)\]/", $runner, $m),
            'The candidate roles must be declared in one place.'
        );
        $roles = array_map(static fn($r) => trim($r, " '\""), explode(',', $m[1]));
        $this->assertNotEmpty(array_filter($roles), 'There must be at least one candidate role.');
        foreach (['read_only_admin', 'admin', 'cc_manager'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $roles,
                $forbidden . ' can legitimately reach admin pages, so the sweep would report holes that are not there.'
            );
        }
    }

    public function testTheRunnerFailsWhenTheRoleCouldNotBeTested(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/smoke.php');
        // Three call sites - the route sweep, the permission sweep and the API sweep. A silent
        // skip in any one of them is a green check that tested nothing, which is the whole
        // subject of this class.
        $this->assertGreaterThanOrEqual(
            3,
            preg_match_all('/no_role_account/', $runner),
            'Every sweep that signs in must react to a role it could not test.'
        );
        foreach (['check_routes', 'check_rbac', 'check_api'] as $fn) {
            $body = (string) preg_replace('/^.*?function ' . $fn . '\(/s', '', $runner);
            $body = (string) preg_replace('/\nfunction .*$/s', '', $body);
            $this->assertStringContainsString(
                'no_role_account',
                $body,
                $fn . '() signs in, so it must say when it could not.'
            );
        }
    }

    // --------------------------------------------- a hole, or a deliberate delegation

    /** The module key is walked through routes.php and auth.php, not kept in a third list. */
    public function testThePagesModuleKeyIsResolvedTheSameWayARequestResolvesIt(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/_smoke_render_fns.php';
        $this->assertSame('meetings', smoke_module_key('/meetings'));
        $this->assertSame('roles', smoke_module_key('/roles'));
        $this->assertSame('', smoke_module_key('/no-such-page-exists'));
    }

    /**
     * A page the role was given on purpose is not a permission hole.
     *
     * Most modules are delegable: an administrator may hand 'meetings' to the user role on the
     * Roles page, and the page then opening is the system doing as it was told. The sweep used to
     * call that a hole, and reported "/meetings - permission hole!" to an owner who had quite
     * deliberately let users into video meetings. A report full of holes that are not there is
     * one nobody reads, which is the failure mode this whole class exists to prevent.
     */
    public function testADelegatedModuleIsNotReportedAsAHole(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/_smoke_render_fns.php';
        $db = getDB();
        $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 0, 0)
             ON DUPLICATE KEY UPDATE can_view = 1, can_access = 1'
        )->execute(['zz_smoke_role', 'meetings']);
        try {
            $this->assertTrue(
                smoke_role_granted('/meetings', 'zz_smoke_role'),
                'An explicit grant on a delegable module must read as granted.'
            );
            $this->assertFalse(
                smoke_role_granted('/conferences', 'zz_smoke_role'),
                'A delegable module with no grant must read as not granted.'
            );
        } finally {
            $db->exec("DELETE FROM sys_role_permissions WHERE role_key = 'zz_smoke_role'");
        }
    }

    /**
     * The exemption above must never be able to excuse a real privilege escalation.
     *
     * This is the dangerous edge of the change. The modules under adminOnlyModuleKeys() can never
     * open to a non-admin whatever sys_role_permissions says - the Roles page offers them like
     * any other module, so "Select all" could otherwise hand somebody user management and with it
     * the ability to make their own role admin (found in the 2026-08-21 audit). If the grant
     * lookup honoured those rows, the sweep would quietly stop reporting exactly the holes it
     * exists to find. So: rows are written granting them, and the answer must still be no.
     */
    public function testAStrayPermissionRowCannotExcuseAnAdminOnlyPage(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/_smoke_render_fns.php';
        $db = getDB();
        $breaker = adminOnlyModuleKeys();
        $this->assertNotEmpty($breaker);
        $ins = $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)
             ON DUPLICATE KEY UPDATE can_view = 1, can_access = 1, can_edit = 1, can_delete = 1'
        );
        foreach ($breaker as $key) {
            $ins->execute(['zz_smoke_role', $key]);
        }
        try {
            foreach ($breaker as $key) {
                $this->assertFalse(
                    hasModulePermissionForRole($key, 'access', 'zz_smoke_role'),
                    "The '{$key}' module must never open to a non-admin, even with a row saying it may."
                );
            }
            // And through the sweep's own lookup, which is what decides the verdict.
            foreach (['/roles', '/system-users', '/firewall', '/fail2ban'] as $path) {
                $this->assertFalse(
                    smoke_role_granted($path, 'zz_smoke_role'),
                    $path . ' must never read as granted, or a real hole there would go unreported.'
                );
            }
        } finally {
            $db->exec("DELETE FROM sys_role_permissions WHERE role_key = 'zz_smoke_role'");
        }
    }

    /**
     * The sweep must base its verdict on the grant, not on whether the page came out.
     *
     * Deliberately not a test of where the words sit in the file: two earlier attempts at this
     * compared string offsets and were defeated first by a comment quoting the message and then
     * by a brace inside an interpolated string. The behaviour is covered by the three tests
     * above; this only pins that the runner reads the grant and says so when it reports.
     */
    public function testTheSweepsVerdictRestsOnTheGrant(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/smoke.php');
        $this->assertStringContainsString(
            "empty(\$asLow['granted'])",
            $runner,
            'The verdict must consult the grant auth.php reports.'
        );
        $this->assertStringContainsString(
            'grants it no access to',
            $runner,
            'And the finding must say that no access was granted - that is what makes it a hole.'
        );
    }

}
