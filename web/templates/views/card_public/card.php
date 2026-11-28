<?php
/**
 * A digital business card as its recipient sees it.
 *
 * Standalone on purpose: no portal layout, no header, no admin CSS or JavaScript. The only
 * thing this page loads from the PBX is widget.js, and only when the card has a call button.
 * Everything else is inline CSS in one <style> block, which is why the page works with
 * default-src 'none'.
 *
 * Every value here was typed by the owner in the admin page and is printed through $e()
 * (htmlspecialchars with ENT_QUOTES) on the way out. Link targets go through
 * BusinessCardService::linkHref(), which allows four schemes and nothing else, so a stored
 * 'javascript:' cannot become a working link.
 *
 * @var array<string,mixed>       $card
 * @var list<array<string,mixed>> $cardFields
 * @var string                    $cardWidget  widget public id, '' for no call button
 * @var string                    $cardToken
 * @var bool                      $cardHasVcard
 */
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// The accent colour comes from a colour picker, but it lands in a style attribute, so it is
// checked here too rather than trusted: anything that is not a plain hex colour falls back.
$accent = (string) ($card['accent_color'] ?? '');
if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
    $accent = '#2563eb';
}
$name = trim((string) ($card['display_name'] ?? ''));
$initials = '';
foreach (preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
    $initials .= mb_substr($part, 0, 1);
}
$initials = mb_strtoupper(mb_substr($initials, 0, 2));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo $e($name !== '' ? $name : 'Contact card'); ?></title>
<style>
:root { --accent: <?php echo $e($accent); ?>; }
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; padding: 24px 16px 48px; background: #0f172a; color: #e8edf7;
       font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
.card { max-width: 420px; margin: 0 auto; background: #182135; border: 1px solid #2a3650;
        border-radius: 18px; overflow: hidden; box-shadow: 0 18px 50px rgba(0,0,0,.45); }
/* A letterhead, then the photo breaking into it, then the name block - the way a CV reads,
   rather than everything stacked down the middle. */
