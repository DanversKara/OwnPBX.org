#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scheduled Tasks Dispatcher (Roadmap 2.1).
 * Run every minute by system cron or systemd timer.
 * Checks due tasks and triggers sandboxed execution.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ScheduledTaskService.php';
require_once dirname(__DIR__) . '/src/services/ScheduledTaskRunner.php';
require_once dirname(__DIR__) . '/src/services/CronScheduleHelper.php';
require_once dirname(__DIR__) . '/src/services/AsteriskSettingsService.php';
require_once dirname(__DIR__) . '/src/services/NightRestartService.php';
require_once dirname(__DIR__) . '/src/repositories/ScheduledTaskRepository.php';

// PBX Maintenance checks: SIP debug expiry and safe night restart
try {
    AsteriskSettingsService::checkSipDebugExpiry();
    NightRestartService::executeRestart();
} catch (\Throwable $e) {
    // Non-fatal
}

// Trunks: a registration that is not working is no calls in or out, so it is restarted
// rather than waiting for somebody to notice.
try {
    require_once dirname(__DIR__) . '/src/services/TrunkRegistrationWatchdog.php';
    TrunkRegistrationWatchdog::check();
    // A trunk without its context in the dialplan means every call from the carrier is
    // answered 404: rebuild the inbound routing rather than wait for somebody to notice.
    $trunksWithoutRouting = TrunkRegistrationWatchdog::inboundRepairDue();
    if ($trunksWithoutRouting !== []) {
        // The cron runs as root, the portal writes these files as the web user: keep
        // whichever owner the file had, or the next "Apply" could not write it.
        $inboundConf = ASTERISK_PBX_DIR . '/extensions_inbound.conf';
        $ownerBefore = @stat($inboundConf);
        require_once dirname(__DIR__) . '/src/asterisk_sync.php';
        syncInboundDialplan();
        if (is_array($ownerBefore) && is_file($inboundConf)) {
            @chown($inboundConf, (int) $ownerBefore['uid']);
            @chgrp($inboundConf, (int) $ownerBefore['gid']);
        }
        writeAuditLog(null, 'trunks', 'watchdog', 'Inbound routing was missing, rebuilt: ' . implode(', ', $trunksWithoutRouting), 'update', null);
    }
} catch (\Throwable $e) {
    error_log('task_dispatcher trunk watchdog: ' . $e->getMessage());
}

// Do not disturb with a timer ("for 8 hours"): switch it off when the time has passed.
try {
    require_once dirname(__DIR__) . '/src/services/DndTimerService.php';
    $dndOff = DndTimerService::expire();
    if ($dndOff !== []) {
        require_once dirname(__DIR__) . '/src/asterisk_sync.php';
        syncGeneralDialplan();
        if (function_exists('writeAuditLog')) {
            writeAuditLog(null, 'user_settings', 'phone', 'DND switched off by its timer: ' . implode(', ', $dndOff), 'update', null);
        }
    }
} catch (\Throwable $e) {
    error_log('task_dispatcher DND timer: ' . $e->getMessage());
}

ScheduledTaskRunner::removeDeletedTaskFolders();

if (!ScheduledTaskService::isEnabled()) {
    // Feature switched off: no task runs, a pending "run now" is dropped.
    ScheduledTaskRepository::takeRunRequests();
    exit(0);
}

$now = new DateTimeImmutable();
$due = [];
// "Run now" from the portal (it may not start a task itself).
foreach (ScheduledTaskRepository::takeRunRequests() as $task) {
    $due[(int) $task['id']] = [$task, 'manual'];
}
foreach (ScheduledTaskRepository::findActive() as $task) {
    if (!isset($due[(int) $task['id']]) && CronScheduleHelper::isDue((string) $task['cron_expression'], $now)) {
        $due[(int) $task['id']] = [$task, 'schedule'];
    }
}

foreach ($due as $taskId => [$task, $trigger]) {
    if (ScheduledTaskRepository::isTaskRunning($taskId)) {
        continue;   // no overlapping runs of one task
    }
    // In the background, so one long task does not hold up the others.
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/run_task.php') . ' ' . escapeshellarg((string) $taskId) . ' ' . escapeshellarg((string) $trigger) . ' >/dev/null 2>&1 &');
}

exit(0);
