<?php
/**
 * The one page every refusal lands on.
 *
 * Unknown token, malformed token, revoked card, expired card, past its view cap, the plugin
 * switched off, too many requests from this address: all of them arrive here and get these
 * exact words. Saying which it was would hand a scanner a way to tell a real token from a
 * guess, or tell someone whose card was withdrawn that it ever existed.
 *
 * Says nothing about the PBX either: no version, no host name, no product name, no link
 * back to the portal. There is nothing here to learn.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Not available</title>
<style>
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
       padding: 24px; text-align: center; background: #0f172a; color: #94a3b8;
       font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
p { margin: 0; max-width: 320px; }
@media (prefers-color-scheme: light) { body { background: #eef2f9; color: #64748b; } }
</style>
</head>
<body>
<p>This card is not available.</p>
</body>
</html>
