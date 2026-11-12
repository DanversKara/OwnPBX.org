<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/ha-texts/lib/HaTexts.php';

/**
 * Home Assistant texts: message ids Home Assistant can keep (it stores the last one seen in an
 * input_number, max 999999999, and counts larger ones as unread) and the allowed-extension list.
 * The inbox, the token and the endpoint answers were run against a test database.
 */
final class HaTextsPluginTest extends TestCase
{
    public function testIdsGrowWithTimeAcrossChatAndSmsAndStayUnderTheHomeAssistantLimit(): void
    {
        $chat = HaTexts::haId('2026-11-11 18:00:00', 0, 10);
        $sms = HaTexts::haId('2026-11-11 18:00:01', 1, 3);
        $later = HaTexts::haId('2026-11-11 18:00:02', 0, 11);
        $this->assertLessThan($sms, $chat);
        $this->assertLessThan($later, $sms);
        $this->assertNotSame(HaTexts::haId('2026-11-11 18:00:00', 0, 10), HaTexts::haId('2026-11-11 18:00:00', 1, 10));
        $this->assertLessThan(999999999, HaTexts::haId('2033-06-30 23:59:59', 1, 1));
    }

    public function testOnlyListedExtensions(): void
    {
        $this->assertSame(['8803', '611'], HaTexts::allowed(['extensions' => ' 8803, x611 ,']));
        $this->assertSame([], HaTexts::allowed(['extensions' => '']));
        $this->assertSame([], HaTexts::allowed([]));
    }
}
