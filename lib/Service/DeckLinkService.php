<?php

/**
 * DeckLinkService — Tier-2 deck integration service.
 *
 * Composes the {@see DeckLinkMapper} with Deck's internal `CardService`
 * and (Tier-1) `DeckCardService` to provide the picker + inline-create
 * UX surface area:
 *
 *   - linkCard(uuid, registerId, schemaId, cardId)         — link existing card
 *   - createAndLinkCard(target, card)                     — create + link new
 *   - unlinkCard(uuid, cardId)
 *   - getLinkedCards(uuid)                                — list, enriched
 *   - getAvailableBoards()
 *   - getStacksForBoard(boardId)
 *
 * Card create flow re-uses {@see DeckCardService::createDeckCard}
 * (kept private for Tier-1 link-or-create) via a thin wrapper to honour
 * the owner-arg fix (Phase A commit 3cdbfe71a) and the board-id
 * lookup (Phase F2 commit 72fceb720). When Deck is unavailable, list
 * still returns the persisted link row so historical references
 * survive even after Deck is uninstalled.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapperInterface;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * DeckLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     Deck's CardService/BoardService/StackService + user mgmt. Each
 *     dependency is required.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Defensive try/catch
 *     blocks around every getter on Deck's Card/Label/Assignment
 *     entities (which use Entity::__call magic) inflate the cyclomatic
 *     score; splitting into helper classes would scatter the leaf-row
 *     contract.
 */
