<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

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

    public function testItPicksARealAccountPreferringTheRoleBeingRendered(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/_smoke_render_fns.php';
        $db = getDB();

        $adminId = (int) $db->query("SELECT id FROM sys_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId > 0) {
            $this->assertSame($adminId, smoke_user_id('admin'), 'It should render as a real admin.');
        }

        // A role nobody holds still has to give back an account that exists, or the sweep is
        // measuring the login page instead of the page it asked for.
        $chosen = smoke_user_id('no_such_role_exists');
        $q = $db->prepare('SELECT COUNT(*) FROM sys_users WHERE id = ? AND is_active = 1');
        $q->execute([$chosen]);
        $this->assertSame(1, (int) $q->fetchColumn(), 'The fallback must still be a real, active account.');
    }
}
