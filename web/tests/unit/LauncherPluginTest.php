<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 3) . '/plugins/launcher/lib/LauncherFeed.php';

/** The read-only feed a TV launcher polls: a glance at calls, messages and voicemail. */
final class LauncherPluginTest extends TestCase
{
    private array $settings = [
        'extensions' => '',
        'include_calls' => '1',
        'include_sms' => '1',
        'include_chat' => '1',
        'include_voicemail' => '1',
        'preview_chars' => '40',
        'window_minutes' => '10',
    ];

    protected function setUp(): void
    {
        Fixtures::load();
        getDB()->exec("DELETE FROM sys_users WHERE username = 'lnchuser'");
        getDB()->exec("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active) VALUES ('lnchuser', 'x', 'Kara Danvers', 'user', '7841', 'sip', 1)");
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM sys_users WHERE username = 'lnchuser'");
    }

    public function testTheTokenIsRealAndCheckedSafely(): void
    {
        $token = LauncherFeed::token();
        $this->assertSame(40, strlen($token));
        $this->assertTrue(LauncherFeed::tokenValid($token));
        $this->assertFalse(LauncherFeed::tokenValid(''));
        $this->assertFalse(LauncherFeed::tokenValid('wrong'));
        // A new token stops the old one at once.
        $fresh = LauncherFeed::token(true);
        $this->assertNotSame($token, $fresh);
        $this->assertFalse(LauncherFeed::tokenValid($token));
    }

    public function testAnswerShapeAndTheSinceCursor(): void
    {
        $res = LauncherFeed::events($this->settings, 0, 20);
        $this->assertTrue($res['ok']);
        $this->assertSame(['ok', 'now', 'latest_id', 'events'], array_keys($res));
        // Asking again with the id just seen returns nothing older: a launcher never repeats itself.
        $again = LauncherFeed::events($this->settings, (int) $res['latest_id'], 20);
        foreach ($again['events'] as $e) {
            $this->assertGreaterThan((int) $res['latest_id'], (int) $e['id']);
        }
    }

    public function testOnlyTheWatchedExtensionsAreReported(): void
    {
        $this->assertSame(['7841'], LauncherFeed::watched(['extensions' => ' 7841 ,,x']));
        $this->assertSame([], LauncherFeed::watched(['extensions' => '']));
    }

    public function testSwitchingASourceOffLeavesItOut(): void
    {
        $off = ['include_calls' => '0', 'include_sms' => '0', 'include_chat' => '0', 'include_voicemail' => '0'] + $this->settings;
        $this->assertSame([], LauncherFeed::events($off, 0, 20)['events']);
    }

    public function testThePluginIsDescribedProperly(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/plugins/launcher/plugin.json'), true);
        $this->assertSame('launcher', $manifest['slug']);
        $this->assertTrue($manifest['hooks']['public_endpoints']);
        $keys = array_column($manifest['settings_schema'], 'key');
        foreach (['include_calls', 'include_sms', 'include_chat', 'include_voicemail', 'preview_chars', 'window_minutes'] as $k) {
            $this->assertContains($k, $keys);
        }
    }
}
