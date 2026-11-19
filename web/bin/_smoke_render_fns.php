<?php

declare(strict_types=1);

/**
 * Helpers for bin/_smoke_render.php, kept apart so they can be tested on their own: the renderer
 * itself renders a page and exits, which is not something a test can call.
 */

/**
 * The id of a real account to render pages as, preferring one that holds the role being tested.
 *
 * Any account will do - the role in the session is what the permission checks read - but it has
 * to exist: getCurrentUser() returning nothing sends every page to /login, and the sweep then
 * blames the pages.
 */
function smoke_user_id(string $role): int
{
    try {
        $db = getDB();
        $q = $db->prepare('SELECT id FROM sys_users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $q->execute([$role]);
        $id = (int) $q->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $id = (int) $db->query('SELECT id FROM sys_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    } catch (\Throwable $e) {
        // Fall through: a database that cannot be read is a different failure, and the sweep
        // reports it plainly through the page errors rather than dying here.
    }
    return 1;
}
