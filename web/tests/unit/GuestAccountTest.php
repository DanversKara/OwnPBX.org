<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncExtensions.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';
require_once dirname(__DIR__, 2) . '/src/services/GuestService.php';

/**
 * Guest accounts: emergency numbers are never dialled by a guest, outside calls only when
 * allowed, and a device a guest may not use has no SIP account. The dialplan gate itself was
 * also exercised in Asterisk 20 (Local channels, transfers, relays); these lock the pieces.
 */
final class GuestAccountTest extends TestCase
{
    public function testEmergencyAndShortNumbersAreAlwaysBlocked(): void
    {
        foreach (['911', '112', '999', '000', '110', '119', '10111', '933', '988', '9911', '1911', '+1 911',
            '0011911', '011112', '00112', '9-1-1', '19115'] as $n) {
            $this->assertTrue(GuestService::isBlockedNumber($n), "{$n} must be blocked for a guest");
        }
        foreach (['15595551234', '5595551234', '5551234', '+15595551234', '442071234567'] as $n) {
            $this->assertFalse(GuestService::isBlockedNumber($n), "{$n} is an ordinary number");
        }
    }

    public function testTheGateRunsBeforeEveryTrunkDial(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php');
        $dials = preg_match_all('/n,Dial\(PJSIP\/\\\\\$\{AIPBX_TRUNK_DIAL\}@/', $src);
        $gates = preg_match_all('/Gosub\(sub-guest-gate,s,1\(/', $src);
        $this->assertGreaterThan(0, $dials);
        $this->assertSame($dials, $gates, 'every trunk Dial() needs the guest gate right before it');
    }

    public function testGateDialplanListsGuests(): void
    {
        $conf = buildGuestGateDialplan(['1000' => false, '1001' => true], 'custom/guest-block-prompt');
        $this->assertMatchesRegularExpression('/\[aipbx-guest-list\]\nexten => 1000,1,Return\(\)\nexten => 1001,1,Return\(\)/', $conf);
        $this->assertMatchesRegularExpression('/\[aipbx-guest-out\]\nexten => 1001,1,Return\(\)\n\n/', $conf);
        $this->assertStringContainsString('Playback(custom/guest-block-prompt)', $conf);
        $this->assertStringContainsString('<= ' . GuestService::SHORT_MAX, $conf);
        $this->assertStringContainsString('BLINDTRANSFER', $conf);
        $this->assertStringContainsString('ATTENDEDTRANSFER', $conf);
        // No guests: the contexts still exist, so the gate loads.
        $this->assertStringContainsString('[sub-guest-gate]', buildGuestGateDialplan([], 'ss-noservice'));
    }

    public function testDevicesAGuestMayNotUseHaveNoSipAccount(): void
    {
        $block = "; Extension 1000: Guest\n"
            . "[1000-sip](endpoint-sip)\nauth=auth1000-sip\naors=1000-sip,1000\nset_var=__AIPBX_GUEST_EXT=1000\n\n"
            . "[1000-webrtc](endpoint-wss)\nauth=auth1000-webrtc\n\n"
            . "[1000-mob-webrtc](endpoint-wss)\nauth=auth1000-mob-webrtc\n\n"
            . "[auth1000-sip](auth-userpass)\nusername=1000\n\n"
            . "[1000-sip-identify]\ntype=identify\n\n"
            . "[auth1000-webrtc](auth-userpass)\n\n[auth1000-mob-webrtc](auth-userpass)\n\n"
            . "[1000-webrtc](aor-dual)\n\n[1000-mob-webrtc](aor-mobile)\n\n[1000-sip](aor-dual)\n\n[1000](aor-dual)\n\n";
        $out = guestEndpointFilter($block, '1000', ['mobil'], 'ulaw', 'opus,ulaw');
        $this->assertStringNotContainsString('[1000-sip]', $out);
        $this->assertStringNotContainsString('[auth1000-sip]', $out);
        $this->assertStringNotContainsString('[1000-sip-identify]', $out);
        $this->assertStringNotContainsString('[1000](aor-dual)', $out);
        $this->assertStringNotContainsString('[1000-webrtc]', $out);
        $this->assertStringContainsString("[1000-mob-webrtc](endpoint-wss)\ndisallow=all\nallow=opus,ulaw\n", $out, 'no video: audio codecs only');
        $this->assertStringContainsString('[auth1000-mob-webrtc]', $out);

        $all = guestEndpointFilter($block, '1000', ['web', 'mobil', 'sip', 'video'], 'ulaw', 'opus');
        $this->assertSame($block, $all, 'every device and video on: nothing changes');
    }

    public function testAdminsAreNeverGuests(): void
    {
        $p = GuestService::policy(['role' => 'admin', 'is_guest' => 1, 'guest_outbound' => 0]);
        $this->assertFalse($p['is_guest']);
        $this->assertTrue($p['guest_outbound']);
        $g = GuestService::policy(['role' => 'user', 'is_guest' => 1, 'guest_sms' => 1]);
        $this->assertTrue($g['is_guest']);
        $this->assertFalse($g['guest_outbound']);
        $this->assertTrue($g['guest_sms']);
        $this->assertTrue(GuestService::denies(['role' => 'user', 'is_guest' => 1], 'guest_fax'));
        $this->assertFalse(GuestService::denies(['role' => 'user', 'is_guest' => 0], 'guest_fax'));
    }
}
