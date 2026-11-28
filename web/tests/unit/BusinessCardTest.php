<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/BusinessCardService.php';

/**
 * Digital business cards, tested from the attacking side.
 *
 * /card/<token> is the one page on this PBX a stranger is meant to reach, so these tests are
 * written as the things an attacker would try rather than as a tour of the happy path. Each
 * one names what would be true if it failed.
 */
final class BusinessCardTest extends TestCase
{
    /** An address of our own, so a throttle tripped here cannot spill into another test. */
    private const IP = '203.0.113.201';

    private int $cardId = 0;
    private string $token = '';

    protected function setUp(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_business_card_views WHERE ip LIKE '203.0.113.%'");
        $db->exec("DELETE FROM pbx_business_cards WHERE label LIKE 'phpunit%'");

        $this->token = bin2hex(random_bytes(16));
        $db->prepare(
            "INSERT INTO pbx_business_cards (label, token_hash, display_name, call_enabled, calls_daily_limit)
             VALUES ('phpunit card', ?, 'Test Person', 1, 3)"
        )->execute([hash('sha256', $this->token)]);
        $this->cardId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO pbx_business_card_fields (card_id, kind, label, value, sort_order)
             VALUES (?, 'link', 'Site', 'example.com', 1)"
        )->execute([$this->cardId]);
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_business_card_views WHERE ip LIKE '203.0.113.%'");
        $db->exec("DELETE FROM pbx_business_cards WHERE label LIKE 'phpunit%'");
        $db->exec("DELETE FROM sys_users WHERE username LIKE 'zz_tenant_%'");
        // The tenancy tests sign somebody in; leaving that behind would quietly scope every
        // later test in the run to one extension.
        unset($_SESSION['user_id'], $_SESSION['user_role']);
        if (class_exists('BillingRoleBridge')) {
            BillingRoleBridge::resetCache();
        }
    }

    private function open(string $token, string $pin = '', string $ip = self::IP): ?array
    {
        return BusinessCardService::publicCard($token, $pin, $ip, 'phpunit', '');
    }

    // --------------------------------------------------------------- it works at all

    public function testTheRightTokenOpensTheCard(): void
    {
        $res = $this->open($this->token);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('card', $res);
        $this->assertSame('Test Person', $res['card']['display_name']);
        $this->assertCount(1, $res['fields']);
    }

    // --------------------------------------------------------------- injection

    /**
     * An attack string must never reach a query.
     *
     * This is the claim the whole page rests on, so it is checked by what the service logged:
     * 'bad_token' is written by the branch BEFORE the database is touched, so seeing it for
     * every one of these proves the pattern check ran first. If any of them instead logged
     * 'not_found', the string had been sent to MariaDB and we would be relying on the
     * prepared statement alone.
     */
    public function testInjectionStringsAreRefusedBeforeAnyQueryRuns(): void
    {
        $attacks = [
            "' OR '1'='1",
            "' OR 1=1 --",
            "x' UNION SELECT token_hash, NULL, NULL FROM pbx_business_cards --",
            "'; DROP TABLE pbx_business_cards; --",
            '1 AND SLEEP(5)',
            '../../../../etc/passwd',
            '<script>alert(1)</script>',
            str_repeat('a', 5000),
            $this->token . "\0",                    // a valid token with a null byte appended
            strtoupper($this->token),               // right token, wrong case
            substr($this->token, 0, 31),            // one character short
            hash('sha256', $this->token),           // the stored hash, which is not the token
        ];
        foreach ($attacks as $attack) {
            $this->assertNull($this->open($attack), 'This must not open a card: ' . substr($attack, 0, 40));
        }

        $q = getDB()->prepare(
            "SELECT COUNT(*) FROM pbx_business_card_views WHERE ip = ? AND result = 'bad_token'"
        );
        $q->execute([self::IP]);
        $this->assertSame(
            count($attacks),
            (int) $q->fetchColumn(),
            'Every one of them must be refused by the pattern check, before a query is prepared.'
        );

        // And the tables are all still there.
        $this->assertSame(
            3,
            (int) getDB()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name LIKE 'pbx_business_card%'"
            )->fetchColumn()
        );
    }

    /** A time-based injection would have to run to be slow. Nothing here runs. */
    public function testASleepInjectionReturnsImmediately(): void
    {
        $started = microtime(true);
        $this->assertNull($this->open("1' AND SLEEP(5) AND '1'='1"));
        $this->assertLessThan(
            1.0,
            microtime(true) - $started,
            'A refusal that takes seconds means the payload reached the database.'
        );
    }

    // --------------------------------------------------------------- no oracle

    /**
     * Every reason a card will not open must look the same from outside.
     *
     * Anything else tells a scanner which tokens are real, or tells somebody whose card was
     * withdrawn that it ever existed.
     */
    public function testEveryRefusalIsIndistinguishable(): void
    {
        $db = getDB();
        $cases = [
            'unknown token' => bin2hex(random_bytes(16)),
            'malformed token' => 'nope',
        ];
        foreach ($cases as $what => $token) {
            $this->assertNull($this->open($token), $what);
        }

        foreach ([
            'revoked' => "UPDATE pbx_business_cards SET is_active = 0, revoked_at = NOW() WHERE id = ?",
            'inactive only' => "UPDATE pbx_business_cards SET is_active = 0, revoked_at = NULL WHERE id = ?",
            'expired' => "UPDATE pbx_business_cards SET is_active = 1, revoked_at = NULL, expires_at = (NOW() - INTERVAL 1 HOUR) WHERE id = ?",
        ] as $what => $sql) {
            $db->prepare($sql)->execute([$this->cardId]);
            $this->assertNull($this->open($this->token), "A {$what} card must answer exactly as an unknown one does.");
        }

        // The view cap, aged with SQL rather than a PHP-formatted string: the database's clock
        // is not necessarily PHP's, which is the bug that hid the admin PIN lockout.
        $db->prepare(
            'UPDATE pbx_business_cards
                SET is_active = 1, revoked_at = NULL, expires_at = NULL, max_views = 1, view_count = 1
              WHERE id = ?'
        )->execute([$this->cardId]);
        $this->assertNull($this->open($this->token), 'A card at its view cap must answer as an unknown one does.');
    }

    public function testAnExpiryInTheFutureStillOpens(): void
    {
        getDB()->prepare('UPDATE pbx_business_cards SET expires_at = (NOW() + INTERVAL 1 HOUR) WHERE id = ?')
            ->execute([$this->cardId]);
        $res = $this->open($this->token);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('card', $res);
    }

    public function testTheViewCapCountsRealViews(): void
    {
        getDB()->prepare('UPDATE pbx_business_cards SET max_views = 2, view_count = 0 WHERE id = ?')
            ->execute([$this->cardId]);
        $this->assertArrayHasKey('card', (array) $this->open($this->token), 'first view');
        $this->assertArrayHasKey('card', (array) $this->open($this->token), 'second view');
        $this->assertNull($this->open($this->token), 'The third view of a two-view card must be refused.');
    }

    // --------------------------------------------------------------- the PIN

    public function testAPinProtectedCardAsksAndThenOpens(): void
    {
        getDB()->prepare('UPDATE pbx_business_cards SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash('4821', PASSWORD_DEFAULT), $this->cardId]);

        $this->assertSame(['need_pin' => true], $this->open($this->token), 'No PIN supplied: ask for it.');
        $this->assertSame(['need_pin' => true], $this->open($this->token, '0000'), 'Wrong PIN: ask again.');
        $res = $this->open($this->token, '4821');
        $this->assertIsArray($res);
        $this->assertArrayHasKey('card', $res, 'The right PIN opens it.');
    }

    /** A revoked card stays shut even for somebody holding the right PIN. */
    public function testRevokedBeatsACorrectPin(): void
    {
        getDB()->prepare('UPDATE pbx_business_cards SET pin_hash = ?, is_active = 0, revoked_at = NOW() WHERE id = ?')
            ->execute([password_hash('4821', PASSWORD_DEFAULT), $this->cardId]);
        $this->assertNull($this->open($this->token, '4821'));
    }

    /**
     * Guessing a PIN has to stop, not merely slow down.
     *
     * Once the per-address limit is reached the card is refused outright - including to
     * somebody who then types the right PIN. That is the point: a limit that still lets the
     * correct answer through on attempt 5000 has not stopped anything.
     */
    public function testWrongPinsAreCappedAndThenEvenTheRightOneIsRefused(): void
    {
        getDB()->prepare('UPDATE pbx_business_cards SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash('4821', PASSWORD_DEFAULT), $this->cardId]);

        $prompts = 0;
        for ($i = 0; $i < 14; $i++) {
            if (isset($this->open($this->token, '9999')['need_pin'])) {
                $prompts++;
            }
        }
        $this->assertLessThan(14, $prompts, 'Wrong PINs must stop being answered at some point.');
        $this->assertNull(
            $this->open($this->token, '4821'),
            'Once the address is throttled the right PIN must be refused too, or guessing is only slowed.'
        );
    }

    // --------------------------------------------------------------- the stored secret

    /**
     * A copy of the database must not yield a working URL.
     *
     * Only the SHA-256 hash is stored, so what a dump contains cannot be used as a token.
     * This is why the admin page can only show the link once.
     */
    public function testTheDatabaseHoldsNoUsableToken(): void
    {
        $q = getDB()->prepare('SELECT token_hash FROM pbx_business_cards WHERE id = ?');
        $q->execute([$this->cardId]);
        $stored = (string) $q->fetchColumn();

        $this->assertNotSame($this->token, $stored, 'The token itself must never be stored.');
        $this->assertSame(hash('sha256', $this->token), $stored);
        $this->assertNull($this->open($stored), 'What is in the database must not work as a URL.');
    }

    // --------------------------------------------------------------- links

    /**
     * A link field may only become an href under four schemes.
     *
     * An allowlist, so the ones nobody has thought of yet are out too. 'javascript:' is the
     * obvious case; the rest are the ways people get it past a naive check.
     */
    public function testOnlySafeSchemesBecomeLinks(): void
    {
        foreach ([
            ['link', 'https://example.com/me'],
            ['link', 'example.com'],
            ['link', 'http://example.com'],
            ['link', 'mailto:me@example.com'],
            ['link', 'tel:+15550101234'],
            ['email', 'me@example.com'],
            ['phone', '+1 555 010 1234'],
            ['phone', '(555) 010-1234'],
        ] as [$kind, $value]) {
            $this->assertNotSame('', BusinessCardService::linkHref($kind, $value), "{$kind}: {$value}");
        }

        foreach ([
            ['link', 'javascript:alert(1)'],
            ['link', 'JaVaScRiPt:alert(1)'],
            ['link', "java\nscript:alert(1)"],
            ['link', 'data:text/html,<script>alert(1)</script>'],
            ['link', 'vbscript:msgbox'],
            ['link', 'file:///etc/passwd'],
            ['link', 'https://'],
            ['link', 'https://exa mple.com'],
            ['link', 'https://-bad-.com'],
            ['link', str_repeat('a', 600) . '.com'],
            ['email', 'not an email'],
            ['phone', '1234; rm -rf /'],
            ['text', 'https://example.com'],   // a text line is never a link
        ] as [$kind, $value]) {
            $this->assertSame(
                '',
                BusinessCardService::linkHref($kind, $value),
                "This must not become a link - {$kind}: " . str_replace("\n", '\n', substr($value, 0, 40))
            );
        }
    }

    /** A value that cannot be a link is refused while the owner is still looking at the form. */
    public function testSavingAHostileLinkIsRefused(): void
    {
        $this->expectException(\Exception::class);
        BusinessCardService::saveFields($this->cardId, [
            ['kind' => 'link', 'label' => 'Evil', 'value' => 'javascript:alert(1)'],
        ]);
    }

    public function testSavingFieldsKeepsOnlyKnownKinds(): void
    {
        BusinessCardService::saveFields($this->cardId, [
            ['kind' => 'text', 'label' => 'Met at', 'value' => 'Expo 2026'],
            ['kind' => 'nonsense', 'label' => 'x', 'value' => 'y'],
            ['kind' => 'text', 'label' => 'empty', 'value' => ''],
        ]);
        $fields = BusinessCardService::cardFields($this->cardId);
        $this->assertCount(1, $fields, 'An unknown kind and an empty value are both dropped.');
        $this->assertSame('Expo 2026', $fields[0]['value']);
    }

    // --------------------------------------------------------------- calling

    public function testTheCardsOwnDailyCallLimitHolds(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue(
                BusinessCardService::authorizeCall($this->token, self::IP, 'phpunit'),
                "call {$i} of a 3-call card"
            );
        }
        $this->assertFalse(
            BusinessCardService::authorizeCall($this->token, self::IP, 'phpunit'),
            'The fourth call on a three-call card must be refused.'
        );
    }

    public function testACallIsRefusedForEveryReasonACardIsShut(): void
    {
        $db = getDB();
        $db->prepare('UPDATE pbx_business_cards SET revoked_at = NOW(), is_active = 0 WHERE id = ?')->execute([$this->cardId]);
        $this->assertFalse(BusinessCardService::authorizeCall($this->token, self::IP, 'phpunit'), 'revoked');

        $db->prepare('UPDATE pbx_business_cards SET revoked_at = NULL, is_active = 1, call_enabled = 0 WHERE id = ?')->execute([$this->cardId]);
        $this->assertFalse(BusinessCardService::authorizeCall($this->token, self::IP, 'phpunit'), 'calls switched off');

        $this->assertFalse(BusinessCardService::authorizeCall("' OR 1=1 --", self::IP, 'phpunit'), 'injection');
        $this->assertFalse(BusinessCardService::authorizeCall(bin2hex(random_bytes(16)), self::IP, 'phpunit'), 'unknown token');
    }

    // --------------------------------------------------------------- the owner's side

    public function testReissuingKillsTheOldLinkAndKeepsTheCard(): void
    {
        $old = $this->token;
        $new = BusinessCardService::reissueCard($this->cardId);

        $this->assertNotSame($old, $new);
        $this->assertNull($this->open($old), 'The QR already printed must stop working.');
        $res = $this->open($new);
        $this->assertIsArray($res);
        $this->assertSame('Test Person', $res['card']['display_name'], 'The card itself survives.');
    }

    public function testRevokingClosesTheCardWithoutLosingItsLog(): void
    {
        $this->open($this->token);
        BusinessCardService::revokeCard($this->cardId);

        $this->assertNull($this->open($this->token));
        $this->assertNotEmpty(
            BusinessCardService::cardViews($this->cardId),
            'The log is how a leak is traced, so withdrawing a card must not erase it.'
        );
    }

    public function testDeletingACardKeepsItsViewsForTheRecord(): void
    {
        $this->open($this->token);
        $before = (int) getDB()->query('SELECT COUNT(*) FROM pbx_business_card_views')->fetchColumn();
        BusinessCardService::deleteCard($this->cardId);

        $this->assertNull(BusinessCardService::getCard($this->cardId));
        $this->assertSame(
            $before,
            (int) getDB()->query('SELECT COUNT(*) FROM pbx_business_card_views')->fetchColumn(),
            'A historical count must not be silently rewritten by a delete.'
        );
    }

    /** Creating a card hands the token back exactly once, and stores only its hash. */
    public function testCreateReturnsTheTokenOnceAndStoresTheHash(): void
    {
        $made = BusinessCardService::createCard([
            'label' => 'phpunit created',
            'display_name' => 'Someone',
            'is_active' => '1',
            'call_enabled' => '1',
        ]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $made['token']);

        $q = getDB()->prepare('SELECT token_hash, label FROM pbx_business_cards WHERE id = ?');
        $q->execute([$made['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(hash('sha256', $made['token']), $row['token_hash']);
        $this->assertSame('phpunit created', $row['label']);

        $res = $this->open($made['token'], '', '203.0.113.202');
        $this->assertIsArray($res);
        $this->assertArrayHasKey('card', $res);
    }

    public function testACardMustSayWhoItIsFor(): void
    {
        $this->expectException(\Exception::class);
        BusinessCardService::createCard(['label' => '   ']);
    }

    public function testAPinMustBeDigits(): void
    {
        $this->expectException(\Exception::class);
        BusinessCardService::createCard(['label' => 'phpunit pin', 'pin' => 'abcd']);
    }

    /** An empty PIN box on an edit means "leave it", never "remove it". */
    public function testSavingWithAnEmptyPinBoxKeepsTheExistingPin(): void
    {
        getDB()->prepare('UPDATE pbx_business_cards SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash('4821', PASSWORD_DEFAULT), $this->cardId]);

        BusinessCardService::updateCard($this->cardId, ['label' => 'phpunit card', 'pin' => '', 'is_active' => '1']);
        $this->assertNotSame('', (string) BusinessCardService::getCard($this->cardId)['pin_hash']);

        BusinessCardService::updateCard($this->cardId, ['label' => 'phpunit card', 'pin' => '', 'remove_pin' => '1', 'is_active' => '1']);
        $this->assertSame('', (string) BusinessCardService::getCard($this->cardId)['pin_hash'], 'Removing it is its own checkbox.');
    }

    /** The page is admin-only and the circuit breaker holds it there. */
    public function testTheAdminPageCannotBeDelegated(): void
    {
        $this->assertContains('business_cards', adminOnlyModuleKeys());
        foreach (['read_only_admin', 'user', 'cc_agent', 'fax_user'] as $role) {
            $this->assertFalse(
                hasModulePermissionForRole('business_cards', 'access', $role),
                "Issuing cards is an owner's decision, so {$role} must not reach the page."
            );
        }
        $this->assertTrue(hasModulePermissionForRole('business_cards', 'access', 'admin'));
    }

    /**
     * The public page must stay out of the portal's authenticated world.
     *
     * Read from the source rather than by rendering, because what matters is what the file
     * does NOT do: no portal layout, no session use, no admin includes. A card page that
     * started pulling in the portal's header would inherit its whole asset and script
     * surface, which is exactly what this feature is built to avoid.
     */
    public function testThePublicPageLoadsNothingFromThePortal(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/src/controllers/CardPublicController.php');
        foreach (['renderPage(', 'BaseController', 'layouts/header', 'requireLogin'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $controller,
                'The public card page must not reach into the authenticated portal: ' . $forbidden
            );
        }
        foreach (['Content-Security-Policy', "script-src 'self'", 'frame-ancestors', 'nosniff',
                  'no-referrer', 'noindex', 'no-store', 'header_remove'] as $required) {
            $this->assertStringContainsString($required, $controller, 'Missing protection: ' . $required);
        }

        foreach (['card.php', 'pin.php', 'not_found.php'] as $tpl) {
            $html = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/views/card_public/' . $tpl);
            $this->assertStringNotContainsString('layouts/header', $html, $tpl . ' must stay standalone.');
            $this->assertStringContainsString('noindex', $html, $tpl . ' must ask not to be indexed.');
        }
    }

    /** Values reach the page escaped, and the template never prints one raw. */
    public function testTheCardTemplateEscapesEveryValue(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/views/card_public/card.php');
        $this->assertStringContainsString('htmlspecialchars', $html);
        // Nothing may be echoed straight out of the row or the field list.
        $this->assertDoesNotMatchRegularExpression(
            '/echo\s+\$(card|f)\[/',
            $html,
            'Every value has to go through the escaper, never straight to output.'
        );
    }

    // --------------------------------------------------------------- sub-accounts

    /** Makes the viewer a billing-scoped plan user on $ext, and returns their id. */
    private function asPlanUser(string $ext): int
    {
        $db = getDB();
        $db->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, extension_type, is_active)
             VALUES (?, '', 'Tenancy test', ?, 'user', ?, 'sip', 1)
             ON DUPLICATE KEY UPDATE role = 'user', extension = VALUES(extension), is_active = 1"
        )->execute(['zz_tenant_' . $ext, "zz{$ext}@example.com", $ext]);
        $id = (int) $db->query("SELECT id FROM sys_users WHERE username = 'zz_tenant_{$ext}'")->fetchColumn();

        $_SESSION['user_id'] = $id;
        $_SESSION['user_role'] = 'user';
        BillingRoleBridge::resetCache();

        // The billing plugin is not loaded in the test run, so BillingBridge::load() is false
        // and no plan resolves. Seeding the plan-feature cache makes the bridge treat this as
        // a real plan user, so every path below is the production one.
        $prop = (new ReflectionClass('BillingRoleBridge'))->getProperty('planFeatures');
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        $cache[$id] = ['web_widgets', 'business_cards'];
        $prop->setValue(null, $cache);
        return $id;
    }

    private function cardOwnedBy(?string $ext, string $label): int
    {
        $db = getDB();
        $db->prepare(
            "INSERT INTO pbx_business_cards (label, owner_extension, token_hash, display_name)
             VALUES (?, ?, ?, 'X')"
        )->execute([$label, $ext, hash('sha256', bin2hex(random_bytes(16)))]);
        return (int) $db->lastInsertId();
    }

    /**
     * One account's cards must never appear in another's list.
     *
     * owner_extension is the column the billing role bridge already uses for time
     * conditions, queues, IVRs and the rest; NULL means admin-owned. An admin stays
     * unscoped and sees everything, which is what makes this safe to add to a live system
     * without rewriting who owns what.
     */
    public function testEachAccountSeesOnlyItsOwnCards(): void
    {
        $this->cardOwnedBy('7001', 'phpunit tenant a');
        $this->cardOwnedBy('7002', 'phpunit tenant b');
        $this->cardOwnedBy(null, 'phpunit tenant admin');

        try {
            $this->asPlanUser('7001');
            $labels = array_column(BusinessCardService::allCards(BillingRoleBridge::listOwnerExtension()), 'label');
            $this->assertSame(['phpunit tenant a'], $labels, 'A plan user sees their own card and nothing else.');

            $this->asPlanUser('7002');
            $labels = array_column(BusinessCardService::allCards(BillingRoleBridge::listOwnerExtension()), 'label');
            $this->assertSame(['phpunit tenant b'], $labels);
        } finally {
            unset($_SESSION['user_id'], $_SESSION['user_role']);
            BillingRoleBridge::resetCache();
            getDB()->exec("DELETE FROM sys_users WHERE username LIKE 'zz_tenant_%'");
        }
    }

    /**
     * A filtered list is not isolation on its own - the ids are sequential and guessable.
     *
     * So every read and every mutation is checked against the stored row too. "Not mine"
     * reads as "not there" rather than as an error, because answering "exists but is not
     * yours" would let one account enumerate another's cards by walking the ids.
     */
    public function testOneAccountCannotTouchAnothersCardById(): void
    {
        $mine = $this->cardOwnedBy('7001', 'phpunit tenant a');
        $theirs = $this->cardOwnedBy('7002', 'phpunit tenant b');
        $adminOwned = $this->cardOwnedBy(null, 'phpunit tenant admin');

        try {
            $this->asPlanUser('7001');

            $this->assertNotNull(BusinessCardService::getCard($mine), 'Their own card is readable.');
            $this->assertNull(BusinessCardService::getCard($theirs), "Another account's card must read as absent.");
            $this->assertNull(BusinessCardService::getCard($adminOwned), 'An admin-owned card is not theirs either.');
            $this->assertSame([], BusinessCardService::cardViews($theirs), 'Nor is its log.');
            $this->assertSame([], BusinessCardService::cardFields($theirs));

            foreach (['revokeCard', 'reissueCard', 'deleteCard'] as $op) {
                $refused = false;
                try {
                    BusinessCardService::$op($theirs);
                } catch (\Throwable $e) {
                    $refused = true;
                }
                $this->assertTrue($refused, "{$op}() on another account's card must be refused.");
            }
            // And it is still there.
            $q = getDB()->prepare('SELECT COUNT(*) FROM pbx_business_cards WHERE id = ?');
            $q->execute([$theirs]);
            $this->assertSame(1, (int) $q->fetchColumn(), "The other account's card must survive.");
        } finally {
            unset($_SESSION['user_id'], $_SESSION['user_role']);
            BillingRoleBridge::resetCache();
            getDB()->exec("DELETE FROM sys_users WHERE username LIKE 'zz_tenant_%'");
        }
    }

    /** An admin is unscoped, as before, or adding this would hide their own rows from them. */
    public function testAnAdminStillSeesEverything(): void
    {
        $this->cardOwnedBy('7001', 'phpunit tenant a');
        $this->cardOwnedBy(null, 'phpunit tenant admin');
        $_SESSION['user_role'] = 'admin';
        BillingRoleBridge::resetCache();

        $this->assertNull(BillingRoleBridge::listOwnerExtension(), 'An admin must not be scoped.');
        $labels = array_column(BusinessCardService::allCards(BillingRoleBridge::listOwnerExtension()), 'label');
        $this->assertContains('phpunit tenant a', $labels);
        $this->assertContains('phpunit tenant admin', $labels);
    }

    // --------------------------------------------------------------- save to contacts

    public function testTheVcardCarriesTheDetailsAndTheCardsOwnLink(): void
    {
        $vcf = BusinessCardService::vcard([
            'vcard_enabled' => 1,
            'display_name' => 'Kara Danvers',
            'job_title' => 'Owner',
            'company' => 'Example, Inc.',
            'vcard_first_name' => 'Kara',
            'vcard_last_name' => 'Danvers',
            'vcard_phone' => '+1 555 010 1234',
            'vcard_email' => 'kara@example.com',
        ], $this->token);

        $this->assertStringContainsString('BEGIN:VCARD', $vcf);
        $this->assertStringContainsString('VERSION:3.0', $vcf, '3.0 is what phones read without complaint.');
        $this->assertStringContainsString('N:Danvers;Kara;;;', $vcf, 'A phone files a contact by surname.');
        $this->assertStringContainsString('TEL;TYPE=WORK,VOICE:+1 555 010 1234', $vcf);
        $this->assertStringContainsString('ORG:Example\\, Inc.', $vcf, 'A comma is structure in a vCard and must be escaped.');
        $this->assertStringContainsString('/card/' . $this->token, $vcf, 'The card link travels with the contact.');
        $this->assertStringContainsString("\r\n", $vcf, 'RFC 6350 wants CRLF, and some address books insist.');
    }

    /** No name anywhere means no contact file, rather than an empty one. */
    public function testTheVcardFallsBackToTheNameOnTheCard(): void
    {
        $vcf = BusinessCardService::vcard(['vcard_enabled' => 1, 'display_name' => 'Kara Danvers'], $this->token);
        $this->assertStringContainsString('N:Danvers;Kara;;;', $vcf);
        $this->assertSame('', BusinessCardService::vcard(['vcard_enabled' => 1, 'display_name' => ''], $this->token));
        $this->assertSame('', BusinessCardService::vcard(['vcard_enabled' => 0, 'display_name' => 'Kara'], $this->token));
    }

    /**
     * A newline in a field must not start a new vCard property.
     *
     * This is the injection that matters in this format: htmlspecialchars is no use, because
     * a comma, a semicolon and a line break are structure here. Unescaped, a "note" becomes
     * arbitrary properties in somebody's address book - a premium-rate TEL, say, saved under
     * your name.
     */
    public function testAFieldCannotSmuggleInExtraVcardProperties(): void
    {
        $vcf = BusinessCardService::vcard([
            'vcard_enabled' => 1,
            'vcard_first_name' => 'A',
            'vcard_last_name' => 'B',
            'vcard_note' => "harmless\r\nTEL;TYPE=WORK:+1-900-PREMIUM\r\nX-EVIL:1",
            'vcard_email' => "x@example.com\nURL:http://evil.example",
        ], $this->token);

        $props = [];
        foreach (explode("\r\n", $vcf) as $line) {
            if ($line !== '' && preg_match('/^([A-Z][A-Z-]*)/', $line, $m) === 1) {
                $props[] = $m[1];
            }
        }
        $this->assertNotContains('TEL', $props, 'A TEL smuggled through a note must not become a property.');
        $this->assertStringNotContainsString("\r\nX-EVIL", $vcf);
        $this->assertStringNotContainsString("\r\nURL:http://evil.example", $vcf);
        $this->assertStringContainsString('\\n', $vcf, 'The line breaks survive as escaped text inside the value.');
    }
}
