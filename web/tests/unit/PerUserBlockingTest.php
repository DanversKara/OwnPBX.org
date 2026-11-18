<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/InboundBlacklistService.php';

/** *86 blocks a caller from reaching one person, not from reaching the whole PBX. */
final class PerUserBlockingTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE username IN ('blkalice', 'blkbob')");
        $db->exec("DELETE FROM pbx_inbound_blacklist WHERE number IN ('15551110001', '15551110002')");
        $db->exec("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active, may_block_callers) VALUES ('blkalice', 'x', 'Alice', 'user', '7831', 'sip', 1, 1)");
        $db->exec("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active, may_block_callers) VALUES ('blkbob', 'x', 'Bob', 'user', '7832', 'sip', 1, 0)");
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM sys_users WHERE username IN ('blkalice', 'blkbob')");
        getDB()->exec("DELETE FROM pbx_inbound_blacklist WHERE number IN ('15551110001', '15551110002')");
    }

    public function testEachPersonHasTheirOwnListAndSeesOnlyIt(): void
    {
        $this->assertTrue(InboundBlacklistService::addForUser('7831', '15551110001', 'Robocall')['success']);
        // The same number on somebody else's list is a separate entry, not a duplicate.
        getDB()->exec("UPDATE sys_users SET may_block_callers = 1 WHERE extension = '7832'");
        $this->assertTrue(InboundBlacklistService::addForUser('7832', '15551110001')['success']);

        $alice = InboundBlacklistService::listForUser('7831');
        $bob = InboundBlacklistService::listForUser('7832');
        $this->assertCount(1, $alice);
        $this->assertCount(1, $bob);
        $this->assertSame('7831', (string) $alice[0]['owner_extension']);
        $this->assertSame('7832', (string) $bob[0]['owner_extension']);

        // One person cannot unblock (or reach) another person's entry.
        $this->assertFalse(InboundBlacklistService::deleteForUser('7831', (int) $bob[0]['id']));
        $this->assertCount(1, InboundBlacklistService::listForUser('7832'));
        $this->assertTrue(InboundBlacklistService::deleteForUser('7831', (int) $alice[0]['id']));
        $this->assertCount(0, InboundBlacklistService::listForUser('7831'));
    }

    public function testAnAdministratorCanTakeBlockingAwayFromOneUser(): void
    {
        $this->assertTrue(InboundBlacklistService::userMayBlock('7831'));
        $this->assertFalse(InboundBlacklistService::userMayBlock('7832'));
        $res = InboundBlacklistService::addForUser('7832', '15551110002');
        $this->assertFalse($res['success']);
        $this->assertCount(0, InboundBlacklistService::listForUser('7832'));
    }

    public function testTheDialplanChecksEachExtensionsOwnList(): void
    {
        syncGeneralDialplan();
        $conf = (string) @file_get_contents(ASTERISK_PBX_DIR . '/extensions_general.conf');
        // Alice's own list is consulted when a call reaches her, before anything rings.
        $this->assertStringContainsString('DB_EXISTS(blacklist_u/7831/${FILTER(0-9,${CALLERID(num)})})', $conf);
        $this->assertStringContainsString('[sub-user-blocked]', $conf);
        // In a ring group only the blocked member's leg ends; the others keep ringing.
        $this->assertStringContainsString('"${SKIP_VM}" = "1"]?quiet', $conf);
        // *86 is armed for Alice and not for Bob, whose administrator switched it off.
        $this->assertStringContainsString('Set(__BLOCK_OWNER=7831)', $conf);
        $this->assertStringNotContainsString('Set(__BLOCK_OWNER=7832)', $conf);
    }
}