class DeckLinkService implements DeckLinkServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param DeckLinkMapperInterface $deckLinkMapper Persistence for link rows.
	 * @param LinkServiceRuntime      $runtime        App manager, active session and logger.
	 * @param IUserManager            $userManager    User lookup for assignee displayName.
	 * @param ContainerInterface      $container      App container Deck's services are resolved from.
	 */
	public function __construct(
		private readonly DeckLinkMapperInterface $deckLinkMapper,
		private readonly LinkServiceRuntime $runtime,
		private readonly IUserManager $userManager,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether NC Deck is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isDeckAvailable(): bool {
		return $this->runtime->appManager()->isEnabledForUser('deck');
	}//end isDeckAvailable()

	/**
	 * Link an existing Deck card to an OR object.
	 *
	 * The link row carries board_id/stack_id/title/dueDate/labels/assignees
	 * harvested from the Deck card so subsequent reads don't need to hit
	 * Deck. Idempotent: a duplicate link raises a 409 Exception (callers
	 * should surface as HTTP 409 to the UI).
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $cardId Deck card id.
	 *
	 * @return DeckLink The persisted link row.
	 *
	 * @throws Exception On missing user, missing card (404), duplicate (409).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkCard(LinkTarget $target, int $cardId): DeckLink {
		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$this->assertCardNotLinked(target: $target, cardId: $cardId);
		$card = $this->findCard(cardService: $this->requireCardService(), cardId: $cardId);

		$link = $this->existingCardLink(card: $card, cardId: $cardId);
		$target->stamp(link: $link, linkedBy: $user->getUID());

		return $this->deckLinkMapper->insert($link);
	}//end linkCard()

	/**
	 * Refuse a card the object already links.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int        $cardId Deck card id.
	 *
	 * @return void
	 *
	 * @throws Exception When the card is already linked (409).
	 */
	private function assertCardNotLinked(LinkTarget $target, int $cardId): void {
		$existing = $this->deckLinkMapper->findByObjectAndCard($target->objectUuid, $cardId);
		if ($existing !== null) {
			throw new Exception('Card already linked to this object', 409);
		}
	}//end assertCardNotLinked()

	/**
	 * Deck's CardService.
	 *
	 * @return object
	 *
	 * @throws Exception When Deck is not available (503).
	 */
	private function requireCardService(): object {
		$cardService = $this->resolveCardService();
		if ($cardService === null) {
			throw new Exception('Deck is not available', 503);
		}

		return $cardService;
	}//end requireCardService()

	/**
	 * The link row of an existing card, with its board, stack, title, due date, labels and assignees.
	 *
	 * @param object $card   The Deck card.
	 * @param int    $cardId Deck card id.
	 *
	 * @return DeckLink The unsaved, unstamped link.
	 */
	private function existingCardLink(object $card, int $cardId): DeckLink {
		$boardId = $this->resolveBoardId(cardId: $cardId);
		$stackId = $this->cardStackId(card: $card);
		$title = $this->cardTitle(card: $card);
		$widened = $this->extractCardFields(card: $card);

		$link = new DeckLink();
		$link->setBoardId($boardId);
		$link->setStackId($stackId);
		$link->setCardId($cardId);
		$link->setCardTitle($title);
		$link->setDueDate($widened['dueDateRaw']);
		$link->setLabels($this->jsonListOrNull(list: $widened['labels']));
		$link->setAssignees($this->jsonListOrNull(list: $widened['assignees']));

		return $link;
	}//end existingCardLink()

	/**
	 * Find a Deck card by id.
	 *
	 * @param object $cardService Deck CardService.
	 * @param int    $cardId      Deck card id.
	 *
	 * @return object The card.
	 *
	 * @throws Exception When the card cannot be found (404).
	 */
	private function findCard(object $cardService, int $cardId): object {
		try {
			$card = $cardService->find($cardId);
		} catch (Throwable $e) {
			throw new Exception('Deck card not found', 404);
		}

		if ($card === null) {
			throw new Exception('Deck card not found', 404);
		}

		return $card;
	}//end findCard()

	/**
	 * The stack id of a card, or 0 when it cannot be read.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return int
	 */
	private function cardStackId(object $card): int {
		try {
			return (int)$card->getStackId();
		} catch (Throwable $e) {
			// Leave default zero.
			return 0;
		}
	}//end cardStackId()

	/**
	 * The title of a card, or '' when it cannot be read.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return string
	 */
	private function cardTitle(object $card): string {
		try {
			return (string)$card->getTitle();
		} catch (Throwable $e) {
			// Leave default empty.
			return '';
		}
	}//end cardTitle()

	/**
	 * A non-empty list as JSON, else null.
	 *
	 * @param array<int,mixed> $list The list.
	 *
	 * @return string|null
	 */
	private function jsonListOrNull(array $list): ?string {
		if ($list === []) {
			return null;
		}

		return json_encode($list);
	}//end jsonListOrNull()

	/**
	 * Unlink a Deck card from an object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $cardId Deck card id.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkCard(string $objectUuid, int $cardId): void {
		$deleted = $this->deckLinkMapper->deleteByObjectAndCard($objectUuid, $cardId);
		if ($deleted === 0) {
			throw new Exception('Deck link not found', 404);
		}
	}//end unlinkCard()

	/**
	 * Return the linked cards for an object, enriched from Deck where
	 * available.
	 *
	 * Always succeeds: when Deck's `CardService` is missing or `find()`
	 * throws for a stale link, the stored row is returned with empty
	 * `labels`/`assignees` and `dueDate` falling back to the cached column.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedCards(string $objectUuid): array {
		$links = $this->deckLinkMapper->findByObjectUuid($objectUuid);
		$cardService = $this->resolveCardService();

		$results = [];
		foreach ($links as $link) {
			$results[] = $this->linkedCardRow(link: $link, cardService: $cardService);
		}

		return $results;
	}//end getLinkedCards()

	/**
	 * One link row, widened with the card's current fields when Deck can read it.
	 *
	 * @param DeckLink    $link        The link row.
	 * @param object|null $cardService Deck's CardService, or null when unavailable.
	 *
	 * @return array<string, mixed>
	 */
	private function linkedCardRow(DeckLink $link, ?object $cardService): array {
		$row = $link->jsonSerialize();
		if ($cardService === null) {
			return $row;
		}

		$widened = $this->currentCardFields(cardService: $cardService, link: $link);
		if ($widened === null) {
			return $row;
		}

		$row['dueDate'] = $widened['dueDate'] ?? $row['dueDate'];
		$row['labels'] = $widened['labels'];
		$row['assignees'] = $widened['assignees'];
		$row['boardTitle'] = $widened['boardTitle'];
		$row['stackTitle'] = $widened['stackTitle'];

		return $row;
	}//end linkedCardRow()

	/**
	 * The current fields of a linked card.
	 *
	 * @param object   $cardService Deck's CardService.
	 * @param DeckLink $link        The link row.
	 *
	 * @return array<string, mixed>|null Null when the card is gone or unreadable (a stale link keeps its cached row).
	 */
	private function currentCardFields(object $cardService, DeckLink $link): ?array {
		try {
			$card = $cardService->find($link->getCardId());
			if (is_object($card) === true) {
				return $this->extractCardFields(card: $card);
			}
		} catch (Throwable $e) {
			// Stale link — keep cached row as-is.
			$this->runtime->logger()->debug('Stale deck link for card ' . $link->getCardId() . ': ' . $e->getMessage());
		}

		return null;
	}//end currentCardFields()

	/**
	 * Create a new Deck card and link it to an object in one transaction.
	 *
	 * Uses Deck's CardService for the create (with the owner-arg fix
	 * from commit 3cdbfe71a) then persists the link row carrying the
	 * new card's id + cached title + due date.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param CardDraft  $card   Title, list (stack), board (used when Deck cannot
	 *                           resolve the card's board after create), and
	 *                           optional description and ISO 8601 due date.
	 *
	 * @return DeckLink The persisted link row.
	 *
	 * @throws Exception On missing user, Deck unavailable, or create failure.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkCard(LinkTarget $target, CardDraft $card): DeckLink {
		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$cardService = $this->resolveCardService();
		if ($cardService === null) {
			throw new Exception('Deck is not available', 503);
		}

		$run = new DeckCardRun(target: $target, card: $card);
		$run->cardService = $cardService;
		$run->ownerUid = $user->getUID();
		$cardId = (int)$this->writeCard(run: $run)->getId();

		return $this->deckLinkMapper->insert($this->newCardLink(run: $run, cardId: $cardId));
	}//end createAndLinkCard()

	/**
	 * Create the card in Deck and give it its description and due date.
	 *
	 * @param DeckCardRun $run The card creation.
	 *
	 * @return object The created Deck card.
	 *
	 * @throws Exception 500 when Deck fails to create or update the card (logged).
	 */
	private function writeCard(DeckCardRun $run): object {
		try {
			$created = $run->cardService->create($run->card->title, $run->card->stackId, 'plain', 0, $run->ownerUid);
			$this->describeCard(run: $run, created: $created);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('Failed to create Deck card: ' . $e->getMessage());
			throw new Exception('Failed to create Deck card: ' . $e->getMessage(), 500);
		}

		return $created;
	}//end writeCard()

	/**
	 * Set a created card's description (with the link back to the object) and due date.
	 *
	 * @param DeckCardRun $run     The card creation.
	 * @param object      $created The created Deck card.
	 *
	 * @return void
	 */
	private function describeCard(DeckCardRun $run, object $created): void {
		// Deck CardService::update signature:
		// update($id, $title, $stackId, $type, $owner, $description='', $order=0, $duedate=null, ...)
		// The owner argument must be a string user UID.
		$run->cardService->update(
			$created->getId(),
			$run->card->title,
			$run->card->stackId,
			'plain',
			$run->ownerUid,
			$run->card->descriptionFor(objectUuid: $run->target->objectUuid),
			0,
			$run->card->duedate()
		);
	}//end describeCard()

	/**
	 * The link row for a created card, stamped with the object and the user.
	 *
	 * @param DeckCardRun $run    The card creation.
	 * @param int         $cardId The created card's id.
	 *
	 * @return DeckLink The unsaved link.
	 */
	private function newCardLink(DeckCardRun $run, int $cardId): DeckLink {
		$resolvedBoard = $this->createdCardBoard(card: $run->card, cardId: $cardId);

		$link = new DeckLink();
		$link->setBoardId($resolvedBoard);
		$link->setStackId($run->card->stackId);
		$link->setCardId($cardId);
		$link->setCardTitle($run->card->title);
		$link->setDueDate($run->card->dueDateTime());
		$link->setLabels(null);
		$link->setAssignees(null);
		$run->target->stamp(link: $link, linkedBy: $run->ownerUid);

		return $link;
	}//end newCardLink()

	/**
	 * The board a created card sits on.
	 *
	 * Resolved authoritatively from the persisted card so we don't trust the
	 * (caller-supplied) value in case the stack was moved between boards; the
	 * caller's board is the fallback.
	 *
	 * @param CardDraft $card   The card as requested.
	 * @param int       $cardId The created card's id.
	 *
	 * @return int The board id.
	 */
	private function createdCardBoard(CardDraft $card, int $cardId): int {
		$resolvedBoard = $this->resolveBoardId(cardId: $cardId);
		if ($resolvedBoard === 0) {
			$resolvedBoard = $card->boardId();
		}

		return $resolvedBoard;
	}//end createdCardBoard()

	/**
	 * Return the Deck boards visible to the current user.
	 *
	 * Each row is `{id, title}` — the minimum the picker needs.
	 *
	 * @return array<int,array{id:int,title:string}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableBoards(): array {
		$boardService = $this->resolveBoardService();
		if ($boardService === null) {
			return [];
		}

		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			return [];
		}

		try {
			$boards = $boardService->findAll();
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Deck BoardService->findAll failed: ' . $e->getMessage());
			return [];
		}

		if (is_array($boards) === false) {
			return [];
		}

		return $this->idTitleRows(items: $boards);
	}//end getAvailableBoards()

	/**
	 * Return the stacks for a Deck board.
	 *
	 * @param int $boardId Deck board id.
	 *
	 * @return array<int,array{id:int,title:string,boardId:int}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getStacksForBoard(int $boardId): array {
		$stackService = $this->resolveStackService();
		if ($stackService === null) {
			return [];
		}

		$stacks = $this->boardStacks(stackService: $stackService, boardId: $boardId);
		if ($stacks === null) {
			return [];
		}

		return array_map(
			static fn (array $row): array => $row + ['boardId' => $boardId],
			$this->idTitleRows(items: $stacks)
		);
	}//end getStacksForBoard()

	/**
	 * The stacks of a board as Deck returns them.
	 *
	 * @param object $stackService Deck's StackService.
	 * @param int    $boardId      Deck board id.
	 *
	 * @return array|null Null when Deck fails to list them or returns no list.
	 */
	private function boardStacks(object $stackService, int $boardId): ?array {
		try {
			$stacks = $stackService->findAll($boardId);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Deck StackService->findAll(' . $boardId . ') failed: ' . $e->getMessage());
			return null;
		}

		if (is_array($stacks) === false) {
			return null;
		}

		return $stacks;
	}//end boardStacks()

	/**
	 * The id/title rows of Deck boards or stacks, skipping those whose id cannot be read or is 0.
	 *
	 * @param array<int,object> $items Deck Board or Stack entities.
	 *
	 * @return list<array{id:int,title:string}>
	 */
	private function idTitleRows(array $items): array {
		$result = [];
		foreach ($items as $item) {
			$row = self::idTitleRow(item: $item);
			if ($row !== null) {
				$result[] = $row;
			}
		}

		return $result;
	}//end idTitleRows()

	/**
	 * The id and title of one Deck entity; its getters may be missing on some Deck versions.
	 *
	 * @param mixed $item A Deck board or stack.
	 *
	 * @return array{id: int, title: string}|null Null when the id cannot be read or is 0.
	 */
	private static function idTitleRow(mixed $item): ?array {
		try {
			$id = (int)$item->getId();
		} catch (Throwable $e) {
			return null;
		}

		try {
			$title = (string)$item->getTitle();
		} catch (Throwable $e) {
			$title = '';
		}

		if ($id === 0) {
			return null;
		}

		return ['id' => $id, 'title' => $title];
	}//end idTitleRow()

	/**
	 * Resolve Deck's CardService from the server container.
	 *
	 * @return object|null Returns null when Deck is unavailable.
	 */
	private function resolveCardService(): ?object {
		if (class_exists('OCA\\Deck\\Service\\CardService') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\Deck\\Service\\CardService');
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Deck CardService not resolvable: ' . $e->getMessage());
			return null;
		}
	}//end resolveCardService()

	/**
	 * Resolve Deck's BoardService.
	 *
	 * @return object|null Returns null when Deck is unavailable.
	 */
	private function resolveBoardService(): ?object {
		if (class_exists('OCA\\Deck\\Service\\BoardService') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\Deck\\Service\\BoardService');
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Deck BoardService not resolvable: ' . $e->getMessage());
			return null;
		}
	}//end resolveBoardService()

	/**
	 * Resolve Deck's StackService.
	 *
	 * @return object|null Returns null when Deck is unavailable.
	 */
	private function resolveStackService(): ?object {
		if (class_exists('OCA\\Deck\\Service\\StackService') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\Deck\\Service\\StackService');
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Deck StackService not resolvable: ' . $e->getMessage());
			return null;
		}
	}//end resolveStackService()

	/**
	 * Resolve a card's board id via Deck's `CardMapper::findBoardId()`.
	 *
	 * Returns `0` when the lookup fails or the mapper is unavailable.
	 *
	 * @param int $cardId Deck card id.
	 *
	 * @return int
	 */
	private function resolveBoardId(int $cardId): int {
		if (class_exists('OCA\\Deck\\Db\\CardMapper') === false) {
			return 0;
		}

		return $this->boardIdFromCardMapper(cardId: $cardId);
	}//end resolveBoardId()

	/**
	 * The board of a card, read through Deck's CardMapper.
	 *
	 * @param int $cardId Deck card id.
	 *
	 * @return int The board id, 0 when unknown or unreadable.
	 */
	private function boardIdFromCardMapper(int $cardId): int {
		try {
			$cardMapper = $this->container->get('OCA\\Deck\\Db\\CardMapper');
			$boardId = $cardMapper->findBoardId($cardId);
			if ($boardId !== null) {
				return (int)$boardId;
			}

			return 0;
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Deck CardMapper::findBoardId(' . $cardId . ') failed: ' . $e->getMessage());
			return 0;
		}
	}//end boardIdFromCardMapper()

	/**
	 * Extract `dueDate`/`labels`/`assignees` from a Deck card.
	 *
	 * Returns:
	 *   - `dueDate`     — ISO 8601 string or null
	 *   - `dueDateRaw`  — DateTime|null (for persistence)
	 *   - `labels`      — list of {id,title,color}
	 *   - `assignees`   — list of {uid,type,displayName}
	 *
	 * Each call is defensive: missing entity methods are swallowed.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return array{dueDate:?string,dueDateRaw:?DateTime,labels:array,assignees:array,boardTitle:string,stackTitle:string}
	 */
	private function extractCardFields(object $card): array {
		[$dueDate, $dueDateRaw] = $this->cardDueDates(card: $card);

		return [
			'dueDate' => $dueDate,
			'dueDateRaw' => $dueDateRaw,
			'labels' => $this->cardLabels(card: $card),
			'assignees' => $this->cardAssignees(card: $card),
			'boardTitle' => $this->relatedTitle(card: $card, getter: 'getRelatedBoard'),
			'stackTitle' => $this->relatedTitle(card: $card, getter: 'getRelatedStack'),
		];
	}//end extractCardFields()

	/**
	 * The due date of a card as an ATOM string and as a DateTime; nulls when it has none.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return array{0:?string,1:?DateTime}
	 */
	private function cardDueDates(object $card): array {
		try {
			$due = $card->getDuedate();
			if ($due instanceof \DateTimeInterface) {
				$dueDate = $due->format(DateTime::ATOM);
				return [$dueDate, new DateTime($dueDate)];
			}
		} catch (Throwable $e) {
			// Leave default null.
			$this->runtime->logger()->debug('[DeckLinkService] Card due date not readable: ' . $e->getMessage());
		}

		return [null, null];
	}//end cardDueDates()

	/**
	 * The mapped labels of a card; none when they cannot be read.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cardLabels(object $card): array {
		$labels = [];
		try {
			$rawLabels = $card->getLabels();
			if (is_array($rawLabels) === true) {
				foreach ($rawLabels as $label) {
					$labels[] = $this->mapLabel(label: $label);
				}
			}
		} catch (Throwable $e) {
			// Leave default empty.
			$this->runtime->logger()->debug('[DeckLinkService] Card labels not readable: ' . $e->getMessage());
		}

		return $labels;
	}//end cardLabels()

	/**
	 * The mapped assignees of a card; none when they cannot be read.
	 *
	 * @param object $card Deck Card entity.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cardAssignees(object $card): array {
		$assignees = [];
		try {
			$rawAssignees = $card->getAssignedUsers();
			if (is_array($rawAssignees) === true) {
				foreach ($rawAssignees as $assignment) {
					$assignees[] = $this->mapAssignee(assignment: $assignment);
				}
			}
		} catch (Throwable $e) {
			// Leave default empty.
			$this->runtime->logger()->debug('[DeckLinkService] Card assignees not readable: ' . $e->getMessage());
		}

		return $assignees;
	}//end cardAssignees()

	/**
	 * The title of the board or list Deck's card lookup put on the card, or ''.
	 *
	 * @param object $card   The card as Deck's card service returned it.
	 * @param string $getter `getRelatedBoard` or `getRelatedStack`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/deck-integration/spec.md
	 */
	private function relatedTitle(object $card, string $getter): string {
		try {
			$related = $card->{$getter}();
			if (is_object($related) === true) {
				return (string)$related->getTitle();
			}
		} catch (Throwable $e) {
			// Leave the title empty.
			$this->runtime->logger()->debug('[DeckLinkService] Card relation title not readable via ' . $getter . ': ' . $e->getMessage());
		}

		return '';
	}//end relatedTitle()

	/**
	 * Map a Deck Label entity to the leaf-row shape.
	 *
	 * @param object $label Deck Label.
	 *
	 * @return array{id:?int,title:string,color:string}
	 */
	private function mapLabel(object $label): array {
		return (new DeckRowMapper(userManager: $this->userManager))->label(label: $label);
	}//end mapLabel()

	/**
	 * Map a Deck Assignment entity to the leaf-row shape.
	 *
	 * @param object $assignment Deck Assignment.
	 *
	 * @return array{uid:string,type:string,displayName:string}
	 */
	private function mapAssignee(object $assignment): array {
		return (new DeckRowMapper(userManager: $this->userManager))->assignee(assignment: $assignment);
	}//end mapAssignee()
}//end class
