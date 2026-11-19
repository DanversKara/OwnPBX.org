<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Every form must send a token that the server will actually accept.
 *
 * Reported from a live system: a new page emitted its token with
 * `function_exists('generateCSRFToken') ? generateCSRFToken() : ''`. No such function exists -
 * the helper is getCSRFToken() - so the guard quietly chose the empty string, every form on the
 * page posted a blank token, and the only symptom was "Invalid security token (CSRF)" with
 * nothing in any log to explain it. A fallback that hides a typo is worse than the typo.
 */
final class CsrfTokenInTemplatesTest extends TestCase
{
    /** @return list<string> every .php file under templates/ */
    private function templates(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    public function testNoTemplateCallsACsrfHelperThatDoesNotExist(): void
    {
        $this->assertTrue(function_exists('getCSRFToken'), 'getCSRFToken() is the helper templates must use.');
        $this->assertFalse(
            function_exists('generateCSRFToken'),
            'If this helper is ever added, the rule below should name it too.'
        );

        $bad = [];
        foreach ($this->templates() as $path) {
            if (str_contains((string) file_get_contents($path), 'generateCSRFToken')) {
                $bad[] = basename($path);
            }
        }
        $this->assertSame([], $bad, 'These templates call a CSRF helper that does not exist.');
    }

    /**
     * Guarding with function_exists() is fine; guarding on a name that does not exist is not.
     *
     * The guard then always takes its fallback branch, and when that branch is "" the form posts
     * a blank token forever. A footer guarding the real getCSRFToken() and falling back to the
     * session's own token is correct and must keep passing, so the rule is about the name.
     */
    public function testEveryCsrfHelperNamedInATemplateExists(): void
    {
        $missing = [];
        foreach ($this->templates() as $path) {
            $src = (string) file_get_contents($path);
            if (preg_match_all('/function_exists\(\s*[\'"]([A-Za-z_]*CSRFToken)[\'"]\s*\)/i', $src, $m) === 0) {
                continue;
            }
            foreach ($m[1] as $fn) {
                if (!function_exists($fn)) {
                    $missing[] = basename($path) . ' → ' . $fn . '()';
                }
            }
        }
        $this->assertSame([], $missing, 'These templates guard on a CSRF helper that does not exist.');
    }

    /** Every form that posts carries a csrf_token field. */
    public function testEveryPostFormInTheLockdownPageCarriesAToken(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/views/lockdown/index.php');
        $forms = preg_split('/<form\b/i', $src);
        array_shift($forms);
        $this->assertNotEmpty($forms, 'The lockdown page should have forms.');
        foreach ($forms as $i => $form) {
            $body = explode('</form>', $form)[0];
            $this->assertStringContainsString(
                'csrf_token',
                $body,
                "Form #{$i} on the lockdown page posts without a CSRF token."
            );
        }
    }
}
