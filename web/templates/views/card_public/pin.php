<?php
/**
 * The PIN prompt for a card whose owner asked for one.
 *
 * Reached only by presenting a valid 32-character token, so showing this page does admit
 * that a card exists behind that URL. That is unavoidable - a prompt has to be shown to
 * somebody - and it costs nothing, because arriving here at all means already holding the
 * 128-bit secret.
 *
 * The form posts to the same URL. $cardToken has been through
 * BusinessCardService::publicCard() and is therefore known to be 32 hex characters before
 * it is printed into the action; it is escaped again here anyway, because a template should
 * not have to be read alongside a controller to be seen to be safe.
 *
 * @var string $cardToken
 * @var bool   $cardPinWrong  true when a PIN was supplied and did not match
 */
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Enter PIN</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
       padding: 24px; background: #0f172a; color: #e8edf7;
       font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
.box { width: 100%; max-width: 340px; background: #182135; border: 1px solid #2a3650;
       border-radius: 18px; padding: 30px 26px; text-align: center; }
h1 { margin: 0 0 8px; font-size: 19px; }
p { margin: 0 0 20px; color: #9fadc7; font-size: 14.5px; }
input { width: 100%; padding: 13px 14px; font-size: 22px; text-align: center; letter-spacing: 6px;
        border-radius: 11px; border: 1px solid #3a4866; background: #0f172a; color: #e8edf7; }
input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.25); }
button { width: 100%; margin-top: 14px; padding: 13px; font-size: 16px; font-weight: 600;
         border: 0; border-radius: 11px; background: #2563eb; color: #fff; cursor: pointer; }
button:hover { background: #1d4ed8; }
.err { margin: 0 0 16px; color: #fda4af; font-size: 14px; }
@media (prefers-color-scheme: light) {
  body { background: #eef2f9; color: #16203a; }
  .box { background: #fff; border-color: #d8e0ee; }
  p { color: #5c6b8a; } input { background: #fff; border-color: #c9d4e8; color: #16203a; }
}
</style>
</head>
<body>
<form class="box" method="post" action="/card/<?php echo $e($cardToken); ?>">
  <h1>This card is PIN protected</h1>
  <?php if ($cardPinWrong) { ?>
    <p class="err">That PIN was not right.</p>
  <?php } else { ?>
    <p>Enter the PIN that came with the card.</p>
  <?php } ?>
  <input type="text" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="16"
         autocomplete="off" autofocus aria-label="PIN">
  <button type="submit">Open card</button>
</form>
</body>
</html>
