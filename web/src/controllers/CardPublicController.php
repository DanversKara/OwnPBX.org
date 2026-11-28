<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/BusinessCardService.php';

/**
 * A digital business card, as the person you handed it to sees it (anonymous).
 *
 *   GET  /card/<token>          the card, or its PIN prompt
 *   GET  /card/<token>?k=<pin>  the same with the PIN already filled in - this is what the
 *                               NFC tag and the QR carry, so a tap opens the card outright
 *   POST /card/<token>          pin=<pin>, for a PIN typed by hand
 *
 * This is the only page on the PBX a stranger is meant to reach, so it is deliberately the
 * dullest code in the project: it reads one row and prints it. No session is used (one is
 * already open by the time index.php gets here, but nothing is read from or written to it),
 * no admin code is loaded, nothing from the URL is echoed, and the only script on the page
 * is the PBX's own widget.js - already carrying the WebRTC call path, so this adds no new
 * JavaScript and no new attack surface for the call button.
 *
 * On the PIN travelling in ?k=: that is what makes a tap work, and it is a deliberate
 * trade. A PIN in a URL can end up in a proxy log or a browser history, so the page sends
 * Referrer-Policy: no-referrer to keep it out of other sites' logs. It costs little either
 * way, because the 128-bit token in the same URL is already the real secret - the PIN is
 * there for the case where somebody photographs the QR over your shoulder and never sees
 * the tag, and for that it does its job.
 */
class CardPublicController
{
    public static function serve(string $path): void
    {
        // /card/<token> and /card/<token>/vcard. Only the first segment is the token; the
        // second is matched against one literal word and anything else is simply not the
        // vCard, so no part of the path is ever used to build a filename or a path.
        $parts = explode('/', trim(substr($path, strlen('/card')), '/'), 2);
        $token = $parts[0] ?? '';
        $suffix = $parts[1] ?? '';
        $wantsVcard = $suffix === 'vcard';
        if ($suffix !== '' && !$wantsVcard) {
            // One known suffix and nothing else. /card/<token>/anything-else is not a card
            // page with a decoration on the end, it is a URL this does not serve.
            self::headers();
            self::notFound();
        }
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // index.php has already opened a session by the time it gets here, and this page never
        // reads or writes one. Closing it and dropping the Set-Cookie keeps the PBX from
        // planting a tracking cookie in the browser of someone who was handed a card - they
        // did not ask for an account here. An admin who already has a session keeps it: PHP
        // only sends Set-Cookie when it starts a NEW session, so this drops nothing of theirs.
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header_remove('Set-Cookie');

        // Before anything else, and before a single byte of body. A page this exposed gets its
        // headers whether it is about to show a card or a refusal.
        self::headers();

        if ($method !== 'GET' && $method !== 'POST') {
            self::notFound();
        }
        // The card is off entirely when its plugin is disabled, same as the widget API.
        if (is_file(dirname(__DIR__) . '/core/PluginManager.php')) {
            require_once dirname(__DIR__) . '/core/PluginManager.php';
            $installed = PluginManager::installedMap();
            if (isset($installed['web-widgets']) && empty($installed['web-widgets']['enabled'])) {
                self::notFound();
            }
        }

        $pin = (string) ($_POST['pin'] ?? $_GET['k'] ?? '');
        // A PIN is digits. Trimming it to a sane length before it goes anywhere keeps a
        // megabyte of nonsense out of password_verify(), which is not free.
        $pin = preg_match('/^[0-9]{1,16}$/', trim($pin)) === 1 ? trim($pin) : '';

        $res = BusinessCardService::publicCard(
            $token,
            $pin,
            function_exists('getClientIP') ? getClientIP() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) ($_SERVER['HTTP_REFERER'] ?? '')
        );

