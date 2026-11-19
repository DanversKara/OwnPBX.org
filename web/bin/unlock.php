<?php

declare(strict_types=1);

/**
 * The way back in, from the console.
 *
 * Nothing that arrives over the network can lift a lockdown or unseal an account: not a text,
 * not a page, not the API. That is the point of the lockdown - if any of them could, an attacker
 * who got that far could undo it. This script is the other half of that bargain, and it only runs
 * for somebody who already has a shell on the machine.
 *
 *   php bin/unlock.php status          what is sealed right now
 *   php bin/unlock.php lockdown off    lift the lockdown
 *   php bin/unlock.php user 611        unseal one account
 *   php bin/unlock.php all             lift the lockdown and unseal every account
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/SecurityLockdownService.php';

$argv = $_SERVER['argv'] ?? [];
$cmd = strtolower((string) ($argv[1] ?? 'status'));
$arg = (string) ($argv[2] ?? '');
$by = 'console:' . (trim((string) @shell_exec('whoami')) ?: 'root');

function say(string $s): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

switch ($cmd) {
    case 'lockdown':
        if (strtolower($arg) !== 'off') {
            say('Usage: php bin/unlock.php lockdown off');
            exit(1);
        }
        SecurityLockdownService::disable($by);
        say('Lockdown lifted. Sign-in works from anywhere again, and suspended API tokens are back.');
        break;

    case 'user':
        if ($arg === '') {
            say('Usage: php bin/unlock.php user <extension|username>');
            exit(1);
        }
        $user = SecurityLockdownService::findUser($arg);
        if ($user === null) {
            say("No user {$arg}.");
            exit(1);
        }
        SecurityLockdownService::unlockUser((int) $user['id'], $by);
        say("{$arg} can sign in again.");
        break;

    case 'all':
        SecurityLockdownService::disable($by);
        $n = 0;
        foreach (SecurityLockdownService::lockedUsers() as $u) {
            SecurityLockdownService::unlockUser((int) $u['id'], $by);
            $n++;
        }
        say("Lockdown lifted and {$n} account(s) unsealed.");
        break;

    case 'status':
    default:
        $state = SecurityLockdownService::state();
        say('Lockdown: ' . ($state['active'] ? 'ON since ' . $state['at'] . ' (' . $state['by'] . ')' : 'off'));
        say('Local networks that may sign in: ' . $state['cidrs']);
        $locked = SecurityLockdownService::lockedUsers();
        if ($locked === []) {
            say('No accounts are locked.');
            break;
        }
        say('Locked accounts:');
        foreach ($locked as $u) {
            say(sprintf(
                '  %-10s %-20s %s  %s',
                (string) $u['extension'],
                (string) $u['username'],
                (string) $u['locked_at'],
                (string) $u['locked_reason']
            ));
        }
        break;
}
