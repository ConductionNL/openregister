<?php

/**
 * DeckProvider — exposes Nextcloud Deck cards linked to an OpenRegister
 * object via the IntegrationProvider contract.
 *
 * Tier-2 (this file): delegates to {@see DeckLinkService} so the picker
 * UX surface (link existing card / create new card / list boards+stacks)
 * is available through the registry. The Tier-1 `DeckCardService` is
 * still wired upstream (RelationsController, ObjectCleanupListener) but
 * the provider has moved to the Tier-2 service to honour the explicit
 * `cardId`-keyed unlink + the widened (dueDate/labels/assignees) read
 * payload.
 *
 * Create payload routing:
 *   - `{ cardId }` only                          → linkCard
 *   - `{ boardId, stackId, title, ... }`         → createAndLinkCard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/integration-deck/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use Exception;
use OCA\OpenRegister\Service\DeckLinkService;
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
     * @param DeckLinkService $deckLinkService Tier-2 backing service.
     * @param IAppManager     $appManager      NC app manager.
     * @param IL10N           $l10n            Localisation.
     */
    public function __construct(
        private DeckLinkService $deckLinkService,
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
        return $this->deckLinkService->isDeckAvailable();
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
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Provider-contract args.
     *
     * @spec openspec/specs/integration-deck/spec.md
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        try {
            return $this->deckLinkService->getLinkedCards($objectId);
        } catch (Throwable $e) {
            return [];
        }
    }//end list()

    /**
     * Create or link a Deck card.
     *
     * Payload variants:
     *   - link existing — `{ cardId, registerId?, schemaId? }`
     *   - create new    — `{ boardId, stackId, title, description?, duedate?, registerId?, schemaId? }`
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $payload  Card data (see method docblock).
     *
     * @return array<string,mixed>
     *
     * @throws Exception When payload is missing required fields.
     *
     * @spec openspec/specs/integration-deck/spec.md
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $registerId = (int) ($payload['registerId'] ?? $register);
        $schemaId   = (int) ($payload['schemaId'] ?? $schema);

        $hasCardId    = (empty($payload['cardId']) === false);
        $hasBoardData = (empty($payload['boardId']) === false && empty($payload['stackId']) === false);

        if ($hasCardId === true) {
            $link = $this->deckLinkService->linkCard(
                $objectId,
                $registerId,
                $schemaId,
                (int) $payload['cardId']
            );

            return $link->jsonSerialize();
        }

        if ($hasBoardData === true) {
            $description = null;
            if (isset($payload['description']) === true) {
                $description = (string) $payload['description'];
            }

            $duedate = null;
            if (isset($payload['duedate']) === true) {
                $duedate = (string) $payload['duedate'];
            }

            $link = $this->deckLinkService->createAndLinkCard(
                $objectId,
                $registerId,
                $schemaId,
                (int) $payload['boardId'],
                (int) $payload['stackId'],
                (string) ($payload['title'] ?? 'Untitled'),
                $description,
                $duedate
            );

            return $link->jsonSerialize();
        }//end if

        throw new Exception('Either cardId or boardId+stackId is required', 400);
    }//end create()

    /**
     * Unlink a Deck card. The card itself stays in Deck.
     *
     * @param string $register Register slug or numeric id (unused).
     * @param string $schema   Schema slug or numeric id (unused).
     * @param string $objectId Object uuid.
     * @param string $entityId Deck card id (numeric).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Provider-contract args.
     *
     * @spec openspec/specs/integration-deck/spec.md
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->deckLinkService->unlinkCard($objectId, (int) $entityId);
    }//end delete()

    /**
     * Provider health descriptor (enabled/disabled echo).
     *
     * @return array<string,mixed>
     *
     * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
     *       the health/OCS contract is owned by pluggable-integration-registry task-2.
     */
    public function health(): array
    {
        $available = $this->deckLinkService->isDeckAvailable();

        $status  = 'unavailable';
        $message = 'NC Deck app is not installed';
        if ($available === true) {
            $status  = 'ok';
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];
    }//end health()
}//end class
