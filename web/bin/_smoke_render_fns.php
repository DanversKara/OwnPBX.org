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
