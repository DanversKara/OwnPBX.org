<?php

/**
 * Do not disturb that switches itself off (sys_users.dnd_until).
 *
 * The user turns DND on "for 8 hours" (My Phone, the app); the every-minute cron
 * (bin/task_dispatcher.php) turns it off when the time has passed and rewrites the dialplan.
 * All times are the database's clock (NOW()), so PHP and MariaDB time zones cannot disagree.
 */
class DndTimerService
{
    /** Minutes offered in the pickers (0 = until I turn it off). */
    public const OPTIONS = [0, 10, 30, 60, 180, 300, 480, 600];

    /**
     * Call after dnd_enabled was saved. $minutes: null = leave the timer as it is (other
     * settings were saved), 0 = no automatic switch-off, > 0 = off after that many minutes.
     */
    public static function apply(int $userId, bool $dndOn, ?int $minutes): void
    {
        $db = getDB();
        if (!$dndOn) {
            $db->prepare('UPDATE sys_users SET dnd_until = NULL WHERE id = ?')->execute([$userId]);
            return;
        }
        if ($minutes === null) {
            return;
        }
        if ($minutes <= 0) {
            $db->prepare('UPDATE sys_users SET dnd_until = NULL WHERE id = ?')->execute([$userId]);
            return;
        }
        $minutes = min($minutes, 7 * 24 * 60);
        $db->prepare("UPDATE sys_users SET dnd_until = DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE) WHERE id = ?")->execute([$userId]);
    }

    /** Minutes until DND switches itself off; 0 = no timer (or DND is off). */
    public static function minutesLeft(int $userId): int
    {
        try {
            $stmt = getDB()->prepare('SELECT CEIL(TIMESTAMPDIFF(SECOND, NOW(), dnd_until) / 60) FROM sys_users WHERE id = ? AND dnd_enabled = 1 AND dnd_until IS NOT NULL');
            $stmt->execute([$userId]);
            return max(0, (int) $stmt->fetchColumn());
        } catch (\Throwable $e) {
            return 0; // before the migration
        }
    }

    /** "45 min" / "7 h 20 min" */
    public static function label(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
    }

    /**
     * Cron: turns DND off where the time has passed.
     * @return list<string> the extensions switched back on (the caller rewrites the dialplan when not empty)
     */
    public static function expire(): array
    {
        $db = getDB();
        try {
            $rows = $db->query('SELECT id, extension FROM sys_users WHERE dnd_enabled = 1 AND dnd_until IS NOT NULL AND dnd_until <= NOW()')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return []; // before the migration
        }
        $done = [];
        $upd = $db->prepare('UPDATE sys_users SET dnd_enabled = 0, dnd_until = NULL WHERE id = ?');
        foreach ($rows as $r) {
            $upd->execute([(int) $r['id']]);
            $done[] = (string) $r['extension'];
        }
        return $done;
    }
}
