<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A plugin's public/handler.php (and user/admin action files) end with `return static function`.
 * Code after a file's return never runs, so a helper function written below it is never defined:
 * the page fails with "Plugin endpoint error" (the Invites join page and the Billing store did).
 */
final class PluginHandlerLayoutTest extends TestCase
{
    public function testNoFunctionIsDefinedAfterTheReturn(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__, 3) . '/plugins/*/public/handler.php') ?: [],
            glob(dirname(__DIR__, 3) . '/plugins/*/*/action.php') ?: [],
            glob(dirname(__DIR__, 3) . '/plugins/*/user/badge.php') ?: []
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $ret = strpos($src, "\nreturn static function");
            if ($ret === false) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/^\s*function\s+\w+\s*\(/m', substr($src, $ret), basename(dirname($file, 2)) . '/' . basename(dirname($file)) . '/' . basename($file) . ' defines a function after its return');
        }
    }
}