.cover { height: 132px; background:
         linear-gradient(135deg, color-mix(in srgb, var(--accent) 62%, #0b1220),
                                 color-mix(in srgb, var(--accent) 22%, #0b1220)); }
.cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.top { padding: 0 24px 22px; }
.idrow { display: flex; align-items: flex-end; gap: 14px; margin-top: -38px; }
.avatar { width: 92px; height: 92px; flex: none; border-radius: 18px; overflow: hidden;
          background: var(--accent); color: #fff; font-size: 30px; font-weight: 600;
          letter-spacing: .5px; display: flex; align-items: center; justify-content: center;
          border: 3px solid #182135; box-shadow: 0 8px 22px rgba(0,0,0,.4); }
.avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.namecol { padding-bottom: 6px; min-width: 0; }
h1 { margin: 0 0 2px; font-size: 22px; letter-spacing: -.3px; line-height: 1.2; }
.title { margin: 0; color: #aab7d1; font-size: 14.5px; }
.company { margin: 1px 0 0; color: var(--accent); font-size: 14.5px; font-weight: 600; }
.tagline { margin: 16px 0 0; padding-top: 14px; border-top: 1px solid #2a3650;
           color: #c3cde3; font-size: 14.5px; }
.rows { padding: 8px 14px 18px; }
.row { display: flex; gap: 12px; align-items: center; padding: 12px 10px; border-radius: 12px;
       text-decoration: none; color: inherit; }
a.row:hover, a.row:focus { background: #1f2a42; outline: none; }
a.row:focus-visible { box-shadow: 0 0 0 2px var(--accent); }
.row .lab { color: #8c9ab5; font-size: 12.5px; text-transform: uppercase; letter-spacing: .6px; }
.row .val { color: #e8edf7; word-break: break-word; }
a.row .val { color: var(--accent); }
.chev { margin-left: auto; color: #5d6b87; flex: none; }
.callwrap { padding: 4px 24px 18px; }
.savewrap { padding: 0 24px 22px; }
.save { display: flex; align-items: center; justify-content: center; gap: 8px;
        padding: 13px 16px; border-radius: 12px; text-decoration: none; font-weight: 600;
        border: 1px solid var(--accent); color: var(--accent); background: transparent; }
.save:hover, .save:focus { background: var(--accent); color: #fff; outline: none; }
.foot { max-width: 420px; margin: 18px auto 0; text-align: center; color: #64748b; font-size: 12px; }
@media (prefers-color-scheme: light) {
  body { background: #eef2f9; color: #16203a; }
  .card { background: #fff; border-color: #d8e0ee; }
  .title { color: #5c6b8a; } .tagline { color: #41506f; border-top-color: #e2e8f4; }
  .avatar { border-color: #fff; }
  .row .val { color: #16203a; } a.row:hover, a.row:focus { background: #f1f5fc; }
}
</style>
</head>
<body>
<main class="card">
  <?php
  // Both come from imageUrl(), which only returns a path for a filename this PBX generated -
  // so a row holding anything else links nowhere rather than being printed into the page.
  $coverUrl = BusinessCardService::imageUrl((string) ($card['cover_file'] ?? ''));
  $avatarUrl = BusinessCardService::imageUrl((string) ($card['avatar_file'] ?? ''));
  ?>
  <div class="cover"><?php if ($coverUrl !== '') { ?><img src="<?php echo $e($coverUrl); ?>" alt=""><?php } ?></div>
  <div class="top">
    <div class="idrow">
      <?php if ($avatarUrl !== '') { ?>
        <div class="avatar"><img src="<?php echo $e($avatarUrl); ?>" alt="<?php echo $e($name); ?>"></div>
      <?php } elseif ($initials !== '') { ?>
        <div class="avatar"><?php echo $e($initials); ?></div>
      <?php } ?>
      <div class="namecol">
        <?php if ($name !== '') { ?><h1><?php echo $e($name); ?></h1><?php } ?>
        <?php if (($card['job_title'] ?? '') !== '') { ?><p class="title"><?php echo $e($card['job_title']); ?></p><?php } ?>
        <?php if (($card['company'] ?? '') !== '') { ?><p class="company"><?php echo $e($card['company']); ?></p><?php } ?>
      </div>
    </div>
    <?php if (($card['tagline'] ?? '') !== '') { ?><p class="tagline"><?php echo $e($card['tagline']); ?></p><?php } ?>
  </div>

  <?php if ($cardWidget !== '') { ?>
  <div class="callwrap">
    <?php /* widget.js draws the button in here and runs the WebRTC call itself. Only the
             widget's public id appears on the page; the SIP account and its secret stay
             behind /widget-api/call, which issues one-time credentials per call. */ ?>
    <div id="card-call"></div>
  </div>
  <?php } ?>

  <?php if ($cardHasVcard) { ?>
  <div class="savewrap">
    <?php /* A plain link, not a script: tapping it on a phone opens the contact card with
             "Add to Contacts" already offered. download= is a hint for a desktop browser. */ ?>
    <a class="save" href="/card/<?php echo $e($cardToken); ?>/vcard" download>
      <span aria-hidden="true">&plus;</span> Save to contacts
    </a>
  </div>
  <?php } ?>

  <?php if ($cardFields !== []) { ?>
  <div class="rows">
    <?php foreach ($cardFields as $f) {
        $kind = (string) ($f['kind'] ?? 'text');
        $val = (string) ($f['value'] ?? '');
        $lab = (string) ($f['label'] ?? '');
        if ($val === '') { continue; }
        $href = BusinessCardService::linkHref($kind, $val);
        if ($href !== '') { ?>
          <a class="row" href="<?php echo $e($href); ?>" rel="noopener noreferrer nofollow"<?php
              echo str_starts_with($href, 'http') ? ' target="_blank"' : ''; ?>>
            <span>
              <?php if ($lab !== '') { ?><span class="lab"><?php echo $e($lab); ?></span><br><?php } ?>
              <span class="val"><?php echo $e($val); ?></span>
            </span>
            <span class="chev" aria-hidden="true">&rsaquo;</span>
          </a>
        <?php } else { ?>
          <div class="row">
            <span>
              <?php if ($lab !== '') { ?><span class="lab"><?php echo $e($lab); ?></span><br><?php } ?>
              <span class="val"><?php echo $e($val); ?></span>
            </span>
          </div>
        <?php }
    } ?>
  </div>
  <?php } ?>
</main>
<p class="foot">This card was issued to one person and can be withdrawn at any time.</p>
<?php if ($cardWidget !== '') { ?>
<script src="/widget.js" data-widget="<?php echo $e($cardWidget); ?>"
        data-card="<?php echo $e($cardToken); ?>" data-target="#card-call" async></script>
<?php } ?>
</body>
</html>
