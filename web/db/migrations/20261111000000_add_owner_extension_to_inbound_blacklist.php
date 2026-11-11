<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Block caller (*86): record which extension blocked a number.
 *
 * Adds `owner_extension` to pbx_inbound_blacklist so a number blocked with
 * *86 is attributed to the extension that blocked it (the called party).
 * The block itself is still enforced for the whole PBX (the blacklist is
 * global), but each user can see and manage their own entries under
 * Call filtering, and the portal shows who blocked what.
 */
final class AddOwnerExtensionToInboundBlacklist extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_inbound_blacklist');
        if (!$table->hasColumn('owner_extension')) {
            $table->addColumn('owner_extension', 'string', ['limit' => 32, 'null' => true, 'default' => null, 'after' => 'title'])
                ->addIndex(['owner_extension'])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('pbx_inbound_blacklist');
        if ($table->hasColumn('owner_extension')) {
            $table->removeColumn('owner_extension')->update();
        }
    }
}
