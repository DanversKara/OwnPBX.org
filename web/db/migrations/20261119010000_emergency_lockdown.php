<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A way back in when somebody else is already inside.
 *
 * Locking an account is kept apart from switching it off: an administrator deactivates a user who
 * has left, and that should not look the same as an account sealed during an incident, nor be
 * undone by accident. A suspended API token is remembered the same way, so lifting the lockdown
 * can put back exactly the tokens the lockdown took away rather than waking every token that was
 * ever issued.
 */
final class EmergencyLockdown extends AbstractMigration
{
    public function up(): void
    {
        $users = $this->table('sys_users');
        if (!$users->hasColumn('locked_at')) {
            $users->addColumn('locked_at', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => 'Sealed during an incident; separate from is_active',
            ])->update();
        }
        if (!$users->hasColumn('locked_reason')) {
            $users->addColumn('locked_reason', 'string', [
                'limit' => 190,
                'null' => true,
                'default' => null,
                'comment' => 'Why, and by what - shown on the lockdown page',
            ])->update();
        }

        if ($this->hasTable('sys_api_tokens')) {
            $tokens = $this->table('sys_api_tokens');
            if (!$tokens->hasColumn('suspended_at')) {
                $tokens->addColumn('suspended_at', 'datetime', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Switched off by a lockdown, so lifting it can restore just these',
                ])->update();
            }
        }
    }

    public function down(): void
    {
        $users = $this->table('sys_users');
        foreach (['locked_at', 'locked_reason'] as $col) {
            if ($users->hasColumn($col)) {
                $users->removeColumn($col)->update();
            }
        }
        if ($this->hasTable('sys_api_tokens')) {
            $tokens = $this->table('sys_api_tokens');
            if ($tokens->hasColumn('suspended_at')) {
                $tokens->removeColumn('suspended_at')->update();
            }
        }
    }
}
