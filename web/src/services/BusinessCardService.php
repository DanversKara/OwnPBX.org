<?php

declare(strict_types=1);

/**
 * Digital business cards: the card you hand to one person, and nothing else.
 *
 * THE PUBLIC SIDE IS THE WHOLE POINT OF THIS CLASS. /card/<token> is reachable by
 * anyone on the internet with no session, so it is written to be boring: it reads one
 * row and renders it. The rules below are not decoration, they are why the page is
 * safe to expose, and each says what it is defending against.
 *
 * 1. The token is checked against a strict pattern BEFORE any database access
 *    (publicCard()). Anything that is not exactly 32 lowercase hex characters is
 *    refused without a query ever being prepared. This is the measure that answers
 *    "can someone probe for SQL injection here?": an injection string is not
 *    escaped-and-queried, it never reaches a query at all, because it cannot be 32
 *    hex characters and an attack string at the same time.
 *
 * 2. The token is stored only as its SHA-256 hash and looked up by that hash through
 *    a prepared statement, as plg_invites does. A stolen copy of the database yields
 *    no working card URLs.
 *
 * 3. Every refusal returns the same thing - unknown token, malformed token, revoked,
 *    expired, over its view cap, PIN wrong. A scanner learns nothing about which
 *    tokens exist, so there is no oracle to walk even if 128 bits were walkable.
 *
 * 4. The public path reads. The only writes it can cause are an append to the view
 *    log and a counter bump on its own row, both parameterised and neither taking a
 *    single byte of attacker-controlled content into a column that is ever executed
 *    or interpolated.
 *
 * 5. Nothing from the URL or the query string is echoed. The page's content comes
 *    from rows the owner typed in the admin page, escaped on output, and link values
 *    are restricted to an allowlist of schemes (linkHref()) so a stored
 *    'javascript:' can never become a working link.
 *
 * What this class deliberately does NOT do: no search, no listing by anything a
 * visitor supplies, no ordering or filtering from input, no file paths, no shell, no
 * includes built from data. The public surface is one row by one hash.
 */
class BusinessCardService
{
    /** A card token as it appears in a URL: bin2hex(random_bytes(16)). */
    public const TOKEN_RE = '/^[a-f0-9]{32}$/';

    /** Field kinds. A kind decides how a value is rendered, so it is never free text. */
    public const KINDS = ['text', 'link', 'email', 'phone'];

    /**
     * Schemes a link field may use.
     *
     * An allowlist, not a blocklist: 'javascript:' and 'data:' are the obvious ones to
     * keep out, but so is everything nobody has thought of yet.
     */
    public const LINK_SCHEMES = ['https', 'http', 'mailto', 'tel'];

    /** Views allowed from one address per hour, across all cards. */
    private const IP_HOURLY_LIMIT = 60;

    /** Wrong PINs from one address per hour before it is refused outright. */
    private const PIN_FAILS_PER_HOUR = 10;

    /** How long the view log is kept. */
    private const LOG_KEEP_DAYS = 365;

    // ---------------------------------------------------------------- the public side

    /**
     * The card behind a token, or null - and null means "no", never why.
     *
     * $pin is whatever the visitor supplied, '' when they supplied nothing. The answer
     * is ['card' => array, 'fields' => list<array>] on success, or one of the two
     * refusals: null for "there is nothing here", or ['need_pin' => true] for a card
     * that exists and wants its PIN. That second one does leak the existence of a
     * card, which is unavoidable - a PIN prompt has to be shown to somebody - and it
     * is reached only by presenting a valid 128-bit token in the first place.
     *
     * @return array{card: array<string,mixed>, fields: list<array<string,mixed>>}|array{need_pin: true}|null
     */
    public static function publicCard(string $token, string $pin, string $ip, string $ua, string $referer): ?array
    {
        // RULE 1. Before the database. An attack string cannot also be 32 hex characters,
        // so this is where probing for injection stops - not at the escaping.
        if (preg_match(self::TOKEN_RE, $token) !== 1) {
            self::logView(null, 'bad_token', $ip, $ua, $referer);
            return null;
        }
        if (!self::ipWithinLimit($ip)) {
            self::logView(null, 'rate_ip', $ip, $ua, $referer);
            return null;
        }

        // RULE 2. By hash, bound, never concatenated.
        $stmt = getDB()->prepare('SELECT * FROM pbx_business_cards WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($card)) {
            self::logView(null, 'not_found', $ip, $ua, $referer);
            return null;
        }

        // RULE 3. One answer for every reason a card will not open. The reason is written
        // to the log, which only the owner can read, and never to the response.
        $closed = self::closedReason($card);
        if ($closed !== '') {
            self::logView((int) $card['id'], $closed, $ip, $ua, $referer);
            return null;
        }

        if ((string) $card['pin_hash'] !== '') {
            if ($pin === '') {
                return ['need_pin' => true];
            }
            if (!self::pinFailsWithinLimit($ip)) {
                self::logView((int) $card['id'], 'rate_pin', $ip, $ua, $referer);
                return null;
            }
            if (!password_verify($pin, (string) $card['pin_hash'])) {
                self::logView((int) $card['id'], 'pin_failed', $ip, $ua, $referer);
                return ['need_pin' => true];
            }
        }

        self::countView((int) $card['id']);
        self::logView((int) $card['id'], 'view', $ip, $ua, $referer);
        return ['card' => $card, 'fields' => self::fields((int) $card['id'])];
    }

