<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Digital business cards: one card per person you hand a card to (core 1.75.82).
 *
 * - pbx_business_cards:       one row per printed card. The QR, the NFC tag and the
 *                            short link all carry that row's token, so the card you
 *                            gave to one person is the only thing that opens it.
 * - pbx_business_card_fields: the lines shown on that card, in order. Kept in rows
 *                            rather than a JSON blob so each card can show a
 *                            different set without the public page ever having to
 *                            parse anything a person typed.
 * - pbx_business_card_views:  append-only view log. The point of a card per person
 *                            is that a leak is traceable: this says which card was
 *                            opened, when and from where, so one card can be revoked
 *                            without disturbing any of the others.
 *
 * The token is stored ONLY as its SHA-256 hash, as plg_invites does since
 * 20261123020000 and the portal's reset tokens since 20261123000000. A copy of this
 * database therefore yields no working card URLs. The consequence is the usual one
 * for a hash: the plaintext token cannot be shown again after the card is created,
 * so the admin page offers "re-issue" (a new token, old QR dead) rather than
 * "show me the link again".
 *
 * 128 bits of randomness (bin2hex(random_bytes(16))) is what makes the URL itself
 * the secret. It is never typed by hand - the QR or the tag carries it - so there is
 * no reason to trade length for convenience. The optional PIN is a second factor for
 * a card whose QR might be photographed; it is stored with password_hash(), never in
 * the clear, and it is deliberately NOT the only gate, because four digits is ten
 * thousand guesses and bots walk that in an afternoon.
 */
final class CreateBusinessCards extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_business_cards', ['signed' => false])
            // The label is for the owner's eyes only ("Alice at Contoso, met at the expo") and
            // is never sent to the public page: it is how you know which card to revoke.
            ->addColumn('label', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            // Shown on the card itself.
            ->addColumn('display_name', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('job_title', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('company', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('tagline', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('accent_color', 'string', ['limit' => 7, 'null' => false, 'default' => '#2563eb'])
            // Calling goes through a web widget, which already issues one-time WebRTC
            // credentials - so the real number never reaches the page. Null means this card
            // shows no call button at all.
            ->addColumn('widget_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('call_enabled', 'boolean', ['null' => false, 'default' => 1])
            // Spam control, per card, because that is the whole point of a card per person.
            ->addColumn('calls_daily_limit', 'integer', ['null' => false, 'default' => 5])
            ->addColumn('max_views', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('view_count', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('expires_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('revoked_at', 'datetime', ['null' => true, 'default' => null])
            // Empty means no PIN. password_hash() output, so never reversible.
            ->addColumn('pin_hash', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('last_viewed_at', 'datetime', ['null' => true, 'default' => null])
            // The public page finds a card by this and nothing else.
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['is_active'])
            ->create();

        $this->table('pbx_business_card_fields', ['signed' => false])
            ->addColumn('card_id', 'integer', ['signed' => false, 'null' => false])
            // 'text' shows a line; 'link' shows a tappable row (the linktree part). Which of
            // the two decides how the value is treated on output, so it is a short keyword
            // from a fixed list and never free text.
            ->addColumn('kind', 'string', ['limit' => 12, 'null' => false, 'default' => 'text'])
            ->addColumn('icon', 'string', ['limit' => 40, 'null' => false, 'default' => ''])
            ->addColumn('label', 'string', ['limit' => 80, 'null' => false, 'default' => ''])
            ->addColumn('value', 'string', ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addForeignKey('card_id', 'pbx_business_cards', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['card_id', 'sort_order'])
            ->create();

        $this->table('pbx_business_card_views', ['signed' => false])
            ->addColumn('card_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            // 'view', 'pin_failed', 'call' - what happened. A refused attempt is logged with a
            // null card_id when no card matched, so a scanner sweeping the URL space leaves a
            // trail without telling us which tokens it guessed.
            ->addColumn('result', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false, 'default' => ''])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('referer', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['card_id', 'created_at'])
            // The per-IP rate limit reads this one.
            ->addIndex(['ip', 'created_at'])
            ->create();
    }

    public function down(): void
    {
        // Fields and views first: both carry a foreign key or an index pointing at the cards.
        foreach (['pbx_business_card_views', 'pbx_business_card_fields', 'pbx_business_cards'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }
}
