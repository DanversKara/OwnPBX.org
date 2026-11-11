<?php
/**
 * Guest accounts — a restricted account type (ported from the old OwnPBX).
 *
 * Internal (extension-to-extension) calls always work. Each of these is OFF for
 * a guest until the admin turns it on for that user (Users → Edit):
 *   guest_outbound  call outside numbers
 *   guest_inbound   be rung by outside callers (a call that came in on a trunk)
 *   guest_sms       use the SMS numbers given to them
 *   guest_fax       send and receive faxes
 * The devices a guest may use (web phone, mobile app, SIP desk phone, video)
 * are the user's allowed_phone_mode; for a guest a device that is off has no
 * SIP account at all (SyncExtensions), so it cannot be used by any client.
 *
 * Emergency numbers are ALWAYS blocked for a guest — every country, with or
 * without access prefixes — whatever the other rules say. The check runs on
 * the number actually sent to the carrier, immediately before every trunk
 * Dial() (SyncOutboundDialplan: [sub-guest-gate]), so no route, prefix,
 * emergency route, forward, transfer, ring group or IVR can bypass it. A guest
 * may never send any number of {@see SHORT_MAX} digits or fewer to a trunk:
 * every national emergency number (and N11 / crisis short code) is that
 * short. Internal extensions are unaffected: those calls never reach a trunk.
 *
 * Admins are never guests.
 */
class GuestService
{
    /** Numbers this short never leave the PBX for a guest. */
    public const SHORT_MAX = 6;

    /**
     * Emergency codes (worldwide), also blocked when longer because of an
     * access prefix (9, 1, 0, 00, 011, 0011 ...).
     */
    public const EMERGENCY_CODES = [
        '911', '933', '112', '999', '000', '110', '119', '118', '117', '113', '100', '101', '102', '103',
        '104', '105', '106', '107', '108', '111', '114', '115', '116', '120', '122', '123', '125', '127',
        '128', '130', '131', '132', '133', '140', '141', '145', '150', '155', '156', '157', '158', '160',
        '166', '177', '180', '181', '190', '191', '192', '193', '194', '196', '197', '198', '199', '990',
        '991', '993', '994', '995', '996', '997', '998', '988', '060', '061', '062', '065', '066', '080',
        '1122', '1313', '1669', '10111', '10177', '15', '16', '17', '18', '19', '08',
    ];

    /** Access prefixes a guest might put in front of an emergency code. */
    public const PREFIXES = ['0', '00', '000', '001', '010', '011', '0011', '1', '9', '90', '99', '8', '80', '81', '88'];

    public const RULES = ['guest_outbound', 'guest_inbound', 'guest_sms', 'guest_fax'];

    /** Regex (digits only, anchored) matching an emergency code with an optional access prefix. */
    public static function emergencyRegex(): string
    {
        $codes = self::EMERGENCY_CODES;
        usort($codes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $prefixes = self::PREFIXES;
        usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return '^(' . implode('|', $prefixes) . ')?(' . implode('|', $codes) . ')$';
    }

    /**
     * Would this number be refused for a guest as an emergency or short code
     * if it left the PBX? (Same rule as the dialplan gate.)
     */
    public static function isBlockedNumber(string $number): bool
    {
        $digits = (string) preg_replace('/\D+/', '', $number);
        return strlen($digits) <= self::SHORT_MAX || preg_match('/' . self::emergencyRegex() . '/', $digits) === 1;
    }

    /**
     * The guest flags of a user row (or of a user id). Non-guests and admins
     * get is_guest false and every rule true.
     *
     * @param array<string, mixed>|int $user
     * @return array{is_guest: bool, guest_outbound: bool, guest_inbound: bool, guest_sms: bool, guest_fax: bool}
     */
    public static function policy(array|int $user): array
    {
        if (is_int($user)) {
            $stmt = getDB()->prepare('SELECT role, is_guest, guest_outbound, guest_inbound, guest_sms, guest_fax FROM sys_users WHERE id = ?');
            $stmt->execute([$user]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $guest = !empty($user['is_guest']) && ($user['role'] ?? '') !== 'admin';
        $out = ['is_guest' => $guest];
        foreach (self::RULES as $rule) {
            $out[$rule] = !$guest || !empty($user[$rule]);
        }
        return $out;
    }

    /** Is this user a guest who may not use $rule (guest_outbound, guest_sms ...)? */
    public static function denies(array|int $user, string $rule): bool
    {
        $p = self::policy($user);
        return $p['is_guest'] && empty($p[$rule]);
    }

    /**
     * Guest extensions: extension => may call outside numbers. For the
     * dialplan lists the gate checks ([aipbx-guest-list], [aipbx-guest-out]).
     *
     * @return array<string, bool>
     */
    public static function guestExtensions(): array
    {
        try {
            $rows = getDB()->query("SELECT extension, guest_outbound FROM sys_users
                WHERE is_guest = 1 AND role <> 'admin' AND extension IS NOT NULL AND extension <> ''")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return []; // column not there yet (migration not run)
        }
        $out = [];
        foreach ($rows as $r) {
            $ext = (string) preg_replace('/\D/', '', (string) $r['extension']);
            if ($ext !== '') {
                $out[$ext] = !empty($r['guest_outbound']);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * A forwarding target a guest may save: an internal extension, or (when
     * outside calls are on) an outside number that is not an emergency/short code.
     */
    public static function forwardAllowed(array|int $user, string $target): bool
    {
        $target = trim($target);
        if ($target === '' || !self::policy($user)['is_guest']) {
            return true;
        }
        // Anything the PBX answers internally (extension, ring group, queue, IVR ...).
        require_once dirname(__DIR__) . '/internal_numbers.php';
        if (internalNumberOwner((string) preg_replace('/[^0-9*#]/', '', $target)) !== null) {
            return true;
        }
        return !self::denies($user, 'guest_outbound') && !self::isBlockedNumber($target);
    }
}
