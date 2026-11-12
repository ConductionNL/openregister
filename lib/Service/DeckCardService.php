<?php

/**
 * DeckCardService
 *
 * Service that wraps Nextcloud Deck card operations for linking cards to OpenRegister objects.
 * Uses the Deck app's internal PHP service classes when available.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapperInterface;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * DeckCardService manages Deck card-to-object links.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Service composes DeckLinkMapper,
 *   IAppManager, IUserSession, LoggerInterface, and dynamic Deck service classes; the set
 *   cannot be reduced as each dependency serves a distinct orchestration concern.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Deck's Card entity resolves
 *   getDuedate/getLabels/getAssignedUsers via OCP Entity::__call magic — method_exists()
 *   returns false for all three, so each must be individually wrapped in try/catch.
 *   There is no alternative API that avoids this pattern; complexity is intrinsic
 *   to defensive interop with the Deck app's internal entity layer.
 */
class DeckCardService implements DeckCardServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param DeckLinkMapperInterface $deckLinkMapper Deck link mapper
	 * @param LinkServiceRuntime      $runtime        Whether the app is enabled, the current user, and the logger
	 * @param IURLGenerator           $urlGenerator   URL generator (webroot-aware deep links)
	 * @param DeckPlatform            $deck           User manager for assignee display names and the container Deck's services are resolved from
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DeckLinkMapperInterface $deckLinkMapper,
		private readonly LinkServiceRuntime $runtime,
		private readonly IURLGenerator $urlGenerator,
		private readonly DeckPlatform $deck,
	) {
	}//end __construct()

	/**
	 * Check if the Nextcloud Deck app is installed and enabled.
	 *
	 * @return bool True if Deck is available.
	 */
	public function isDeckAvailable(): bool {
		return $this->runtime->appManager()->isEnabledForUser('deck');
	}//end isDeckAvailable()

	/**
	 * Get all deck links for an object.
	 *
	 * Each row is the canonical {@see DeckLink::jsonSerialize()} payload
	 * enriched with `dueDate`, `labels`, and `assignees` resolved from
	 * the underlying Deck card via Deck's CardService:
	 *   * `dueDate`   — ISO 8601 datetime string ("2026-06-01T12:00:00+00:00")
	 *                   or `null` when the card has no due date.
	 *   * `labels`    — array of `{id, title, color}` (color preserved as
	 *                   Deck stores it — hex without the leading `#`).
	 *   * `assignees` — array of `{uid, type, displayName}`. The Deck
	 *                   Assignment entity exposes `getParticipant()`
	 *                   (a uid for type=USER) and `getType()` (user /
	 *                   group / circle). The provider surfaces type so
	 *                   the UI can render a distinct chip when an
	 *                   assignment is to a group/circle.
	 *
	 * Enrichment is best-effort: if Deck's CardService isn't available
	 * (Deck disabled at runtime) or `find()` throws for an individual
	 * card (deleted from Deck but link row stale), the widened fields
	 * fall back to `null` / `[]` and the link is still surfaced.
	 *
	 * Idempotent: re-running the enrichment doesn't double-write — the
	 * widened keys are computed fresh each call.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return array{results: array, total: int}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function getCardsForObject(string $objectUuid): array {
		$links = $this->deckLinkMapper->findByObjectUuid($objectUuid);

		// Resolve Deck's CardService once; null when unavailable so we
		// don't pay the lookup cost per link.
		$cardService = $this->resolveDeckCardService();

		$results = array_map(
			fn (DeckLink $link): array => $this->linkedCardRow(link: $link, cardService: $cardService),
			$links
		);

		return ['results' => $results, 'total' => count($results)];
	}//end getCardsForObject()

	/**
	 * One linked card as a row, widened with Deck's card fields and a deep link.
	 *
	 * @param DeckLink    $link        The link row.
	 * @param object|null $cardService Deck's CardService, or null when unavailable.
	 *
	 * @return array<string, mixed>
	 */
	private function linkedCardRow(DeckLink $link, ?object $cardService): array {
		$row = $link->jsonSerialize();
		$widened = $this->extractCardFields(cardService: $cardService, cardId: (int)$link->getCardId());
		$row += $widened;

		// Deep-link to the specific card in the Deck app: reusing the
		// canonical {app}.page.index route keeps the webroot prefix
		// correct (mirrors MapLinkService/PhotoLinkService).
		$deepLink = $this->buildCardDeepLink(boardId: (int)$link->getBoardId(), cardId: (int)$link->getCardId());
		if ($deepLink !== null) {
			$row['url'] = $deepLink;
		}

		return $row;
	}//end linkedCardRow()

	/**
	 * Build a webroot-aware deep-link to a specific Deck card.
	 *
	 * Returns `/index.php/apps/deck/board/{boardId}/card/{cardId}` (with the
	 * correct webroot prefix). Returns null when either identifier is missing
	 * so the relation record is returned without a `url` rather than a broken one.
	 *
	 * @param int $boardId The Deck board id; 0 when unknown.
	 * @param int $cardId  The Deck card id; 0 when unknown.
	 *
	 * @return string|null The deep-link URL, or null when not resolvable.
	 */
	private function buildCardDeepLink(int $boardId, int $cardId): ?string {
		if ($boardId === 0 || $cardId === 0) {
			return null;
		}

		try {
			$base = $this->urlGenerator->linkToRoute('deck.page.index');
		} catch (\Throwable $e) {
			return null;
		}

		return rtrim($base, '/') . '/board/' . $boardId . '/card/' . $cardId;
	}//end buildCardDeepLink()

	/**
	 * Resolve Deck's CardService from the server container.
	 *
	 * @return object|null The CardService, or `null` when Deck is not
	 *                     installed / its service class can't be
	 *                     resolved.
	 */
	private function resolveDeckCardService(): ?object {
		if (class_exists('OCA\\Deck\\Service\\CardService') === false) {
			return null;
		}

		try {
			return $this->deck->container->get('OCA\\Deck\\Service\\CardService');
		} catch (\Throwable $e) {
			$this->runtime->logger()->debug('Deck CardService not resolvable: ' . $e->getMessage());
			return null;
		}
	}//end resolveDeckCardService()

	/**
	 * Extract the widened `dueDate`, `labels`, `assignees` payload from
	 * a Deck card by its id.
	 *
	 * Always returns the full key set, even on lookup failure, so the
	 * caller can safely union with the link row.
	 *
	 * @param object|null $cardService Deck CardService (may be null).
	 * @param int         $cardId      Deck card id from the link row; 0 when unknown.
	 *
	 * @return array{dueDate: ?string, labels: array, assignees: array}
	 */
	private function extractCardFields(?object $cardService, int $cardId): array {
		$defaults = ['dueDate' => null, 'labels' => [], 'assignees' => []];

		if ($cardService === null || $cardId === 0) {
			return $defaults;
		}

		$card = $this->findCard(cardService: $cardService, cardId: $cardId);
		if ($card === null) {
			return $defaults;
		}

		// NB: Deck's Card entity (and its CardDetails wrapper) resolves
		// getters via OCP\AppFramework\Db\Entity::__call magic — so PHP's
		// `method_exists()` returns false for `getDuedate`, `getLabels`,
		// and `getAssignedUsers` even though the calls work. Each call
		// is therefore wrapped in its own try/catch.
		return [
			'dueDate' => $this->extractDueDate(card: $card),
			'labels' => $this->extractLabels(card: $card),
			'assignees' => $this->extractAssignees(card: $card),
		];
	}//end extractCardFields()

	/**
	 * Find a Deck card; null when it is gone (stale link row) or not an object.
	 *
	 * @param object $cardService Deck CardService.
	 * @param int    $cardId      Deck card id.
	 *
	 * @return object|null
	 */
	private function findCard(object $cardService, int $cardId): ?object {
		try {
			$card = $cardService->find($cardId);
		} catch (\Throwable $e) {
			// Card deleted from Deck (link row is stale) — degrade gracefully.
			return null;
		}

		if (is_object($card) === false) {
			return null;
		}

		return $card;
	}//end findCard()

	/**
	 * Extract the due date string from a Deck card entity.
	 *
	 * Returns null when the card has no due date or `getDuedate()` is unavailable
	 * (resolved via OCP Entity magic — method_exists returns false).
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return string|null ISO 8601 datetime, or null.
	 */
	private function extractDueDate(object $card): ?string {
		try {
			$due = $card->getDuedate();
			if ($due instanceof \DateTimeInterface) {
				return $due->format(\DateTime::ATOM);
			}
		} catch (\Throwable $e) {
			// GetDuedate missing on this Deck version — leave null.
			$this->runtime->logger()->debug('[DeckCardService] Card due date not readable: ' . $e->getMessage());
		}

		return null;
	}//end extractDueDate()

	/**
	 * Extract the labels array from a Deck card entity.
	 *
	 * Returns an empty array when `getLabels()` is unavailable or returns an
	 * unexpected shape.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return array
	 */
	private function extractLabels(object $card): array {
		try {
			$rawLabels = $card->getLabels();
			if (is_array($rawLabels) === true) {
				$labels = [];
				foreach ($rawLabels as $label) {
					$labels[] = $this->mapLabel(label: $label);
				}

				return $labels;
			}
		} catch (\Throwable $e) {
			// GetLabels missing or returned unexpected shape — leave empty.
			$this->runtime->logger()->debug('[DeckCardService] Card labels not readable: ' . $e->getMessage());
		}

		return [];
	}//end extractLabels()

	/**
	 * Extract the assignees array from a Deck card entity.
	 *
	 * Returns an empty array when `getAssignedUsers()` is unavailable.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return array
	 */
	private function extractAssignees(object $card): array {
		try {
			$rawAssignees = $card->getAssignedUsers();
			if (is_array($rawAssignees) === true) {
				$assignees = [];
				foreach ($rawAssignees as $assignment) {
					$assignees[] = $this->mapAssignee(assignment: $assignment);
				}

				return $assignees;
			}
		} catch (\Throwable $e) {
			// GetAssignedUsers missing — leave empty.
			$this->runtime->logger()->debug('[DeckCardService] Card assignees not readable: ' . $e->getMessage());
		}

		return [];
	}//end extractAssignees()

	/**
	 * Map a Deck Label entity to the leaf-row label shape.
	 *
	 * @param object $label Deck Label.
	 *
	 * @return array{id: ?int, title: string, color: string}
	 */
	private function mapLabel(object $label): array {
		return (new DeckRowMapper(userManager: $this->deck->userManager))->label(label: $label);
	}//end mapLabel()

	/**
	 * Map a Deck Assignment entity to the leaf-row assignee shape.
	 *
	 * `participant` carries the user uid (TYPE_USER), group name (TYPE_GROUP),
	 * or circle id (TYPE_CIRCLE); we surface it as `uid` plus a typed
	 * label so the UI can render a distinct chip for non-user assignments.
	 *
	 * @param object $assignment Deck Assignment.
	 *
	 * @return array{uid: string, type: string, displayName: string}
	 */
	private function mapAssignee(object $assignment): array {
		return (new DeckRowMapper(userManager: $this->deck->userManager))->assignee(assignment: $assignment);
	}//end mapAssignee()

	/**
	 * Create a new Deck card linked to an object, or link an existing card.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $registerId The register ID.
	 * @param array $data Card data: boardId, stackId, title, description, or cardId for existing.
	 *
	 * @return DeckLink The created link.
	 *
	 * @throws Exception If parameters are missing or Deck operations fail.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function linkOrCreateCard(string $objectUuid, int $registerId, array $data): DeckLink {
		return $this->linkCardTo(target: new LinkTarget(objectUuid: $objectUuid, registerId: $registerId), data: $data);
	}//end linkOrCreateCard()

	/**
	 * Link the requested existing card to the target object, or create one and link it.
	 *
	 * @param LinkTarget          $target The object and register to link to.
	 * @param array<string,mixed> $data   Card data: boardId, stackId, title, description, or cardId for existing.
	 *
	 * @return DeckLink The created link.
	 *
	 * @throws Exception If parameters are missing or Deck operations fail.
	 */
	private function linkCardTo(LinkTarget $target, array $data): DeckLink {
		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$card = $this->requestedCardFields(objectUuid: $target->objectUuid, data: $data);

		$link = $this->newCardLink(target: $target, card: $card);
		$link->setLinkedBy($user->getUID());
		$link->setLinkedAt(new DateTime());

		return $this->deckLinkMapper->insert($link);
	}//end linkCardTo()

	/**
	 * The fields of the card a request links: an existing card, or a new one it creates.
	 *
	 * @param string               $objectUuid The object to link to.
	 * @param array<string, mixed> $data       The request body.
	 *
	 * @return array{boardId: int, stackId: int, cardId: int, cardTitle: string}
	 */
	private function requestedCardFields(string $objectUuid, array $data): array {
		if ($this->requireCardRequest(data: $data) === true) {
			// Link existing card.
			return $this->existingCardFields(objectUuid: $objectUuid, cardId: (int)$data['cardId']);
		}

		// Create new card.
		return $this->newCardFields(objectUuid: $objectUuid, data: $data);
	}//end requestedCardFields()

	/**
	 * The link row of a card to an object, before who linked it and when.
	 *
	 * @param LinkTarget                                                       $target The object to link to.
	 * @param array{boardId: int, stackId: int, cardId: int, cardTitle: string} $card   The card's fields.
	 *
	 * @return DeckLink The unsaved link.
	 */
	private function newCardLink(LinkTarget $target, array $card): DeckLink {
		$link = new DeckLink();
		$link->setObjectUuid($target->objectUuid);
		$link->setRegisterId($target->registerId);
		$link->setBoardId($card['boardId']);
		$link->setStackId($card['stackId']);
		$link->setCardId($card['cardId']);
		$link->setCardTitle($card['cardTitle']);

		return $link;
	}//end newCardLink()

	/**
	 * Check the request names an existing card or a board and stack to create one on.
	 *
	 * @param array<string,mixed> $data Card data.
	 *
	 * @return bool True to link an existing card, false to create a new one.
	 *
	 * @throws Exception When neither cardId nor boardId+stackId is given.
	 */
	private function requireCardRequest(array $data): bool {
		$hasCardId = (empty($data['cardId']) === false);
		$hasBoardData = (empty($data['boardId']) === false && empty($data['stackId']) === false);

		if ($hasCardId === false && $hasBoardData === false) {
			throw new Exception('Either cardId or boardId+stackId is required');
		}

		return $hasCardId;
	}//end requireCardRequest()

	/**
	 * The link fields of an existing Deck card not yet linked to the object.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int    $cardId     The Deck card id.
	 *
	 * @return array{cardId:int,cardTitle:mixed,boardId:mixed,stackId:mixed}
	 *
	 * @throws Exception When the card is not found (404) or already linked (409).
	 */
	private function existingCardFields(string $objectUuid, int $cardId): array {
		$cardInfo = $this->getDeckCardInfo(cardId: $cardId);
		if ($cardInfo === null) {
			throw new Exception('Deck card not found', 404);
		}

		$fields = [
			'cardId' => $cardId,
			'cardTitle' => $cardInfo['title'] ?? 'Unknown',
			'boardId' => $cardInfo['boardId'] ?? 0,
			'stackId' => $cardInfo['stackId'] ?? 0,
		];

		$this->assertCardNotLinked(objectUuid: $objectUuid, cardId: $cardId);

		return $fields;
	}//end existingCardFields()

	/**
	 * Refuse a card the object already links.
	 *
	 * @param string $objectUuid The object.
	 * @param int    $cardId     Deck card ID.
	 *
	 * @return void
	 *
	 * @throws Exception When the card is already linked (409).
	 */
	private function assertCardNotLinked(string $objectUuid, int $cardId): void {
		$existing = $this->deckLinkMapper->findByObjectAndCard($objectUuid, $cardId);
		if ($existing !== null) {
			throw new Exception('Card already linked to this object', 409);
		}
	}//end assertCardNotLinked()

	/**
	 * Create a Deck card on the requested board and stack and return its link fields.
	 *
	 * @param string              $objectUuid The object UUID for the back-link.
	 * @param array<string,mixed> $data       Card data: boardId, stackId, title, description.
	 *
	 * @return array{cardId:int,cardTitle:mixed,boardId:int,stackId:int}
	 *
	 * @throws Exception When the card cannot be created.
	 */
	private function newCardFields(string $objectUuid, array $data): array {
		$boardId = (int)$data['boardId'];
		$stackId = (int)$data['stackId'];
		$cardTitle = $data['title'] ?? 'Untitled';

		$cardId = $this->createDeckCard(
			card: new CardDraft(title: $cardTitle, stackId: $stackId),
			description: $this->cardDescription(description: ($data['description'] ?? ''), objectUuid: $objectUuid)
		);

		if ($cardId === null) {
			throw new Exception('Failed to create Deck card');
		}

		return ['cardId' => $cardId, 'cardTitle' => $cardTitle, 'boardId' => $boardId, 'stackId' => $stackId];
	}//end newCardFields()

	/**
	 * Remove a deck link.
	 *
	 * @param int $linkId The link ID.
	 *
	 * @return void
	 *
	 * @throws Exception If link not found.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function unlinkCard(int $linkId): void {
		try {
			$link = $this->deckLinkMapper->find($linkId);
			$this->deckLinkMapper->delete($link);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new Exception('Deck link not found', 404);
		}
	}//end unlinkCard()

	/**
	 * Find all objects linked to cards on a board.
	 *
	 * @param int $boardId The Deck board ID.
	 *
	 * @return array Array of deck links.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function getObjectsForBoard(int $boardId): array {
		// IDOR guard: only return links for a board the caller can access in
		// Deck. Without this any authenticated user could enumerate board IDs
		// and read OpenRegister object links for boards they have no Deck
		// access to (the deckLinkMapper table bypasses Deck's own ACL).
		if ($this->userCanAccessBoard(boardId: $boardId) === false) {
			return [];
		}

		$links = $this->deckLinkMapper->findByBoardId($boardId);

		return array_map(
			static function (DeckLink $link): array {
				return $link->jsonSerialize();
			},
			$links
		);
	}//end getObjectsForBoard()

	/**
	 * Whether the current session user may read the given Deck board.
	 *
	 * Delegates to Deck's own BoardService::find(), which enforces the board
	 * ACL and throws when the session user lacks read access. Fail-closed:
	 * returns false when Deck is unavailable or access is denied, so no link
	 * metadata leaks for a board the caller cannot see.
	 *
	 * @param int $boardId The Deck board ID.
	 *
	 * @return bool True when the caller may read the board.
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	protected function userCanAccessBoard(int $boardId): bool {
		if (class_exists('OCA\\Deck\\Service\\BoardService') === false) {
			return false;
		}

		try {
			$boardService = $this->deck->container->get('OCA\\Deck\\Service\\BoardService');
			// Find() throws (NoPermissionException) when the session user
			// lacks read access to the board.
			$boardService->find($boardId);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}//end userCanAccessBoard()

	/**
	 * Delete all deck links for an object (cleanup).
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted links.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function deleteLinksForObject(string $objectUuid): int {
		return $this->deckLinkMapper->deleteByObjectUuid($objectUuid);
	}//end deleteLinksForObject()

	/**
	 * Get Deck card info by card ID using Deck's services.
	 *
	 * Resolves the board ID via `CardMapper::findBoardId()` because the
	 * Deck `Card` entity exposes only `getStackId()` — there is no
	 * `getBoardId()` method (board is reachable via the stack). Mirrors the
	 * pattern used inside Deck's own `CardService::update()` (line 331 in
	 * deck/lib/Service/CardService.php).
	 *
	 * @param int $cardId The card ID.
	 *
	 * @return array|null Card info or null.
	 */
	private function getDeckCardInfo(int $cardId): ?array {
		try {
			// Try using Deck's CardService if available.
			if (class_exists('OCA\Deck\Service\CardService') === true) {
				return $this->deckCardRow(cardId: $cardId);
			}
		} catch (Exception $e) {
			$this->runtime->logger()->debug('Deck CardService not available, card lookup skipped: ' . $e->getMessage());
		}//end try

		return null;
	}//end getDeckCardInfo()

	/**
	 * A Deck card's title, board and stack, read through Deck's services.
	 *
	 * @param int $cardId Deck card ID.
	 *
	 * @return array{title: mixed, boardId: int, stackId: mixed}
	 */
	private function deckCardRow(int $cardId): array {
		$cardService = $this->deck->container->get('OCA\Deck\Service\CardService');
		$card = $cardService->find($cardId);
		$boardId = $this->deckCardBoardId(cardId: $cardId);

		return [
			'title' => $card->getTitle(),
			'boardId' => $boardId,
			'stackId' => $card->getStackId(),
		];
	}//end deckCardRow()

	/**
	 * The board a Deck card sits on.
	 *
	 * Board ID is not a Card property — look it up via CardMapper,
	 * which is how Deck itself derives it (see CardService::update).
	 *
	 * @param int $cardId Deck card ID.
	 *
	 * @return int The board id, or 0 when Deck's CardMapper is absent or knows none.
	 */
	private function deckCardBoardId(int $cardId): int {
		$boardId = 0;
		if (class_exists('OCA\Deck\Db\CardMapper') === true) {
			$cardMapper = $this->deck->container->get('OCA\Deck\Db\CardMapper');
			$boardId = ($cardMapper->findBoardId($cardId) ?? 0);
		}

		return $boardId;
	}//end deckCardBoardId()

	/**
	 * The description of a new card: the given text, then a back-link to the object.
	 *
	 * @param string $description The card description.
	 * @param string $objectUuid  The object UUID for the back-link.
	 *
	 * @return string The full description.
	 */
	private function cardDescription(string $description, string $objectUuid): string {
		$fullDescription = $description;
		if (empty($fullDescription) === false) {
			$fullDescription .= "\n\n";
		}

		return $fullDescription . '[Object: ' . $objectUuid . '](/apps/openregister/objects/' . $objectUuid . ')';
	}//end cardDescription()

	/**
	 * Create a Deck card using Deck's service classes.
	 *
	 * @param CardDraft $card        The card title and stack.
	 * @param string    $description The full card description, back-link included.
	 *
	 * @return int|null The created card ID or null.
	 */
	private function createDeckCard(CardDraft $card, string $description): ?int {
		try {
			if (class_exists('OCA\Deck\Service\CardService') === true) {
				$cardService = $this->deck->container->get('OCA\Deck\Service\CardService');

				$userUid = $this->runtime->userSession()->getUser()->getUID();
				$created = $cardService->create($card->title, $card->stackId, 'plain', 0, $userUid);
				// Deck CardService::update signature is:
				// update($id, $title, $stackId, $type, $owner, $description = '', $order = 0, ...)
				// The owner argument is required and must be a string user UID;
				// a previous version of this call passed 0 (zero) for owner and
				// mis-aligned every subsequent arg by one slot. Restore the
				// correct positional order so updating a freshly-created card
				// doesn't throw.
				$cardService->update($created->getId(), $card->title, $card->stackId, 'plain', $userUid, $description, 0);

				return $created->getId();
			}//end if
		} catch (Exception $e) {
			$this->runtime->logger()->warning('Failed to create Deck card: ' . $e->getMessage());
		}//end try

		return null;
	}//end createDeckCard()
}//end class