    /**
     * Why this card will not open, as a log keyword, or '' when it will.
     *
     * Checked in one place so the public page cannot accidentally honour a card that
     * one of these should have closed.
     */
    private static function closedReason(array $card): string
    {
        if ((int) $card['is_active'] !== 1 || $card['revoked_at'] !== null) {
            return 'revoked';
        }
        // UNIX_TIMESTAMP is not available here, so the comparison is done in SQL-free form
        // on a value this class wrote. strtotime() on a DATETIME string reads it in PHP's
        // timezone, which is not necessarily the database's - so expiry is stored and
        // compared as a date string, never as an epoch difference.
        $expires = (string) ($card['expires_at'] ?? '');
        if ($expires !== '' && $expires < date('Y-m-d H:i:s')) {
            return 'expired';
        }
        $max = (int) $card['max_views'];
        if ($max > 0 && (int) $card['view_count'] >= $max) {
            return 'view_cap';
        }
        return '';
    }

    /** The lines on a card, in the owner's order. */
    private static function fields(int $cardId): array
    {
        $stmt = getDB()->prepare(
            'SELECT kind, icon, label, value FROM pbx_business_card_fields
              WHERE card_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$cardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The href for a link field, or '' when it may not be linked.
     *
     * RULE 5. Values come from the owner, but "the owner typed it" is not a reason to
     * put an arbitrary scheme into an href - a mistake, or an account that is not as
     * trusted tomorrow as it is today, should not turn into script running in a
     * visitor's browser. Only the four schemes in LINK_SCHEMES survive, and a bare
     * 'example.com' is read as https rather than guessed at.
     */
    public static function linkHref(string $kind, string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 500) {
            return '';
        }

        if ($kind === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:' . $value : '';
        }
        if ($kind === 'phone') {
            // Spaces and brackets are how people write phone numbers, so they are allowed in
            // what is shown and stripped out of the tel: href. Note this runs before the URL
            // control-character check below, which rejects spaces - a phone number is not a
            // URL and must not be held to a URL's rules.
            return preg_match('/^\+?[0-9 ().-]{3,32}$/', $value) === 1
                ? 'tel:' . preg_replace('/[^0-9+]/', '', $value)
                : '';
        }
        if ($kind !== 'link') {
            return '';
        }

        // From here it is a URL. Control characters and spaces are how "java\nscript:" slips
        // past a naive scheme check, and a URL has no legitimate use for either.
        if (preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return '';
        }
        if (!str_contains($value, ':')) {
            $value = 'https://' . $value;   // a bare host, read as https rather than guessed
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if ($scheme === '' || !in_array($scheme, self::LINK_SCHEMES, true)) {
            return '';
        }
        if (in_array($scheme, ['http', 'https'], true)) {
            // The host is checked against what a host may contain, not merely for being
            // non-empty. Prefixing 'https://' onto anything turns junk into a link that looks
            // real - 'javascript&colon;alert(1)' became 'https://javascript&colon;alert(1)' -
            // and a card is printed on paper, so a link that cannot work should be refused
            // while its owner is still looking at the form.
            $host = (string) parse_url($value, PHP_URL_HOST);
            if (preg_match('/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/', $host) !== 1) {
                return '';
            }
        }
        return $value;
    }

    // ---------------------------------------------------------------- limits and the log

    private static function ipWithinLimit(string $ip): bool
    {
        return self::recentCount($ip, ['view', 'not_found', 'bad_token'], self::IP_HOURLY_LIMIT);
    }

    private static function pinFailsWithinLimit(string $ip): bool
    {
        return self::recentCount($ip, ['pin_failed'], self::PIN_FAILS_PER_HOUR);
    }

    /** True while this address is still under $limit of these results in the last hour. */
    private static function recentCount(string $ip, array $results, int $limit): bool
    {
        if ($ip === '') {
            return true;
        }
        // The IN list is built from the hardcoded arrays above, never from input: the
        // placeholders are generated, the values are bound.
        $marks = implode(',', array_fill(0, count($results), '?'));
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM pbx_business_card_views
              WHERE ip = ? AND result IN ({$marks}) AND created_at >= (NOW() - INTERVAL 1 HOUR)"
        );
        $stmt->execute(array_merge([$ip], $results));
        return (int) $stmt->fetchColumn() < $limit;
    }

    /**
     * Append one line to the view log. Never throws into the public page.
     *
     * A card that cannot be logged is still served: the log exists so the owner can
     * trace a leak, and losing one line of it is a smaller harm than a visitor seeing
     * a stack trace from a page whose whole job is to be inert.
     */
    public static function logView(?int $cardId, string $result, string $ip, string $ua, string $referer): void
    {
        try {
            getDB()->prepare(
                'INSERT INTO pbx_business_card_views (card_id, result, ip, user_agent, referer)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $cardId,
                substr($result, 0, 20),
                substr($ip, 0, 45),
                substr($ua, 0, 255),
                substr($referer, 0, 255),
            ]);
            if (random_int(1, 200) === 1) {
                getDB()->prepare(
                    'DELETE FROM pbx_business_card_views WHERE created_at < (NOW() - INTERVAL ? DAY)'
                )->execute([self::LOG_KEEP_DAYS]);
            }
        } catch (\Throwable $e) {
            error_log('business card log: ' . $e->getMessage());
        }
    }

