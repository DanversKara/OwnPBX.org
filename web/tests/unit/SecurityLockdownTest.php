<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/services/SecurityLockdownService.php';
require_once dirname(__DIR__, 2) . '/src/services/TwoFactorService.php';
require_once dirname(__DIR__, 2) . '/src/services/SmsGuardService.php';

/**
 * Shutting the PBX down, and the text message that can do it.
 *
 * The interesting half of this is what the text channel refuses. A command that arrives from an
 * unknown number, or without the passphrase, or with a code already spent, must change nothing -
 * and a channel that can be made to unlock anything is worse than no channel at all.
 */
final class SecurityLockdownTest extends TestCase
{
    private const PASS = 'correct-horse-battery';
    private string $secret = '';

    protected function setUp(): void
    {
        Fixtures::load();
        $this->secret = TwoFactorService::generateSecret();
        setSystemSetting(SmsGuardService::SETTING_ENABLED, '1');
        setSystemSetting(SmsGuardService::SETTING_NUMBERS, '+15555550123');
        setSystemSetting(SmsGuardService::SETTING_PASSPHRASE, password_hash(self::PASS, PASSWORD_DEFAULT));
        setSystemSetting(SmsGuardService::SETTING_SECRET, $this->secret);
        setSystemSetting(SmsGuardService::SETTING_USED_CODE, '');
        setSystemSetting(SmsGuardService::SETTING_FAILS, '');
        setSystemSetting(SecurityLockdownService::SETTING_ACTIVE, '0');
    }

    protected function tearDown(): void
    {
        setSystemSetting(SmsGuardService::SETTING_ENABLED, '0');
        setSystemSetting(SecurityLockdownService::SETTING_ACTIVE, '0');
        setSystemSetting(SmsGuardService::SETTING_FAILS, '');
    }

    private function code(): string
    {
        return TwoFactorService::calculateCode($this->secret);
    }

    // ------------------------------------------------------------ the networks

    public function testLocalAddressesAreRecognised(): void
    {
        $this->assertTrue(SecurityLockdownService::isLocalIp('192.168.8.196'));
        $this->assertTrue(SecurityLockdownService::isLocalIp('10.4.1.9'));
        $this->assertTrue(SecurityLockdownService::isLocalIp('127.0.0.1'));
        $this->assertTrue(SecurityLockdownService::isLocalIp('172.16.0.1'));
        $this->assertFalse(SecurityLockdownService::isLocalIp('8.8.8.8'));
        $this->assertFalse(SecurityLockdownService::isLocalIp('172.32.0.1'));
        $this->assertFalse(SecurityLockdownService::isLocalIp('not-an-ip'));
        $this->assertFalse(SecurityLockdownService::isLocalIp(''));
    }

    public function testCidrEdgesAreNotOffByOne(): void
    {
        $this->assertTrue(SecurityLockdownService::ipInCidr('10.0.0.0', '10.0.0.0/8'));
        $this->assertTrue(SecurityLockdownService::ipInCidr('10.255.255.255', '10.0.0.0/8'));
        $this->assertFalse(SecurityLockdownService::ipInCidr('11.0.0.0', '10.0.0.0/8'));
        $this->assertTrue(SecurityLockdownService::ipInCidr('192.168.8.196', '192.168.8.192/28'));
        $this->assertFalse(SecurityLockdownService::ipInCidr('192.168.8.208', '192.168.8.192/28'));
    }

    // --------------------------------------------------------------- the gate

    public function testALockdownShutsOutEverythingButTheLocalNetwork(): void
    {
        $user = ['locked_at' => null];
        $this->assertTrue(SecurityLockdownService::loginAllowed($user, '8.8.8.8')['allowed']);

        SecurityLockdownService::enable('test');
        $this->assertFalse(SecurityLockdownService::loginAllowed($user, '8.8.8.8')['allowed']);
        $this->assertSame('lockdown', SecurityLockdownService::loginAllowed($user, '8.8.8.8')['reason']);
        $this->assertTrue(SecurityLockdownService::loginAllowed($user, '192.168.8.196')['allowed']);

        SecurityLockdownService::disable('test');
        $this->assertTrue(SecurityLockdownService::loginAllowed($user, '8.8.8.8')['allowed']);
    }

    public function testALockedAccountIsRefusedEvenLocally(): void
    {
        $user = ['locked_at' => '2026-11-19 01:00:00'];
        $res = SecurityLockdownService::loginAllowed($user, '192.168.8.196');
        $this->assertFalse($res['allowed']);
        $this->assertSame('locked', $res['reason']);
    }

    // ---------------------------------------------------------- the text line

    public function testATextFromAnUnknownNumberIsIgnoredEntirely(): void
    {
        $reply = SmsGuardService::handle('+15559998888', self::PASS . ' ' . $this->code() . ' LOCKDOWN');
        // Not even an error: a stranger must not learn that this channel exists.
        $this->assertNull($reply);
        $this->assertFalse(SecurityLockdownService::isActive());
    }

    public function testTheRightNumberWithTheWrongPassphraseChangesNothing(): void
    {
        $reply = SmsGuardService::handle('+15555550123', 'wrong ' . $this->code() . ' LOCKDOWN');
        $this->assertSame('Not accepted.', $reply);
        $this->assertFalse(SecurityLockdownService::isActive());
    }

