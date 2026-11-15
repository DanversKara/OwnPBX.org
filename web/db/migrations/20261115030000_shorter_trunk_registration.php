<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registering trunks re-register every few minutes instead of once an hour.
 *
 * Behind NAT the router gives the PBX an outside port, and the carrier sends every inbound
 * call to the port it saw at registration time. When the router hands out a different port
 * (its mapping was dropped or rotated), the carrier keeps using the old one: the PBX still
 * reports "Registered" for the rest of the hour, OPTIONS still get answered, outbound calls
 * still work, and nothing comes in. Re-registering every three minutes keeps the carrier's
 * idea of the address fresh and bounds that gap to minutes.
 */
final class ShorterTrunkRegistration extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('UPDATE pbx_trunks SET registration_expiration = 180 WHERE registration_enabled = 1 AND (registration_expiration IS NULL OR registration_expiration >= 600)');
        $this->execute('UPDATE pbx_trunks SET qualify_frequency = 30 WHERE qualify_frequency IS NULL OR qualify_frequency > 30');
    }

    public function down(): void
    {
        // Nothing to undo: the old hourly value is the thing this fixes.
    }
}
