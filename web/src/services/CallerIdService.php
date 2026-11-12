<?php

/**
 * Outgoing numbers (caller IDs) an extension may use on outside calls.
 *
 * - The administrator gives an extension any of the DIDs (Extensions > Edit); one DID may be
 *   given to many extensions.
 * - The extension's default is sys_users.cid_external (unchanged meaning): the administrator
 *   may type any number there, the user may choose one of the numbers given to them.
 * - Per call, the web phone and the app send the chosen number in the SIP header
 *   X-OwnPBX-CID. The endpoint carries the allowed list (CID_ALLOWED, written by
 *   SyncExtensions), and the outbound routes use the header only when the number is on it.
 */
class CallerIdService
{
    public const HEADER = 'X-OwnPBX-CID';

    /** All DIDs, to tick on an extension: [['number' => ..., 'title' => ...]] */
    public static function allDids(): array
    {
        try {
            $rows = getDB()->query("SELECT did_number, title FROM pbx_dids WHERE did_number REGEXP '^[+]?[0-9]{3,}$' ORDER BY title, did_number")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(fn ($r) => ['number' => (string) $r['did_number'], 'title' => (string) $r['title']], $rows);
    }

    /** @return list<string> numbers ticked for this user */
    public static function assigned(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        try {
            $stmt = getDB()->prepare('SELECT number FROM pbx_user_caller_ids WHERE user_id = ? ORDER BY id');
            $stmt->execute([$userId]);
            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return []; // before the migration
        }
    }

    /**
     * What the user may call out as: their default first, then the numbers given to them.
     * @return list<array{number: string, title: string, default: bool}>
     */
    public static function choices(int $userId): array
    {
        $default = self::clean((string) DBHelper::fetchColumn('SELECT cid_external FROM sys_users WHERE id = ?', [$userId]));
        $titles = [];
        foreach (self::allDids() as $d) {
            $titles[self::digits($d['number'])] = $d['title'];
        }
        $out = [];
        $seen = [];
        foreach (array_merge($default !== '' ? [$default] : [], self::assigned($userId)) as $n) {
            $key = self::digits($n);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['number' => $n, 'title' => $titles[$key] ?? '', 'default' => $default !== '' && self::digits($default) === $key];
        }
        return $out;
    }

    /** Replaces the numbers given to a user; only DIDs of this PBX are kept. */
    public static function assign(int $userId, array $numbers): void
    {
        if ($userId < 1) {
            return;
        }
        $dids = [];
        foreach (self::allDids() as $d) {
            $dids[self::digits($d['number'])] = $d['number'];
        }
        $keep = [];
        foreach ($numbers as $n) {
            $key = self::digits((string) $n);
            if (isset($dids[$key])) {
                $keep[$key] = $dids[$key];
            }
        }
        $db = getDB();
        $db->prepare('DELETE FROM pbx_user_caller_ids WHERE user_id = ?')->execute([$userId]);
        $ins = $db->prepare('INSERT INTO pbx_user_caller_ids (user_id, number) VALUES (?, ?)');
        foreach ($keep as $n) {
            $ins->execute([$userId, $n]);
        }
    }

    /**
     * The user chooses their default among their numbers ('' = the line's own number).
     * Writes sys_users.cid_external; call applyNow() afterwards.
     */
    public static function setDefault(int $userId, string $number): void
    {
        $number = self::clean($number);
        if ($number !== '') {
            $ok = false;
            foreach (self::choices($userId) as $c) {
                if (self::digits($c['number']) === self::digits($number)) {
                    $number = $c['number'];
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new \InvalidArgumentException('That number is not one of yours.');
            }
        } elseif (self::assigned($userId) === []) {
            throw new \InvalidArgumentException('Ask an administrator to give you outgoing numbers first.');
        }
        getDB()->prepare('UPDATE sys_users SET cid_external = ? WHERE id = ?')->execute([$number, $userId]);
        // The caller (My Phone, the app) rewrites the endpoints right away (syncAllExtensions):
        // a user's own setting must not wait for an administrator's Apply.
    }

    /** The endpoint variable the dialplan checks a picked number against: "-digits-digits-". */
    public static function allowedVar(int $userId, string $cidExternal): string
    {
        $keys = [];
        foreach (array_merge([$cidExternal], self::assigned($userId)) as $n) {
            $k = self::digits((string) $n);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        return $keys === [] ? '' : '-' . implode('-', array_keys($keys)) . '-';
    }

    public static function digits(string $n): string
    {
        return (string) preg_replace('/\D/', '', $n);
    }

    private static function clean(string $n): string
    {
        $n = (string) preg_replace('/[^0-9+]/', '', $n);
        return strlen(self::digits($n)) >= 3 ? $n : '';
    }
}
