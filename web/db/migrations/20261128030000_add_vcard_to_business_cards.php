<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Save-to-contacts for business cards (1.75.85).
 *
 * The card page can offer a .vcf download so the person you handed the card to keeps your
 * details in their phone rather than in a browser tab. These columns are what goes in it.
 *
 * They are separate from the card's display fields on purpose. What reads well on a page is
 * not what a phone's address book wants: a contact needs a surname of its own to file you
 * under, a number in a form a dialler can use, and one address. Deriving that from free-form
 * display lines would guess wrong - "Direct line" and "WhatsApp" are both phone lines on the
 * page, and only one of them belongs in the contact's TEL field.
 *
 * vcard_enabled is default 1: a card whose owner filled nothing in still produces a usable
 * contact, because BusinessCardService::vcard() falls back to the display name, and a
 * business card nobody can save is missing the point. Nothing is published that was not
 * already on the page - the vCard carries the same details plus the card's own link.
 */
final class AddVcardToBusinessCards extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('pbx_business_cards');
        if (!$t->exists()) {
            return;
        }
        $cols = [
            // Split name, because a phone files a contact by surname.
            'vcard_first_name' => 120,
            'vcard_last_name' => 120,
            'vcard_phone' => 40,
            'vcard_mobile' => 40,
            'vcard_email' => 190,
            'vcard_website' => 255,
            'vcard_address' => 255,
            'vcard_note' => 255,
        ];
        foreach ($cols as $col => $limit) {
            if (!$t->hasColumn($col)) {
                $t->addColumn($col, 'string', ['limit' => $limit, 'null' => false, 'default' => '']);
            }
        }
        if (!$t->hasColumn('vcard_enabled')) {
            $t->addColumn('vcard_enabled', 'boolean', ['null' => false, 'default' => 1]);
        }
        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('pbx_business_cards');
        if (!$t->exists()) {
            return;
        }
        foreach ([
            'vcard_first_name', 'vcard_last_name', 'vcard_phone', 'vcard_mobile',
            'vcard_email', 'vcard_website', 'vcard_address', 'vcard_note', 'vcard_enabled',
        ] as $col) {
            if ($t->hasColumn($col)) {
                $t->removeColumn($col);
            }
        }
        $t->update();
    }
}
