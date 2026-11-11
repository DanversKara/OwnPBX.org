<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fax cover templates: company contact details + a per-template logo,
 * matching the old OwnPBX fax branding (company, address, phone, email,
 * website, footer notice, logo). The cover layout reads these fields;
 * empty fields are simply left out of the cover.
 */
final class AddFaxCoverTemplateDetails extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_fax_cover_templates');
        if (!$table->hasColumn('logo_path')) {
            $table->addColumn('logo_path', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'logo']);
        }
        if (!$table->hasColumn('address')) {
            $table->addColumn('address', 'string', ['limit' => 200, 'null' => true, 'default' => null, 'after' => 'company']);
        }
        if (!$table->hasColumn('phone')) {
            $table->addColumn('phone', 'string', ['limit' => 30, 'null' => true, 'default' => null, 'after' => 'address']);
        }
        if (!$table->hasColumn('email')) {
            $table->addColumn('email', 'string', ['limit' => 80, 'null' => true, 'default' => null, 'after' => 'phone']);
        }
        if (!$table->hasColumn('website')) {
            $table->addColumn('website', 'string', ['limit' => 80, 'null' => true, 'default' => null, 'after' => 'email']);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('pbx_fax_cover_templates');
        foreach (['logo_path', 'address', 'phone', 'email', 'website'] as $col) {
            if ($table->hasColumn($col)) {
                $table->removeColumn($col);
            }
        }
        $table->update();
    }
}
