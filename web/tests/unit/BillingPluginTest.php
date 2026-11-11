<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/billing/lib/Stripe.php';
require_once dirname(__DIR__, 3) . '/plugins/billing/lib/Billing.php';

/**
 * Billing plugin: Stripe request encoding, webhook signatures and plan descriptions. The full
 * flows (publish, checkout, webhook, switch/cancel, guest upgrade, Store approve/decline) were run
 * against a fake Stripe API, and the minutes limit in Asterisk 20 (cut at the exact second,
 * refused at none, usage recorded from ANSWEREDTIME).
 */
final class BillingPluginTest extends TestCase
{
    public function testStripeFormEncoding(): void
    {
        $this->assertSame([
            ['mode', 'subscription'],
            ['line_items[0][price]', 'price_1'],
            ['line_items[0][quantity]', '1'],
            ['metadata[pbx_user_id]', '7'],
            ['cancel_at_period_end', 'false'],
        ], BillingStripe::encode([
            'mode' => 'subscription',
            'line_items' => [['price' => 'price_1', 'quantity' => 1]],
            'metadata' => ['pbx_user_id' => 7],
            'description' => null,
            'cancel_at_period_end' => false,
        ]));
    }

    public function testModeFromKey(): void
    {
        $this->assertSame('test', BillingStripe::modeOf('sk_test_123'));
        $this->assertSame('live', BillingStripe::modeOf('rk_live_123'));
        $this->assertSame('', BillingStripe::modeOf('pk_test_123'));
    }

    public function testWebhookSignatures(): void
    {
        $payload = '{"id":"evt_1","type":"invoice.paid"}';
        $ts = 1700000000;
        $good = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, 'whsec_x');
        $this->assertSame('evt_1', BillingStripe::verifyWebhook($payload, $good, 'whsec_x', 300, $ts + 10)['id']);
        foreach ([
            ['t=' . $ts . ',v1=deadbeef', $ts],                    // wrong signature
            [$good, $ts + 1000],                                    // replayed later
            [$good . ',v1=x', $ts],                                 // extra v1 is fine only if one matches
        ] as $i => [$header, $now]) {
            if ($i === 2) {
                $this->assertSame('evt_1', BillingStripe::verifyWebhook($payload, $header, 'whsec_x', 300, $now)['id']);
                continue;
            }
            try {
                BillingStripe::verifyWebhook($payload, $header, 'whsec_x', 300, $now);
                $this->fail('accepted a bad webhook');
            } catch (BillingStripeError $e) {
                $this->assertTrue($e->getMessage() !== '');
            }
        }
        try {
            BillingStripe::verifyWebhook($payload, $good, '', 300, $ts);
            $this->fail('accepted a webhook without a signing secret');
        } catch (BillingStripeError $e) {
            $this->assertTrue(true);
        }
    }

    public function testPlanDescription(): void
    {
        $this->assertSame(['500 outside call minutes / month', 'Unlimited texts', 'Fax (10 / month)'],
            Billing::describe(['call_minutes' => 500, 'messages' => -1, 'fax' => 1, 'faxes' => 10]));
        $this->assertSame(['Unlimited outside calls'], Billing::describe(['call_minutes' => -1, 'messages' => 0, 'fax' => 0]));
        $this->assertSame('$9.99', Billing::money(999, 'usd'));
    }

    public function testOutboundRoutesAskBillingBeforeEveryTrunkDial(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php');
        $dials = preg_match_all('/n,Dial\(PJSIP\/\\\\\$\{AIPBX_TRUNK_DIAL\}@[^\n]*\\\\\$\{BILL_OPT\}\)/', $src);
        $this->assertSame(2, $dials, 'both trunk Dial() lines carry the billing limit');
        $this->assertSame(2, preg_match_all('/Gosub\(sub-billing-gate,s,1\(/', $src));
    }
}
