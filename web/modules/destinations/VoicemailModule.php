<?php
namespace PBX\Destinations;

/**
 * Destination type 'voicemail': straight to an extension's voicemail box (for example a ring
 * group nobody answered). Offered in the destination pickers (DestinationRegistry,
 * /api/destinations.php); the dialplan lines are built by buildDestinationLines() in
 * web/src/sync/DialplanDestinationBuilder.php.
 */
class VoicemailModule implements PBXDestinationInterface {
    public function getKey(): string { return 'voicemail'; }
    public function getName(): string { return '📼 Voicemail'; }

    /** Extensions whose voicemail is on. */
    public function getOptions(): array {
        $db = \getDB();
        $stmt = $db->query("SELECT extension AS id, CONCAT(extension, ' - ', full_name) AS name FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 AND voicemail_enabled = 1 ORDER BY extension ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function resolve($agi, string $destId): array {
        return [
            'type' => 'voicemail',
            'val' => $destId
        ];
    }
}

DestinationRegistry::register(new VoicemailModule());
