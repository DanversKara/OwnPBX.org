<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/invites/lib/Invites.php';

/**
 * Invites: the status of a link and how its account is described. The full flow (make, accept
 * with every refusal, guest rules on the new user, one-person and reusable limits, withdraw) was
 * run against a test database.
 */
final class InvitesPluginTest extends TestCase
{
    public function testStatus(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->assertSame('open', Invites::status(['revoked' => 0, 'uses' => 0, 'max_uses' => 1, 'expires_at' => $future]));
        $this->assertSame('used', Invites::status(['revoked' => 0, 'uses' => 3, 'max_uses' => 3, 'expires_at' => $future]));
        $this->assertSame('expired', Invites::status(['revoked' => 0, 'uses' => 0, 'max_uses' => 5, 'expires_at' => date('Y-m-d H:i:s', time() - 5)]));
        $this->assertSame('withdrawn', Invites::status(['revoked' => 1, 'uses' => 0, 'max_uses' => 5, 'expires_at' => $future]));
    }

    public function testAccountText(): void
    {
        $this->assertSame('normal user', Invites::accountText(['is_guest' => 0]));
        $rules = json_encode(['guest_outbound' => 1, 'guest_inbound' => 0, 'guest_sms' => 1, 'guest_fax' => 0, 'phone_modes' => ['web', 'mobil']]);
        $this->assertSame('guest (outside calls, texts; web phone, mobile app)', Invites::accountText(['is_guest' => 1, 'guest_rules' => $rules]));
        $this->assertSame('guest (no outside access; no phones)', Invites::accountText(['is_guest' => 1, 'guest_rules' => '{}']));
    }
}