        if ($res === null) {
            self::notFound();
        }
        if (isset($res['need_pin'])) {
            // A PIN-protected card hands out no contact file before the PIN, or the .vcf
            // would be a way round the PIN for everything the vCard carries.
            self::render(null, [], $token, $pin !== '');
        }
        if ($wantsVcard) {
            self::sendVcard($res['card'], $token);
        }
        self::render($res['card'], $res['fields'], $token, false);
    }

    /**
     * The headers that make this page safe to hand to the internet.
     *
     * script-src is the strict one and has no 'unsafe-inline': there is no inline script on
     * the page, so an injected <script> has nothing to run under. style-src does allow it,
     * because widget.js styles itself by creating a <style> element and setting its
     * textContent (widget.js:263) - that is the existing, tested call path and worth the
     * much smaller risk that inline CSS carries.
     *
     * frame-ancestors stops the card being framed by someone else's page and passed off as
     * theirs. noindex keeps it out of search results, which matters for a URL whose secrecy
     * is the whole access control. no-store keeps it out of shared caches.
     */
    private static function headers(): void
    {
        $self = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $wss = $self !== '' && preg_match('/^[A-Za-z0-9.\-:]+$/', $self) === 1 ? ' wss://' . $self : '';
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline';"
            . " img-src 'self' data:; font-src 'self'; connect-src 'self'" . $wss . ";"
            . " form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Permissions-Policy: microphone=(self), camera=(), geolocation=()');
        header('Content-Type: text/html; charset=utf-8');
    }

    /**
     * One answer for every reason there is nothing to show.
     *
     * Unknown token, malformed token, revoked, expired, past its view cap, a plugin switched
     * off: all of it arrives here. A scanner gets the same page and the same status every
     * time and so learns nothing about which tokens exist. The real reason is in the owner's
     * view log, where it belongs.
     */
    private static function notFound(): void
    {
        http_response_code(404);
        require dirname(__DIR__, 2) . '/templates/views/card_public/not_found.php';
        exit;
    }

    /**
     * Sends the card as a .vcf, so the person can keep the contact in their phone.
     *
     * The filename is built from the card's display name, not from anything in the URL, and
     * it is reduced to letters, digits, dashes and underscores before it goes into the
     * header - a quote or a newline in a Content-Disposition is how a header gets split.
     */
    private static function sendVcard(array $card, string $token): void
    {
        $vcf = BusinessCardService::vcard($card, $token);
        if ($vcf === '') {
            self::notFound();
        }
        $name = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($card['display_name'] ?? 'contact'));
        $name = trim($name, '-');
        if ($name === '') {
            $name = 'contact';
        }
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . substr($name, 0, 60) . '.vcf"');
        header('Content-Length: ' . (string) strlen($vcf));
        echo $vcf;
        exit;
    }

    /** @param array<string,mixed>|null $card null renders the PIN prompt */
    private static function render(?array $card, array $fields, string $token, bool $pinWasWrong): void
    {
        http_response_code(200);
        // The template reads these and nothing else. $token is echoed only into the form's
        // action, and only after publicCard() has already proved it is 32 hex characters.
        $cardToken = $token;
        $cardFields = $fields;
        $cardPinWrong = $pinWasWrong;
        if ($card === null) {
            require dirname(__DIR__, 2) . '/templates/views/card_public/pin.php';
            exit;
        }
        $cardWidget = self::widgetPublicId($card);
        $cardHasVcard = BusinessCardService::vcard($card, $token) !== '';
        require dirname(__DIR__, 2) . '/templates/views/card_public/card.php';
        exit;
    }

    /**
     * The public id of the widget this card calls through, or '' for no call button.
     *
     * Only the widget's public id reaches the page - the same identifier any website using
     * the widget already publishes. The SIP account and its secret stay behind
     * /widget-api/call, which hands out one-time credentials, so the number being called is
     * never in the card's source.
     */
    private static function widgetPublicId(array $card): string
    {
        if ((int) $card['call_enabled'] !== 1 || $card['widget_id'] === null) {
            return '';
        }
        if (BusinessCardService::callsToday((int) $card['id']) >= (int) $card['calls_daily_limit']
            && (int) $card['calls_daily_limit'] > 0) {
            return '';   // this card has had its calls for today; the rest of it still shows
        }
        $stmt = getDB()->prepare('SELECT public_id, is_active FROM pbx_web_widgets WHERE id = ?');
        $stmt->execute([(int) $card['widget_id']]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($w) || (int) $w['is_active'] !== 1) {
            return '';
        }
        return (string) $w['public_id'];
    }
}