    public function testTheRightPassphraseWithAWrongCodeChangesNothing(): void
    {
        $reply = SmsGuardService::handle('+15555550123', self::PASS . ' 000000 LOCKDOWN');
        $this->assertSame('Not accepted.', $reply);
        $this->assertFalse(SecurityLockdownService::isActive());
    }

    public function testAProperCommandLocksThePbxDown(): void
    {
        $reply = SmsGuardService::handle('+15555550123', self::PASS . ' ' . $this->code() . ' LOCKDOWN');
        $this->assertStringStartsWith('Lockdown ON', (string) $reply);
        $this->assertTrue(SecurityLockdownService::isActive());
    }

    public function testACodeWorksOnlyOnce(): void
    {
        $code = $this->code();
        SmsGuardService::handle('+15555550123', self::PASS . ' ' . $code . ' STATUS');
        // Someone who reads the message on its way cannot send it again.
        $again = SmsGuardService::handle('+15555550123', self::PASS . ' ' . $code . ' LOCKDOWN');
        $this->assertSame('Not accepted.', $again);
        $this->assertFalse(SecurityLockdownService::isActive());
    }

    public function testThereIsNoCommandThatOpensAnything(): void
    {
        SecurityLockdownService::enable('test');
        foreach (['UNLOCK 611', 'LOCKDOWN OFF', 'OPEN', 'DISABLE', 'OVERRIDE 611'] as $attempt) {
            setSystemSetting(SmsGuardService::SETTING_USED_CODE, '');
            SmsGuardService::handle('+15555550123', self::PASS . ' ' . $this->code() . ' ' . $attempt);
            $this->assertTrue(
                SecurityLockdownService::isActive(),
                "A text must not be able to lift a lockdown, but '{$attempt}' did."
            );
        }
    }

    public function testGuessingStopsBeingAnsweredAfterAFewTries(): void
    {
        for ($i = 0; $i < SmsGuardService::MAX_FAILS; $i++) {
            SmsGuardService::handle('+15555550123', 'wrong 000000 LOCKDOWN');
        }
        // Even the right message is now ignored: the channel has gone quiet.
        $reply = SmsGuardService::handle('+15555550123', self::PASS . ' ' . $this->code() . ' LOCKDOWN');
        $this->assertNull($reply);
        $this->assertFalse(SecurityLockdownService::isActive());
    }

    public function testTheChannelIsOffUntilItIsSetUp(): void
    {
        setSystemSetting(SmsGuardService::SETTING_PASSPHRASE, '');
        $this->assertFalse(SmsGuardService::enabled());
        $this->assertNull(SmsGuardService::handle('+15555550123', self::PASS . ' ' . $this->code() . ' LOCKDOWN'));
    }

    // ------------------------------------------------------------ the replies

    /**
     * A reply waits in a queue rather than going out on the provider's own webhook.
     *
     * Reported from a live system: the answer was sent from inside the inbound webhook, our SMS
     * API call outlasted the provider's patience, and it reported "Operation timed out after
     * 15002 milliseconds" for a message that had in fact arrived.
     */
    public function testARepliesQueueRoundTripsAndEmpties(): void
    {
        PluginManager::kvSet(SmsGuardService::SLUG_SMS, 'state', 'guard_replies', []);
        SmsGuardService::queueReply('+15555550123', 'Lockdown ON.');
        SmsGuardService::queueReply('+15555550123', 'Second one.');

        $taken = SmsGuardService::takeQueuedReplies();
        $this->assertCount(2, $taken);
        $this->assertSame('Lockdown ON.', $taken[0]['text']);
        $this->assertSame('+15555550123', $taken[0]['to']);

        // Taking empties it, so a reply cannot be sent twice or retried for ever.
        $this->assertSame([], SmsGuardService::takeQueuedReplies());
    }

    // -------------------------------------------------------------- the kicks

    public function testSigningSomeoneOutRaisesTheirSessionEpoch(): void
    {
        $db = getDB();
        $id = (int) $db->query('SELECT id FROM sys_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        $before = (int) $db->query("SELECT token_epoch FROM sys_users WHERE id = {$id}")->fetchColumn();

        SecurityLockdownService::kickUser($id);

        $after = (int) $db->query("SELECT token_epoch FROM sys_users WHERE id = {$id}")->fetchColumn();
        $this->assertSame($before + 1, $after, 'A raised epoch is what drops the open sessions.');
    }

    public function testLockingAnAccountAlsoSignsItOut(): void
    {
        $db = getDB();
        $id = (int) $db->query('SELECT id FROM sys_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        $before = (int) $db->query("SELECT token_epoch FROM sys_users WHERE id = {$id}")->fetchColumn();

        SecurityLockdownService::lockUser($id, 'test');
        $row = $db->query("SELECT locked_at, token_epoch FROM sys_users WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['locked_at'], 'Locking must seal the account...');
        $this->assertSame($before + 1, (int) $row['token_epoch'], '...and end the sessions it already had.');

        SecurityLockdownService::unlockUser($id, 'test');
        $this->assertNull($db->query("SELECT locked_at FROM sys_users WHERE id = {$id}")->fetchColumn());
    }
}
