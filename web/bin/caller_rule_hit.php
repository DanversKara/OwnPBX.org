#!/usr/bin/env php
<?php
/**
 * Caller rule hit counter.
 *
 * Increments the hit_count ("Used") of a caller rule when it matches an
 * incoming call. Called in the background from the dialplan
 * (sub-caller-rules) so it never blocks the call.
 *
 * Usage: caller_rule_hit.php <rule_id>
 * CLI only; never over HTTP.
 */
if (PHP_SAPI !== 'cli') {   // dialplan tool; never over HTTP
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';

$id = (int)($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: caller_rule_hit.php <rule_id>\n");
    exit(1);
}

$db = getDB();
$db->prepare('UPDATE caller_rules SET hit_count = hit_count + 1 WHERE id = ?')->execute([$id]);
exit(0);
