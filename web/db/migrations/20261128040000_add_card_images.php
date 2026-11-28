<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A photo and a header image for a business card (1.75.85).
 *
 * Only the stored file's name is kept, never a path and never anything the uploader typed.
 * BusinessCardService generates it, so a row cannot point at a file outside the cards
 * directory however the upload arrived.
 */
final class AddCardImages extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('pbx_business_cards');
        if (!$t->exists()) {
            return;
        }
        foreach (['avatar_file', 'cover_file'] as $col) {
            if (!$t->hasColumn($col)) {
                $t->addColumn($col, 'string', ['limit' => 80, 'null' => false, 'default' => '']);
            }
        }
        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('pbx_business_cards');
        if (!$t->exists()) {
            return;
        }
        foreach (['avatar_file', 'cover_file'] as $col) {
            if ($t->hasColumn($col)) {
                $t->removeColumn($col);
            }
        }
        $t->update();
    }
}
