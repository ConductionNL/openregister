<?php

/**
 * DeckObjectSourceProvider — serves the `nc-card` virtual schema's objects live
 * from the acting user's Nextcloud Deck boards (read-only).
 *
 * The authoritative record is the Deck card held by the Deck app; this provider
 * projects each card the acting user can read as a virtual ObjectEntity
 * (uuid = card id; object = {id, title, description, stackId, boardId, duedate})
 * and never writes back. Deck's own `BoardService`/`StackService` run in the
 * acting user's context and only return boards/cards the user may read, so the
 * projection is inherently user-scoped (denied == absent, no enumeration oracle)
 * — mirroring the scoping approach of the sibling object-source providers.
 *
 * Deck lives in ANOTHER app's namespace, so all access is gated behind
 * `class_exists` + a guarded container lookup (the defensive style of
 * {@see \OCA\OpenRegister\Service\DeckCardService} and the CalDAV provider): when
 * Deck is absent or its service classes cannot be resolved, the projection
 * degrades to an empty list with a logged warning rather than erroring.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud Deck app.
 */
class DeckObjectSourceProvider implements ObjectSourceProvider {

	use CountsThroughFindAll;
	use ReadsRowsById;
	use InstalledAppCheck;
	use ContainerServiceLookup;
	use DefensiveGetters;

	/**
	 * NC app id whose install-state gates this provider.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'deck';

	/**
	 * Label the warnings of this provider carry.
	 *
	 * @var string
	 */
	private const SOURCE_LABEL = 'deck-source';

	/**
	 * Deck's per-user board service (resolved dynamically — Deck's namespace).
	 *
	 * @var string
	 */
	private const BOARD_SERVICE = 'OCA\\Deck\\Service\\BoardService';

	/**
	 * Deck's per-user stack service (resolved dynamically — Deck's namespace).
	 *
	 * @var string
	 */
	private const STACK_SERVICE = 'OCA\\Deck\\Service\\StackService';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App availability checks.
	 * @param IUserSession $userSession The acting-user session.
	 * @param ContainerInterface $container Server container (lazy Deck-service lookup).
	 * @param LoggerInterface $logger Logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function getId(): string {
		return 'deck-source';
	}//end getId()

	/**
	 * Read the Deck cards visible to the acting user, failing closed to an empty
	 * list.
	 *
	 * Walks the user's boards (Deck `BoardService::findAll()`) and, for each,
	 * its stacks-with-cards (Deck `StackService::findAll()`); every access is
	 * guarded so an absent Deck app or a per-card read failure degrades to an
	 * empty/partial list rather than erroring.
	 *
	 * @return array<int, array<string, mixed>> The normalised card arrays.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	protected function readRows(): array {
		if ($this->userSession->getUser() === null) {
			return [];
		}

		$boardService = $this->resolveService(class: self::BOARD_SERVICE);
		$stackService = $this->resolveService(class: self::STACK_SERVICE);
		if ($boardService === null || $stackService === null) {
			return [];
		}

		return $this->cardsOfBoards(boardService: $boardService, stackService: $stackService);
	}//end readRows()

	/**
	 * The cards on every board of the acting user.
	 *
	 * @param object $boardService Deck's BoardService.
	 * @param object $stackService Deck's StackService.
	 *
	 * @return array<int, array<string, mixed>> The normalised cards.
	 */
	private function cardsOfBoards(object $boardService, object $stackService): array {
		$cards = [];
		foreach ($this->listBoards(boardService: $boardService) as $board) {
			$cards = array_merge($cards, $this->boardCards(stackService: $stackService, board: $board));
		}

		return $cards;
	}//end cardsOfBoards()

	/**
	 * The acting user's boards, failing closed to an empty list.
	 *
	 * @param object $boardService Deck's BoardService.
	 *
	 * @return mixed The boards (whatever BoardService::findAll() returns), or [].
	 */
	private function listBoards(object $boardService): mixed {
		try {
			return $boardService->findAll();
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:deck-source] could not list boards: ' . $e->getMessage());
			return [];
		}
	}//end listBoards()

	/**
	 * The cards of every stack on one board; none when the board has no id
	 * or its stacks cannot be listed.
	 *
	 * @param object $stackService Deck's StackService.
	 * @param mixed  $board        A Deck board.
	 *
	 * @return array<int, array<string, mixed>> The normalised cards.
	 */
	private function boardCards(object $stackService, mixed $board): array {
		$boardId = $this->intGetter(entity: $board, getter: 'getId');
		if ($boardId === null) {
			return [];
		}

		$cards = [];
		foreach ($this->listStacks(stackService: $stackService, boardId: $boardId) as $stack) {
			$cards = array_merge($cards, $this->cardsFromStack(stack: $stack, boardId: $boardId));
		}

		return $cards;
	}//end boardCards()

	/**
	 * The stacks of one board, failing closed to an empty list.
	 *
	 * @param object $stackService Deck's StackService.
	 * @param int    $boardId      The board id.
	 *
	 * @return mixed The stacks (whatever StackService::findAll() returns), or [].
	 */
	private function listStacks(object $stackService, int $boardId): mixed {
		try {
			return $stackService->findAll($boardId);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:deck-source] could not list stacks for board ' . $boardId . ': ' . $e->getMessage());
			return [];
		}
	}//end listStacks()

	/**
	 * Normalise the cards of one stack, skipping non-object entries.
	 *
	 * @param object $stack   A Deck stack entity.
	 * @param int    $boardId The owning board id.
	 *
	 * @return array<int, array<string, mixed>> The normalised cards.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function cardsFromStack(object $stack, int $boardId): array {
		$result = [];
		foreach ($this->stackCards(stack: $stack) as $card) {
			if (is_object($card) === true) {
				$result[] = $this->cardRow(card: $card, boardId: $boardId);
			}
		}

		return $result;
	}//end cardsFromStack()

	/**
	 * The raw cards of a stack; none when getCards() is missing, throws or is not a list.
	 *
	 * @param object $stack A Deck stack entity.
	 *
	 * @return array<mixed> The raw cards.
	 */
	private function stackCards(object $stack): array {
		try {
			$stackCards = $stack->getCards();
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($stackCards) === false) {
			return [];
		}

		return $stackCards;
	}//end stackCards()

	/**
	 * Normalise one card.
	 *
	 * @param object $card    A Deck card entity.
	 * @param int    $boardId The owning board id.
	 *
	 * @return array<string, mixed> The card row.
	 */
	private function cardRow(object $card, int $boardId): array {
		return [
			'id' => (string)($this->intGetter(entity: $card, getter: 'getId') ?? ''),
			'title' => $this->stringGetter(entity: $card, getter: 'getTitle'),
			'description' => $this->stringGetter(entity: $card, getter: 'getDescription'),
			'stackId' => $this->intGetter(entity: $card, getter: 'getStackId'),
			'boardId' => $boardId,
			'duedate' => $this->dueDate(card: $card),
		];
	}//end cardRow()

	/**
	 * Read a Deck card's due date as an ISO-8601 string, or null.
	 *
	 * @param object $card The Deck card entity.
	 *
	 * @return string|null The ISO-8601 due date, or null.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function dueDate(object $card): ?string {
		try {
			$due = $card->getDuedate();
			if ($due instanceof \DateTimeInterface) {
				return $due->format(\DateTime::ATOM);
			}
		} catch (Throwable $e) {
			// Getter missing on this Deck version — leave null.
		}

		return null;
	}//end dueDate()
}//end class
