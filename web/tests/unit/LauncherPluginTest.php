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

    /**
     * A text that has just arrived reaches the feed.
     *
     * This is the test that was missing: the query asked plg_sms_messages for a "number" column
     * it does not have, so every text failed the query, the failure was logged and swallowed, and
     * the screen simply stayed empty. Reading one real row back is the only way to catch that.
     */
    public function testAnIncomingTextIsReported(): void
    {
        $db = getDB();
        // The SMS plugin is not installed on every PBX, and not in CI's database.
        $has = $db->prepare("SELECT 1 FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = 'plg_sms_numbers' LIMIT 1");
        $has->execute();
        if ($has->fetchColumn() === false) {
            $this->markTestSkipped('The SMS plugin tables are not installed here.');
        }
        $db->exec("INSERT IGNORE INTO plg_sms_numbers (id, number, provider, label) VALUES (9971, '+15595600098', 'voipms', 'Main')");
        $db->exec("DELETE FROM plg_sms_messages WHERE provider_id = 'lnch-test'");
        $db->exec("DELETE FROM plg_sms_contacts WHERE number_id = 9971 AND phone = '+15551230001'");
        $db->exec("INSERT INTO plg_sms_contacts (number_id, phone, name) VALUES (9971, '+15551230001', 'Jamie')");
        $db->exec("INSERT INTO plg_sms_messages (number_id, peer, direction, body, status, provider_id)
                   VALUES (9971, '+15551230001', 'in', 'running ten minutes late', 'received', 'lnch-test')");

        $texts = array_values(array_filter(
            LauncherFeed::events($this->settings, 0, 50)['events'],
            static fn(array $e): bool => $e['type'] === 'sms'
        ));
        $db->exec("DELETE FROM plg_sms_messages WHERE provider_id = 'lnch-test'");
        $db->exec("DELETE FROM plg_sms_contacts WHERE number_id = 9971 AND phone = '+15551230001'");

        $this->assertNotEmpty($texts, 'An incoming text should reach the feed.');
        $mine = null;
        foreach ($texts as $t) {
            if ($t['number'] === '+15551230001') {
                $mine = $t;
            }
        }
        $this->assertNotEmpty($mine, 'The text that was just inserted should be among them.');
        $this->assertSame('Jamie', $mine['who']);
        $this->assertSame('running ten minutes late', $mine['text']);
        $this->assertSame('pstn', $mine['line']);
    }

    /** A chat message addressed to a watched extension reaches the feed. */
    public function testAnIncomingChatIsReported(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM chat_messages WHERE message = 'lnch chat probe'");
        $db->exec("INSERT IGNORE INTO chat_conversations (id, type) VALUES (9971, 'direct')");
        $db->exec("DELETE FROM chat_participants WHERE conversation_id = 9971");
        $db->exec("INSERT INTO chat_participants (conversation_id, extension) VALUES (9971, '7841'), (9971, '7842')");
        $db->exec("INSERT INTO chat_messages (conversation_id, sender_ext, msg_type, message, created_at)
                   VALUES (9971, '7842', 'text', 'lnch chat probe', NOW())");

        $settings = $this->settings;
        $settings['extensions'] = '7841';
        $chats = array_values(array_filter(
            LauncherFeed::events($settings, 0, 50)['events'],
            static fn(array $e): bool => $e['type'] === 'chat' && $e['text'] === 'lnch chat probe'
        ));
        $db->exec("DELETE FROM chat_messages WHERE message = 'lnch chat probe'");
        $db->exec("DELETE FROM chat_participants WHERE conversation_id = 9971");

        $this->assertCount(1, $chats, 'A chat sent to the watched extension should reach the feed.');
        $this->assertSame('7842', $chats[0]['number']);
        $this->assertSame('internal', $chats[0]['line']);
    }

    /**
     * A second call from the same number is a new event, not the first one again.
     *
     * A ringing call keeps one id so the banner does not come back every few seconds, but the id
     * is kept against a key, and when that key was "<extension>|<number>" the same person ringing
     * again was handed their first call's id - older than the cursor, so it never showed. Keys
     * come from channel unique ids now, which Asterisk never repeats.
     */
    public function testEachCallGetsItsOwnId(): void
    {
        $callId = new ReflectionMethod(LauncherFeed::class, 'callId');
        $callId->setAccessible(true);
        $now = time();

        $first = $callId->invoke(null, 'L1763515234.56', $now);
        // While it rings, every poll must hand back the same id.
        $this->assertSame($first, $callId->invoke(null, 'L1763515234.56', $now + 3));
        // The same caller ringing again is a different channel, so a different and newer id.
        $second = $callId->invoke(null, 'L1763517000.12', $now + 600);
        $this->assertGreaterThan($first, $second);

        // And the key itself must differ between two calls from one number. Asterisk is not
        // running here, so this takes the path where the call is recognised by the trunk leg.
        $callerOf = new ReflectionMethod(LauncherFeed::class, 'callerOf');
        $callerOf->setAccessible(true);
        $keyFor = static function (string $trunkUniqueId) use ($callerOf): string {
            $leg = ['name' => 'PJSIP/7841-webrtc-0000000b', 'exten' => '7841', 'state' => 'Ringing',
                    'cid' => '7841', 'uniqueid' => $trunkUniqueId . '1'];
            $trunk = ['name' => 'PJSIP/236248_openpbx-0000000a', 'exten' => '7841', 'state' => 'Up',
                      'cid' => '5593799829', 'uniqueid' => $trunkUniqueId];
            [$from, $key] = $callerOf->invoke(null, $leg, [$trunkUniqueId => $trunk, $leg['uniqueid'] => $leg], '7841');
            return $from . ' ' . $key;
        };
        $callA = $keyFor('1763515234.56');
        $callB = $keyFor('1763517000.12');
        // Same caller both times...
        $this->assertStringStartsWith('5593799829 ', $callA);
        $this->assertStringStartsWith('5593799829 ', $callB);
        // ...but not the same call.
        $this->assertNotSame($callA, $callB);
    }

    public function testAnswerShapeAndTheSinceCursor(): void
    {
        $res = LauncherFeed::events($this->settings, 0, 20);
        $this->assertTrue($res['ok']);
        $this->assertSame(['ok', 'now', 'latest_id', 'now_id', 'events'], array_keys($res));
        // A client that has just started asks for nothing older than this.
        $this->assertGreaterThan((int) $res['latest_id'], (int) $res['now_id']);
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
