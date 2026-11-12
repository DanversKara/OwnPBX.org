<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/CallerIdService.php';

/**
 * Outgoing numbers: DIDs given to extensions (one DID to many), the user's default, and the
 * per-call pick (X-OwnPBX-CID) that the dialplan accepts only from the extension's own list.
 */
final class CallerIdTest extends TestCase
{
    private int $alice = 0;
    private int $bob = 0;

    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE username IN ('cidalice', 'cidbob')");
        $db->exec('DELETE FROM pbx_user_caller_ids');
        $ins = $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active, cid_external) VALUES (?, 'x', ?, 'user', ?, 'sip', 1, '')");
        $ins->execute(['cidalice', 'Alice', '7801']);
        $this->alice = (int) $db->lastInsertId();
        $ins->execute(['cidbob', 'Bob', '7802']);
        $this->bob = (int) $db->lastInsertId();
        $did = $db->prepare("INSERT INTO pbx_dids (did_number, title, dest_type, dest_id, is_active) VALUES (?, ?, 'extension', '7801', 1)");
        $did->execute(['+15551230001', 'Main line']);
        $did->execute(['15551230002', 'Sales']);
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM sys_users WHERE username IN ('cidalice', 'cidbob')");
        getDB()->exec('DELETE FROM pbx_user_caller_ids');
    }

    public function testOnlyDidsCanBeGivenAndOneDidGoesToMany(): void
    {
        CallerIdService::assign($this->alice, ['15551230001', '15551230002', '15559999999']);
        CallerIdService::assign($this->bob, ['+15551230001']);
        // Stored as the DID is written; a number that is not a DID of this PBX is dropped.
        $this->assertSame(['+15551230001', '15551230002'], CallerIdService::assigned($this->alice));
        $this->assertSame(['+15551230001'], CallerIdService::assigned($this->bob));
    }

    public function testTicksSurviveADidRewrittenWithItsCountryCode(): void
    {
        CallerIdService::assign($this->alice, ['15551230002']);
        getDB()->exec("UPDATE pbx_dids SET did_number = '5551230002' WHERE did_number = '15551230002'");
        $this->assertSame(['5551230002'], CallerIdService::assigned($this->alice));
        // A default typed with the country code is still the same number: one choice, marked default.
        getDB()->prepare('UPDATE sys_users SET cid_external = ? WHERE id = ?')->execute(['+15551230002', $this->alice]);
        $choices = CallerIdService::choices($this->alice);
        $this->assertCount(1, $choices);
        $this->assertTrue($choices[0]['default']);
    }

    public function testDefaultMustBeOneOfTheirNumbers(): void
    {
        CallerIdService::assign($this->alice, ['15551230002']);
        try {
            CallerIdService::setDefault($this->alice, '15551230001'); // a DID, but not given to Alice
            $this->assertTrue(false, 'a number that is not hers was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not one of yours', $e->getMessage());
        }
        $this->assertSame('', (string) DBHelper::fetchColumn('SELECT cid_external FROM sys_users WHERE id = ?', [$this->alice]));

        getDB()->prepare('UPDATE sys_users SET cid_external = ? WHERE id = ?')->execute(['15551230002', $this->alice]);
        $choices = CallerIdService::choices($this->alice);
        $this->assertSame('15551230002', $choices[0]['number']);
        $this->assertTrue($choices[0]['default']);
        $this->assertSame('Sales', $choices[0]['title']);
    }

    public function testAllowedListCoversDefaultAndGivenNumbers(): void
    {
        CallerIdService::assign($this->alice, ['+15551230001']);
        $this->assertSame('-18005550000-15551230001-', CallerIdService::allowedVar($this->alice, '+1 800 555 0000'));
        $this->assertSame('', CallerIdService::allowedVar($this->bob, ''));
    }

    public function testDialplanAcceptsAPickedNumberOnlyFromTheList(): void
    {
        $lines = buildCallerIdPickLines();
        $this->assertStringContainsString('PJSIP_HEADER(read,X-OwnPBX-CID)', $lines);
        $this->assertStringContainsString('REGEX("-${FILTER(0-9,${CID_PICK})}-" ${CID_ALLOWED})', $lines);
        $this->assertStringContainsString('Set(CID_EXTERNAL=${CID_PICK})', $lines);
        // Not read on a Local channel (no headers there).
        $this->assertStringContainsString('"${CHANNEL(channeltype)}" != "PJSIP"', $lines);
    }
}
