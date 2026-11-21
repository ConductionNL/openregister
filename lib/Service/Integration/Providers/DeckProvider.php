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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use Exception;
use OCA\OpenRegister\Service\CardDraft;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * Deck (Kanban cards) integration provider.
 */
class DeckProvider extends AbstractIntegrationProvider {
	use LinkTableAppDescriptor;

	/**
	 * NC app id required for this integration.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'deck';

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param AppProviderDescription $description     The app manager and localisation.
	 * @param DeckLinkService        $deckLinkService Tier-2 backing service.
	 */
	public function __construct(
		AppProviderDescription $description,
		private DeckLinkService $deckLinkService,
	) {
		$this->appManager = $description->appManager;
		$this->l10n = $description->l10n;
	}//end __construct()

	public function getId(): string {
		return 'deck';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Cards');
	}//end getLabel()

	public function getIcon(): string {
		return 'ViewColumnOutline';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	public function isEnabled(): bool {
		return $this->deckLinkService->isDeckAvailable();
	}//end isEnabled()

	/**
	 * List deck cards linked to an OR object.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $filters Optional filters (currently ignored).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Provider-contract args.
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		return $this->cardsOf(objectId: $ref->id);
	}//end list()

	/**
	 * The Deck cards linked to an object; none when they cannot be read.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cardsOf(string $objectId): array {
		try {
			return $this->deckLinkService->getLinkedCards($objectId);
		} catch (Throwable $e) {
			return [];
		}
	}//end cardsOf()

	/**
	 * Create or link a Deck card.
	 *
	 * Payload variants:
	 *   - link existing — `{ cardId, registerId?, schemaId? }`
	 *   - create new    — `{ boardId, stackId, title, description?, duedate?, registerId?, schemaId? }`
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $payload Card data (see method docblock).
	 *
	 * @return array<string,mixed>
	 *
	 * @throws Exception When payload is missing required fields.
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function create(ObjectRef $ref, array $payload): array {
		return $this->linkCardFor(ref: $ref, payload: $payload);
	}//end create()

	/**
	 * Link an existing card, or create one on a board and stack and link it.
	 *
	 * @param ObjectRef           $ref     The owning object; the payload may name its register and schema ids.
	 * @param array<string,mixed> $payload `cardId`, or `boardId` + `stackId` and the card fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws Exception When neither a card nor a board and stack is named.
	 */
	private function linkCardFor(ObjectRef $ref, array $payload): array {
		$registerId = (int)($payload['registerId'] ?? $ref->register);
		$schemaId = (int)($payload['schemaId'] ?? $ref->schema);
		$target = (new LinkTarget(objectUuid: $ref->id, registerId: $registerId))->inSchema(schemaId: $schemaId);

		if (empty($payload['cardId']) === false) {
			return $this->deckLinkService->linkCard(target: $target, cardId: (int)$payload['cardId'])->jsonSerialize();
		}

		if (empty($payload['boardId']) === true || empty($payload['stackId']) === true) {
			throw new Exception('Either cardId or boardId+stackId is required', 400);
		}

		$card = $this->cardDraft(payload: $payload);

		return $this->deckLinkService->createAndLinkCard(target: $target, card: $card)->jsonSerialize();
	}//end linkCardFor()

	/**
	 * The new card a payload describes.
	 *
	 * @param array<string,mixed> $payload Payload with boardId and stackId.
	 *
	 * @return CardDraft The card.
	 */
	private function cardDraft(array $payload): CardDraft {
		$card = new CardDraft(title: (string)($payload['title'] ?? 'Untitled'), stackId: (int)$payload['stackId']);
		$description = $this->optionalText(payload: $payload, key: 'description');
		$duedate = $this->optionalText(payload: $payload, key: 'duedate');

		return $card->onBoard(boardId: (int)$payload['boardId'])->withDetails(description: $description, duedate: $duedate);
	}//end cardDraft()

	/**
	 * A payload field as text, null when absent.
	 *
	 * @param array<string,mixed> $payload The payload.
	 * @param string              $key     The field.
	 *
	 * @return string|null The text.
	 */
	private function optionalText(array $payload, string $key): ?string {
		if (isset($payload[$key]) === false) {
			return null;
		}

		return (string)$payload[$key];
	}//end optionalText()

	/**
	 * Unlink a Deck card. The card itself stays in Deck.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId Deck card id (numeric).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Provider-contract args.
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function delete(ObjectRef $ref, string $entityId): void {
		$this->deckLinkService->unlinkCard($ref->id, (int)$entityId);
	}//end delete()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *       the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->deckLinkService->isDeckAvailable(), missing: 'NC Deck app is not installed');
	}//end health()
}//end class
