<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Hash stored invite tokens in place (core 1.75.65).
 *
 * From this version the invites plugin stores only the SHA-256 hash of a
 * token in plg_invites.token (see Invites::byToken), like the portal's
 * reset tokens (20261123000000). Outstanding invites keep working: rows
 * still holding a 32-char plaintext token are hashed here, with the same
 * function the plugin uses (MySQL/MariaDB SHA2(..., 256) is the lowercase
 * hex SHA-256 PHP's hash('sha256', ...) produces), so links already sent
 * out still match.
 *
 * Two limits, stated plainly. First, hashing cannot un-leak a token that
 * was already copied out of the database before this migration — such a
 * copy stays usable until the invite expires (at most 90 days). Clearing
 * every token instead, as 20261123000000 did for 30-minute reset tokens,
 * would have killed every outstanding invite with no way for the invitee
 * to ask for a new one; hashing in place was chosen because no database
 * exposure was ever found. Second, this runs as part of the 1.75.65
 * portal update, in the same release as the plugin change: updating the
 * invites plugin on its own, without this migration, leaves outstanding
 * invites unmatchable until the next core update runs it.
 *
 * Only 32-char rows are touched, so the migration is a no-op when run
 * again and leaves already-hashed (64-char) rows alone. One-way by
 * design: a hash cannot be turned back into the token.
 */
final class HashInviteTokens extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('plg_invites')) {
            return;
        }
        $invites = $this->table('plg_invites');
        if (!$invites->hasColumn('token')) {
            return;
        }
        $this->execute("UPDATE plg_invites SET token = SHA2(token, 256) WHERE CHAR_LENGTH(token) = 32");
    }

    public function down(): void
    {
        // One-way by design: hashes cannot be turned back into tokens.
    }
}
