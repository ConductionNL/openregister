<?php

/**
 * DeckProvider — exposes Nextcloud Deck cards linked to an OpenRegister
 * object via the IntegrationProvider contract.
 *
 * Backed by the already-shipped DeckCardService — link rows live in
 * `openregister_deck_links`, the cards themselves live in NC Deck and
 * are created via Deck's internal service classes (not OCS) so that
 * board/stack selection is honoured. The provider's `create()` is the
 * shared "link existing OR create new" entry point — callers pass
 * either `cardId` (link existing) or `boardId + stackId + title`
 * (create new), matching DeckCardService::linkOrCreateCard.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-deck/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * Deck (Kanban cards) integration provider.
 */
class DeckProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'deck';

    /**
     * Constructor.
     *
     * @param DeckCardService $deckCardService Backing service.
     * @param IAppManager     $appManager      NC app manager.
     * @param IL10N           $l10n            Localisation.
     *
     * @return void
     */
    public function __construct(
        private DeckCardService $deckCardService,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'deck';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Cards');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'ViewColumnOutline';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->deckCardService->isDeckAvailable();
    }//end isEnabled()

    /**
     * List deck cards linked to an OR object.
     *
     * @param string              $register Register slug or numeric id (unused — link rows resolve scope).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (currently ignored).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        try {
            $result = $this->deckCardService->getCardsForObject(objectUuid: $objectId);
        } catch (Throwable $e) {
            return [];
        }

        return $result['results'] ?? [];
    }//end list()

    /**
     * Create or link a Deck card.
     *
     * Payload shape mirrors DeckCardService::linkOrCreateCard — either
     * `cardId` (link existing) or `boardId + stackId + title +
     * description` (create new). `registerId` (numeric) is required.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id (unused — Deck cards are object-scoped).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $payload  Card data (see method docblock).
     *
     * @return array<string,mixed>
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $registerId = (int) ($payload['registerId'] ?? $register);
        $link       = $this->deckCardService->linkOrCreateCard(
            objectUuid: $objectId,
            registerId: $registerId,
            data: $payload
        );

        return $link->jsonSerialize();
    }//end create()

    /**
     * Unlink a Deck card. The card itself stays in Deck.
     *
     * @param string $register Register slug or numeric id (unused).
     * @param string $schema   Schema slug or numeric id (unused).
     * @param string $objectId Object uuid (unused).
     * @param string $entityId Numeric link id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->deckCardService->unlinkCard(linkId: (int) $entityId);
    }//end delete()

    public function health(): array
    {
        $available = $this->deckCardService->isDeckAvailable();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Deck app is not installed',
        ];
    }//end health()
}//end class
