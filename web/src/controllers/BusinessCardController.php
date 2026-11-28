<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/BusinessCardService.php';
require_once __DIR__ . '/../services/WebWidgetService.php';
require_once __DIR__ . '/../services/BillingRoleBridge.php';

/**
 * Integrations → Business cards: one card per person you hand a card to.
 *
 * Admin-only, like the widgets it calls through: a card can place calls and decides what
 * contact details go out to a stranger.
 *
 * The token is shown exactly once, right after the card is created or re-issued, because
 * only its hash is stored (BusinessCardService). That is a deliberate inconvenience - it is
 * what makes a stolen database useless - so this page carries the QR, the URL and the NFC
 * text at that moment and never again. Losing it means re-issuing, which kills the old QR.
 */
class BusinessCardController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $csrf = static::csrfToken();

        // A freshly issued token lives for one render and is never stored anywhere but here.
        $fresh = null;

        $notices = static::handlePost([
            'save_card' => function () use (&$fresh, $csrf) {
                return PBXHelper::handleAction($csrf, function () use (&$fresh) {
                    $id = (int) ($_POST['id'] ?? 0);
                    if ($id > 0) {
                        BusinessCardService::updateCard($id, $_POST);
                    } else {
                        $made = BusinessCardService::createCard($_POST);
                        $id = $made['id'];
                        $fresh = ['id' => $id, 'token' => $made['token']];
                    }
                    BusinessCardService::saveFields($id, self::postedFields());
                    // After the row exists, so a new card's pictures are named with its id.
                    BusinessCardService::applyImageUploads($id, $_POST);
                    return t('cards.saved');
                });
            },
            'revoke_card' => fn() => PBXHelper::handleAction($csrf, function () {
                BusinessCardService::revokeCard((int) ($_POST['card_id'] ?? 0));
                return t('cards.revoked');
            }),
            'reissue_card' => function () use (&$fresh, $csrf) {
                return PBXHelper::handleAction($csrf, function () use (&$fresh) {
                    $id = (int) ($_POST['card_id'] ?? 0);
                    $fresh = ['id' => $id, 'token' => BusinessCardService::reissueCard($id)];
                    return t('cards.reissued');
                });
            },
            'delete_card' => fn() => PBXHelper::handleAction($csrf, function () {
                BusinessCardService::deleteCard((int) ($_POST['card_id'] ?? 0));
                return t('cards.deleted');
            }),
        ]);

        // The QR and the links for a token that has just been issued.
        $freshView = null;
        if ($fresh !== null) {
            $card = BusinessCardService::getCard($fresh['id']);
            $url = BusinessCardService::cardUrl($fresh['token']);
            $freshView = [
                'label' => (string) ($card['label'] ?? ''),
                'url' => $url,
                'qr' => BusinessCardService::renderQr($url),
                // Only offered when the card actually has a PIN. The tag carries it so a tap
                // opens the card; the printed URL deliberately does not, so that a photograph
                // of the QR still needs the PIN.
                'nfc' => ($card['pin_hash'] ?? '') !== '' ? '' : $url,
            ];
        }

        $viewLogFor = (int) ($_GET['log'] ?? 0);

        static::renderPage('business_cards/index', [
            'cards' => BusinessCardService::allCards(BillingRoleBridge::listOwnerExtension()),
            // Only widgets the viewer owns, and only those with a profile turned on: a card
            // calls through a widget, so offering someone else's would hand them a call path
            // into an account that is not theirs.
            'widgets' => array_values(array_filter(
                WebWidgetService::listWidgets(BillingRoleBridge::listOwnerExtension()),
                static fn(array $w): bool => (int) ($w['profile_enabled'] ?? 0) === 1
            )),
            'fresh' => $freshView,
            'edit' => isset($_GET['edit']) ? BusinessCardService::getCard((int) $_GET['edit']) : null,
            'editFields' => isset($_GET['edit']) ? BusinessCardService::cardFields((int) $_GET['edit']) : [],
            'viewLogFor' => $viewLogFor,
            'viewLog' => $viewLogFor > 0 ? BusinessCardService::cardViews($viewLogFor) : [],
            'kinds' => BusinessCardService::KINDS,
        ], ['title' => t('cards.title')] + $notices);
    }

    /**
     * The field rows the form posted, as a list.
     *
     * The inputs arrive as parallel arrays (field_kind[], field_label[], field_value[]), so
     * they are zipped here by position. Anything the service does not recognise it drops;
     * this only has to hand over the shape it expects.
     *
     * @return list<array<string,string>>
     */
    private static function postedFields(): array
    {
        $kinds = (array) ($_POST['field_kind'] ?? []);
        $labels = (array) ($_POST['field_label'] ?? []);
        $values = (array) ($_POST['field_value'] ?? []);
        $out = [];
        foreach ($kinds as $i => $kind) {
            $out[] = [
                'kind' => is_scalar($kind) ? (string) $kind : 'text',
                'label' => isset($labels[$i]) && is_scalar($labels[$i]) ? (string) $labels[$i] : '',
                'value' => isset($values[$i]) && is_scalar($values[$i]) ? (string) $values[$i] : '',
            ];
        }
        return $out;
    }
}
