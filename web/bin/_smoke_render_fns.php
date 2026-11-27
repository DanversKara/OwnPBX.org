<?php

declare(strict_types=1);

/**
 * Helpers for bin/_smoke_render.php, kept apart so they can be tested on their own: the renderer
 * itself renders a page and exits, which is not something a test can call.
 */

/**
 * The id of an active account that actually holds this role, or 0 when there is none.
 *
 * It must be this role and no other. The session's role is not what decides what a page shows:
 * applyRemoteAdminRestriction() reads the role from the sys_users row of the signed-in id on
 * every request and writes it back over the session. So rendering "as fax_user" from an admin's
 * account renders as an admin - which is why the RBAC sweep reported every admin page as open to
 * fax_user, and, far worse, could no longer have noticed a page that really was.
 *
 * Returning 0 rather than borrowing somebody else's account is the point: a check that cannot be
 * run should say so, not quietly test the wrong thing.
 */
function smoke_user_id(string $role): int
{
    try {
        $q = getDB()->prepare('SELECT id FROM sys_users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $q->execute([$role]);
        return (int) $q->fetchColumn();
    } catch (\Throwable $e) {
        // A database that cannot be read is a different failure; the sweep reports it plainly
        // through the page errors rather than dying here.
        return 0;
    }
}

/**
 * The module key a page's permissions are read from, or '' when it has none.
 *
 * routes.php names the module file for each page and auth.php maps that file to a key; this walks
 * the same two steps the real request does, rather than keeping a third list to drift out of step
 * with them.
 */
function smoke_module_key(string $path): string
{
    static $routes = null;
    if ($routes === null) {
        // routes.php returns its array, so it is read once and kept rather than re-required per
        // page. The web root comes from this file's own location - it lives in web/bin - and not
        // from SMOKE_ROOT or $GLOBALS: those exist in the runner and the render subprocess but
        // not in a PHPUnit process, where this silently resolved every module to '' and so
        // reported every page as not granted.
        $file = dirname(__DIR__) . '/src/routes.php';
        $routes = is_file($file) ? (array) require $file : [];
    }
    $module = (string) ($routes[$path]['module'] ?? '');
    if ($module === '') {
        return '';
    }
    try {
        return (string) getModuleKeyForPage($module);
    } catch (\Throwable $e) {
        // auth.php not loaded (both harnesses require it one line before this file, so this is
        // the belt rather than the braces). An undefined function throws Error, which is a
        // Throwable, so this is a real guard and not decoration - and it is written this way
        // rather than with function_exists() because the analyser can see auth.php and reports
        // that call as always true.
        return '';
    }
}

/**
 * Would auth.php let this role reach this page? Asked of auth.php itself, never inferred.
 *
 * This is what separates a permission hole from a deliberate delegation. Most modules are
 * delegable: an administrator may hand 'meetings' or 'conferences' to the user role on the Roles
 * page, and then that role rendering the page is the system doing as it was told. Only the
 * modules under adminOnlyModuleKeys() can never open to a non-admin, whatever the Roles page
 * says. A sweep that cannot tell those apart has to call every delegation a hole, and a report
 * full of holes that are not there is one nobody reads.
 */
function smoke_role_granted(string $path, string $role): bool
{
    $key = smoke_module_key($path);
    if ($key === '') {
        return false;
    }
    try {
        return (bool) hasModulePermissionForRole($key, 'access', $role);
    } catch (\Throwable $e) {
        // Not granted is the safe answer: it makes the sweep report the page rather than excuse
        // it, so a lookup that breaks can only ever cost a false alarm, never a missed hole.
        return false;
    }
}
