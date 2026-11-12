<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Web widgets: how the widget looks on a website (layout: floating or in the page, corner,
 * offsets, size, shape, theme, texts...) and which in-call buttons a visitor gets (controls:
 * mute, keypad and its keys, hold, volume, timer, name). Both are JSON so the builder can grow
 * without a migration per option; WebWidgetService fills in the defaults.
 */
final class WebWidgetLayoutAndControls extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_web_widgets')) {
            return;
        }
        $t = $this->table('pbx_web_widgets');
        if (!$t->hasColumn('layout_json')) {
            $t->addColumn('layout_json', 'text', ['null' => true, 'after' => 'ask_name']);
        }
        if (!$t->hasColumn('controls_json')) {
            $t->addColumn('controls_json', 'text', ['null' => true, 'after' => 'layout_json']);
        }
        $t->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('pbx_web_widgets')) {
            return;
        }
        $t = $this->table('pbx_web_widgets');
        foreach (['controls_json', 'layout_json'] as $c) {
            if ($t->hasColumn($c)) {
                $t->removeColumn($c);
            }
        }
        $t->update();
    }
}