    private static function countView(int $cardId): void
    {
        try {
            getDB()->prepare(
                'UPDATE pbx_business_cards SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = ?'
            )->execute([$cardId]);
        } catch (\Throwable $e) {
            error_log('business card view count: ' . $e->getMessage());
        }
    }

    /** Calls already placed from this card today, for its own daily limit. */
    public static function callsToday(int $cardId): int
    {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM pbx_business_card_views
              WHERE card_id = ? AND result = 'call' AND created_at >= CURDATE()"
        );
        $stmt->execute([$cardId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * May this card place a call right now? Records it when yes.
     *
     * Called from /widget-api/call when the page passes a card token, which is how a call
     * made from a card counts against THAT card rather than only against the widget. It is
     * what turns "a card per person" into usable spam control: one recipient calling too
     * often shows up under their card, and revoking that card stops it without touching
     * anybody else's.
     *
     * Worth being straight about what this is not. The widget's public id is in the card's
     * HTML - it has to be, that is how widget.js works - so somebody who reads the page
     * source can post to /widget-api/call without a card token and bypass this. The hard
     * ceiling therefore remains the widget's own daily and per-IP limits, which that path
     * still goes through. This limit is what stops ordinary over-calling and what gives the
     * owner per-card visibility; it is not a security boundary and is not relied on as one.
     */
    public static function authorizeCall(string $token, string $ip, string $ua): bool
    {
        if (preg_match(self::TOKEN_RE, $token) !== 1) {
            return false;
        }
        $stmt = getDB()->prepare('SELECT * FROM pbx_business_cards WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($card) || self::closedReason($card) !== '' || (int) $card['call_enabled'] !== 1) {
            return false;
        }
        $limit = (int) $card['calls_daily_limit'];
        if ($limit > 0 && self::callsToday((int) $card['id']) >= $limit) {
            self::logView((int) $card['id'], 'call_capped', $ip, $ua, '');
            return false;
        }
        self::logView((int) $card['id'], 'call', $ip, $ua, '');
        return true;
    }

    // ---------------------------------------------------------------- pictures

    /** Where card images live. Served as /assets/images/cards/<file>, like the brand logos. */
    public const IMAGE_DIR = '/assets/images/cards';

    /** The two pictures a card can carry, and the size each is reduced to. */
    private const IMAGE_SPECS = [
        'avatar' => ['w' => 512, 'h' => 512],
        'cover'  => ['w' => 1200, 'h' => 400],
    ];

    private const IMAGE_MAX_BYTES = 6 * 1024 * 1024;

    public static function imageDir(): string
    {
        return dirname(__DIR__, 2) . self::IMAGE_DIR;
    }

    /** The public URL for a stored card image, or '' - never a path from the database. */
    public static function imageUrl(string $file): string
    {
        // The column holds a bare generated filename. Anything else is treated as absent
        // rather than printed, so a row that somehow held "../../etc/passwd" links nowhere.
        if (preg_match('/^card[0-9]+-(avatar|cover)-[a-f0-9]{16}\.(jpg|png)$/', $file) !== 1) {
            return '';
        }
        return self::IMAGE_DIR . '/' . $file;
    }

    /**
     * Stores an uploaded picture for a card and returns its filename, or '' when none came.
     *
     * The file is not trusted and not merely checked - it is re-encoded. getimagesize() says
     * what it really is rather than what it is called, and then GD reads it and writes a
     * fresh file from the pixels. That drops EXIF, colour profiles, trailing archives and
     * anything else riding along, and it is what makes a polyglot (a file that is a valid
     * JPEG and a valid something-else at once) land on disk as an ordinary JPEG.
     *
     * SVG is deliberately not accepted. A logo uploaded by an administrator is one thing;
     * these can be uploaded by a sub-account, SVG is XML that can carry script, and scrubbing
     * it with regular expressions is a game nobody wins. GIF is out too, for APNG/GIF
     * animation payload surface we have no reason to take on.
     *
     * The filename is generated. Nothing the uploader sent reaches the disk - not the name,
     * not the extension - so there is no path to traverse and no second extension to smuggle.
     */
    public static function saveImage(string $fileKey, int $cardId, string $kind): string
    {
        if (!isset(self::IMAGE_SPECS[$kind])) {
            throw new \InvalidArgumentException('Unknown image kind.');
        }
        if (!isset($_FILES[$fileKey]) || ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        $f = $_FILES[$fileKey];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception(sprintf(t('cards.err_upload'), (int) $f['error']));
        }
        if ((int) $f['size'] > self::IMAGE_MAX_BYTES) {
            throw new \Exception(sprintf(t('cards.err_image_big'), (int) round(self::IMAGE_MAX_BYTES / 1048576)));
        }
        // is_uploaded_file, so a path cannot be pointed at a local file by a crafted request.
        if (!is_uploaded_file($f['tmp_name'])) {
            throw new \Exception(t('cards.err_image_bad'));
        }

        $info = @getimagesize($f['tmp_name']);
        if (!is_array($info)) {
            throw new \Exception(t('cards.err_image_bad'));
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
            IMAGETYPE_PNG  => @imagecreatefrompng($f['tmp_name']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']),
            default => null,
        };
        if (!$src instanceof \GdImage) {
            throw new \Exception(t('cards.err_image_type'));
        }

        try {
            $spec = self::IMAGE_SPECS[$kind];
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1.0, $spec['w'] / max(1, $w), $spec['h'] / max(1, $h));
            if ($scale < 1.0) {
                $resized = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
                if ($resized instanceof \GdImage) {
                    imagedestroy($src);
                    $src = $resized;
                }
            }

            // PNG keeps transparency, which a cut-out photo or a logo needs; everything else
            // becomes JPEG, which is smaller for a photograph.
            $png = $info[2] === IMAGETYPE_PNG;
            $name = sprintf('card%d-%s-%s.%s', $cardId, $kind, bin2hex(random_bytes(8)), $png ? 'png' : 'jpg');
            $dir = self::imageDir();
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \Exception(sprintf(t('cards.err_image_write'), $dir));
            }
            $target = $dir . '/' . $name;
            $ok = $png ? imagepng($src, $target, 6) : imagejpeg($src, $target, 85);
            if (!$ok) {
                throw new \Exception(sprintf(t('cards.err_image_write'), $dir));
            }
            @chmod($target, 0644);
            return $name;
        } finally {
            if ($src instanceof \GdImage) {
                imagedestroy($src);
            }
        }
    }

    /**
     * Applies the picture part of a save: new uploads, and the two remove boxes.
     *
     * Run after the row exists, so the generated filename can carry the card's id. The old
     * file is deleted only once the new one is safely written, and never when the upload
     * failed - a failed upload must not leave a card with no picture.
     */
    public static function applyImageUploads(int $cardId, array $post): void
    {
        $card = self::getCard($cardId);
        if ($card === null) {
            return;
        }
        $set = [];
        foreach (['avatar', 'cover'] as $kind) {
            $col = $kind . '_file';
            $current = (string) ($card[$col] ?? '');
            $new = self::saveImage($kind . '_file', $cardId, $kind);
            if ($new !== '') {
                $set[$col] = $new;
                self::deleteImage($current);
                continue;
            }
            if (!empty($post['remove_' . $kind])) {
                $set[$col] = '';
                self::deleteImage($current);
            }
        }
        if ($set === []) {
            return;
        }
        $sets = implode(' = ?, ', array_keys($set)) . ' = ?';
        getDB()->prepare("UPDATE pbx_business_cards SET {$sets} WHERE id = ?")
            ->execute(array_merge(array_values($set), [$cardId]));
    }

    /** Deletes a card's stored picture, if the name is one this class would have written. */
    public static function deleteImage(string $file): void
    {
        if (self::imageUrl($file) === '') {
            return;
        }
        @unlink(self::imageDir() . '/' . $file);
    }

    // ---------------------------------------------------------------- save to contacts

    /**
     * The card as a vCard 3.0, for "save to my phone", or '' when it is switched off.
     *
     * Version 3.0 rather than 4.0 deliberately: iOS and Android both read 3.0 without
     * complaint, while 4.0 is still patchily handled, and a contact file that half the
     * phones you hand a card to cannot open is worse than none.
     *
     * The card's own URL goes in as well, so whoever saved you can reopen the page later -
     * the links, the call button, whatever you have added since. That is the one piece of
     * information in here that is not already on the page, and it is their own link.
     *
     * Nothing is published that the page does not already show. The fields are the owner's
     * own, and the only values that appear are the ones they typed.
     */
    public static function vcard(array $card, string $token): string
    {
        if ((int) ($card['vcard_enabled'] ?? 1) !== 1) {
            return '';
        }
        $first = trim((string) ($card['vcard_first_name'] ?? ''));
        $last = trim((string) ($card['vcard_last_name'] ?? ''));
        if ($first === '' && $last === '') {
            // Nothing filled in: fall back to the name on the card, splitting on the last
            // space so "Kara Danvers" files under Danvers, as an address book expects.
            $display = trim((string) ($card['display_name'] ?? ''));
            if ($display === '') {
                return '';
            }
            $pos = strrpos($display, ' ');
            if ($pos === false) {
                $first = $display;
            } else {
                $first = substr($display, 0, $pos);
                $last = substr($display, $pos + 1);
            }
        }

        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'N:' . self::vcardEscape($last) . ';' . self::vcardEscape($first) . ';;;';
        $lines[] = 'FN:' . self::vcardEscape(trim($first . ' ' . $last));
        foreach ([
            'TITLE' => $card['job_title'] ?? '',
            'ORG' => $card['company'] ?? '',
            'TEL;TYPE=WORK,VOICE' => $card['vcard_phone'] ?? '',
            'TEL;TYPE=CELL,VOICE' => $card['vcard_mobile'] ?? '',
            'EMAIL;TYPE=INTERNET,WORK' => $card['vcard_email'] ?? '',
            'ADR;TYPE=WORK' => $card['vcard_address'] ?? '',
            'NOTE' => $card['vcard_note'] ?? '',
        ] as $field => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            // An address is structured; a one-line address goes in the street component.
            $lines[] = str_starts_with($field, 'ADR')
                ? $field . ':;;' . self::vcardEscape($value) . ';;;;'
                : $field . ':' . self::vcardEscape($value);
        }
        $site = trim((string) ($card['vcard_website'] ?? ''));
        if ($site !== '') {
            $href = self::linkHref('link', $site);
            if ($href !== '') {
                $lines[] = 'URL;TYPE=WORK:' . self::vcardEscape($href);
            }
        }
        $lines[] = 'URL;TYPE=HOME:' . self::vcardEscape(self::cardUrl($token));
        $lines[] = 'REV:' . gmdate('Ymd\\THis\\Z');
        $lines[] = 'END:VCARD';

        // CRLF between lines: RFC 6350 requires it, and some address books refuse a file
        // without it even though every text editor shows the same thing.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Escapes one vCard value.
     *
     * vCard has its own rules and htmlspecialchars is no use here: a comma, a semicolon or a
     * backslash is structure in this format, and a newline would end the property and let
     * whatever followed be read as a new one - which is how a "note" field turns into
     * arbitrary vCard properties in somebody's address book. Backslash first, or it would
     * escape the escapes added after it.
     */
    private static function vcardEscape(string $v): string
    {
        $v = str_replace("\\", "\\\\", $v);
        $v = str_replace(["\r\n", "\n", "\r"], '\\n', $v);
        return str_replace([';', ','], ['\\;', '\\,'], $v);
    }

    // ---------------------------------------------------------------- the owner's side

    /** The full URL a QR, an NFC tag or a short link points at. */
    public static function cardUrl(string $token, string $pin = ''): string
    {
        require_once __DIR__ . '/ForgotPasswordService.php';
        $url = rtrim(ForgotPasswordService::portalUrl(), '/') . '/card/' . $token;
        // ?k= is what lets a tap open a PIN-protected card without typing. Only offered for
        // the tag and the QR; the printed short link is given without it so that a photograph
        // of the card still needs the PIN.
        return $pin !== '' ? $url . '?k=' . rawurlencode($pin) : $url;
    }

    /** The card URL as an SVG data URI, for printing. '' when php-qrcode is unavailable. */
    public static function renderQr(string $url): string
    {
        if (!class_exists(\chillerlan\QRCode\QRCode::class)) {
            return '';
        }
        try {
            $options = new \chillerlan\QRCode\QROptions([
                'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
                'outputBase64' => true,
                'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
                'addQuietzone' => true,
                'scale' => 6,
            ]);
            return (new \chillerlan\QRCode\QRCode($options))->render($url);
        } catch (\Throwable $e) {
            error_log('business card qr: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * The cards this viewer may see, newest first, with their view and call counts.
     *
     * $owner is BillingRoleBridge::listOwnerExtension(): null for an admin, who sees every
     * card, and a string for one account's. An empty string matches nothing on purpose - a
     * plan user with no extension gets an empty list rather than everyone's cards.
     */
    public static function allCards(?string $owner = null): array
    {
        $where = '';
        $args = [];
        if ($owner !== null) {
            $where = 'WHERE c.owner_extension = ?';
            $args = [$owner];
        }
        $stmt = getDB()->prepare(
            "SELECT c.*, w.name AS widget_name, w.public_id AS widget_public_id,
                    (SELECT COUNT(*) FROM pbx_business_card_views v
                      WHERE v.card_id = c.id AND v.result = 'call' AND v.created_at >= CURDATE()) AS calls_today
             FROM pbx_business_cards c
             LEFT JOIN pbx_web_widgets w ON w.id = c.widget_id
             {$where} ORDER BY c.id DESC"
        );
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * One card, or null when it is not this viewer's to see.
     *
     * Not-mine reads as not-there rather than as an error: the editor and the view log both
     * come in by id from a query string, and answering "that exists but is not yours" would
     * let one account enumerate another's cards by walking the ids.
     */
    public static function getCard(int $id): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pbx_business_cards WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (class_exists('BillingRoleBridge')
            && !BillingRoleBridge::mayTouchOwner($row['owner_extension'] ?? null)) {
            return null;
        }
        return $row;
    }

    /**
     * Refuses unless this card is the viewer's to touch.
     *
     * Checked against the stored row rather than anything the form sent, so posting another
     * account's card id is refused instead of obeyed. An admin is unscoped and always passes;
     * a plan user passes only on their own rows. Called by every operation that changes a
     * card - a list that is filtered but mutations that are not would be no isolation at all,
     * because the ids are sequential and guessable.
     */
    private static function assertMine(int $id): void
    {
        if (class_exists('BillingRoleBridge')) {
            BillingRoleBridge::assertMayTouchRow('pbx_business_cards', $id);
        }
    }

    /** The owner-facing field list for a card (the public page has its own, narrower read). */
    public static function cardFields(int $id): array
    {
        if (self::getCard($id) === null) {
            return [];
        }
        $stmt = getDB()->prepare(
            'SELECT * FROM pbx_business_card_fields WHERE card_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** The view log for one card, newest first. */
    public static function cardViews(int $id, int $limit = 100): array
    {
        // Through getCard(), so a card that is not the viewer's has no readable log either.
        if (self::getCard($id) === null) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        // Interpolated because LIMIT cannot be bound on every driver; it is an int clamped to
        // a range one line above, so no input reaches the statement.
        $stmt = getDB()->prepare(
            "SELECT * FROM pbx_business_card_views WHERE card_id = ?
              ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Creates a card and returns [id, token]. The token is returned ONCE and never again:
     * only its hash is stored, so the admin page has to show the QR and the link there and
     * then. Losing it means re-issuing, which is the right trade - it also means a stolen
     * database yields no working URLs.
     *
     * @return array{id: int, token: string}
     */
    public static function createCard(array $data): array
    {
        $token = bin2hex(random_bytes(16));
        $row = self::validate($data);
        $row['token_hash'] = hash('sha256', $token);
        $row['created_by'] = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        // NULL for an admin (a system-owned row everyone with the module sees), the creator's
        // extension for a plan user - which is what keeps one account's cards out of another's
        // list. Stamped here rather than taken from the form, so it cannot be posted.
        if (class_exists('BillingRoleBridge')) {
            $row['owner_extension'] = BillingRoleBridge::creatorOwnerExtension();
        }

        $cols = array_keys($row);
        $stmt = getDB()->prepare(
            'INSERT INTO pbx_business_cards (' . implode(', ', $cols) . ')
             VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
        );
        $stmt->execute(array_values($row));
        return ['id' => (int) getDB()->lastInsertId(), 'token' => $token];
    }

    public static function updateCard(int $id, array $data): void
    {
        if (self::getCard($id) === null) {
            throw new \Exception(t('cards.err_not_found'));
        }
        self::assertMine($id);
        $row = self::validate($data);
        $sets = implode(' = ?, ', array_keys($row)) . ' = ?';
        $stmt = getDB()->prepare("UPDATE pbx_business_cards SET {$sets} WHERE id = ?");
        $stmt->execute(array_merge(array_values($row), [$id]));
    }

    /** Withdraws a card. The QR on the printed card stops working; the row and its log stay. */
    public static function revokeCard(int $id): void
    {
        self::assertMine($id);
        getDB()->prepare('UPDATE pbx_business_cards SET is_active = 0, revoked_at = NOW() WHERE id = ?')
            ->execute([$id]);
    }

    /** A new token for the same card: the old QR dies, the card's content and log survive. */
    public static function reissueCard(int $id): string
    {
        self::assertMine($id);
        $token = bin2hex(random_bytes(16));
        getDB()->prepare(
            'UPDATE pbx_business_cards
                SET token_hash = ?, is_active = 1, revoked_at = NULL, view_count = 0 WHERE id = ?'
        )->execute([hash('sha256', $token), $id]);
        return $token;
    }

    public static function deleteCard(int $id): void
    {
        self::assertMine($id);
        // The pictures go too, or they sit in assets/images/cards for ever with nothing
        // pointing at them.
        $card = self::getCard($id);
        if ($card !== null) {
            self::deleteImage((string) ($card['avatar_file'] ?? ''));
            self::deleteImage((string) ($card['cover_file'] ?? ''));
        }
        // The fields go with it through the foreign key; the views keep their rows with a null
        // card_id so a historical count is not silently rewritten.
        getDB()->prepare('UPDATE pbx_business_card_views SET card_id = NULL WHERE card_id = ?')->execute([$id]);
        getDB()->prepare('DELETE FROM pbx_business_cards WHERE id = ?')->execute([$id]);
    }

    /**
     * Replaces a card's field list with what the form sent.
     *
     * Every value is checked here, not on the way out: a link that cannot become a working
     * href is refused while its owner is still looking at the form, because the alternative
     * is finding out after the cards are printed.
     *
     * @param list<array<string,string>> $fields
     */
    public static function saveFields(int $cardId, array $fields): void
    {
        self::assertMine($cardId);
        $db = getDB();
        $db->prepare('DELETE FROM pbx_business_card_fields WHERE card_id = ?')->execute([$cardId]);
        $ins = $db->prepare(
            'INSERT INTO pbx_business_card_fields (card_id, kind, icon, label, value, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $order = 0;
        foreach (array_slice($fields, 0, 30) as $f) {
            $kind = (string) ($f['kind'] ?? 'text');
            $value = trim((string) ($f['value'] ?? ''));
            if ($value === '' || !in_array($kind, self::KINDS, true)) {
                continue;
            }
            if ($kind !== 'text' && self::linkHref($kind, $value) === '') {
                throw new \Exception(sprintf(t('cards.err_bad_value'), $value));
            }
            $ins->execute([
                $cardId,
                $kind,
                substr(trim((string) ($f['icon'] ?? '')), 0, 40),
                substr(trim((string) ($f['label'] ?? '')), 0, 80),
                substr($value, 0, 500),
                ++$order,
            ]);
        }
    }

    /**
     * The card columns, cleaned. Only these keys ever reach a statement, so a form that
     * grows an extra input cannot write a column nobody intended it to.
     *
     * @return array<string,mixed>
     */
    private static function validate(array $d): array
    {
        $label = trim((string) ($d['label'] ?? ''));
        if ($label === '') {
            throw new \Exception(t('cards.err_label'));
        }
        $pinRaw = trim((string) ($d['pin'] ?? ''));
        if ($pinRaw !== '' && preg_match('/^[0-9]{4,16}$/', $pinRaw) !== 1) {
            throw new \Exception(t('cards.err_pin'));
        }
        $accent = (string) ($d['accent_color'] ?? '#2563eb');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
            $accent = '#2563eb';
        }
        $expires = trim((string) ($d['expires_at'] ?? ''));
        if ($expires !== '' && strtotime($expires) === false) {
            throw new \Exception(t('cards.err_expires'));
        }
        $widgetId = (int) ($d['widget_id'] ?? 0);

        $row = [
            'label' => substr($label, 0, 120),
            'display_name' => substr(trim((string) ($d['display_name'] ?? '')), 0, 120),
            'job_title' => substr(trim((string) ($d['job_title'] ?? '')), 0, 120),
            'company' => substr(trim((string) ($d['company'] ?? '')), 0, 120),
            'tagline' => substr(trim((string) ($d['tagline'] ?? '')), 0, 255),
            'accent_color' => $accent,
            'widget_id' => $widgetId > 0 ? $widgetId : null,
            'call_enabled' => !empty($d['call_enabled']) ? 1 : 0,
            'calls_daily_limit' => max(0, min(1000, (int) ($d['calls_daily_limit'] ?? 5))),
            'max_views' => max(0, min(100000, (int) ($d['max_views'] ?? 0))),
            'expires_at' => $expires !== '' ? date('Y-m-d H:i:s', (int) strtotime($expires)) : null,
            'is_active' => !empty($d['is_active']) ? 1 : 0,
        ];
        // The contact-file fields. Length-capped to the columns and otherwise taken as typed:
        // they are escaped for vCard on the way out (vcardEscape) and for HTML on the way to
        // the page, and the two need different escaping, so neither is baked in here.
        foreach ([
            'vcard_first_name' => 120, 'vcard_last_name' => 120, 'vcard_phone' => 40,
            'vcard_mobile' => 40, 'vcard_email' => 190, 'vcard_website' => 255,
            'vcard_address' => 255, 'vcard_note' => 255,
        ] as $col => $limit) {
            $row[$col] = substr(trim((string) ($d[$col] ?? '')), 0, $limit);
        }
        $row['vcard_enabled'] = !empty($d['vcard_enabled']) ? 1 : 0;

        // An empty PIN box on an edit means "leave it alone", not "remove it" - a form that
        // silently dropped the PIN every time it was saved would be worse than no PIN at all.
        // Removing one is its own checkbox.
        if ($pinRaw !== '') {
            $row['pin_hash'] = password_hash($pinRaw, PASSWORD_DEFAULT);
        } elseif (!empty($d['remove_pin'])) {
            $row['pin_hash'] = '';
        }
        return $row;
    }
}
