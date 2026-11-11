<?php

/**
 * OpenRegister Example Set Deck Store
 *
 * The Deck rows an example set writes, read and written straight in Deck's
 * tables. Deck's own services act for the signed-in user and check that
 * user's board rights; an occ install has no user, so the set writes as the
 * fragment's app, as it does for every other part.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Finds, writes and deletes Deck boards, lists, labels and cards.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */
class ExampleSetDeckStore {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db   The database Deck's tables live in.
	 * @param IAppManager   $apps Tells whether Deck is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppManager $apps,
	) {
	}//end __construct()

	/**
	 * Whether Deck is installed and enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function available(): bool {
		return $this->apps->isInstalled('deck');
	}//end available()

	/**
	 * The live board of this owner with this title, written when absent and
	 * then shared for editing with these groups.
	 *
	 * @param DeckBoardRequest $board The owner, the title, and the colour and editing groups of a board written now.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function board(DeckBoardRequest $board): array {
		$titled = new DeckTitled(parent: $board->owner, title: $board->title);
		$found  = $this->liveId(rows: new DeckColumn(table: 'deck_boards', column: 'owner'), row: $titled);
		if ($found !== null) {
			return ['id' => $found, 'created' => false];
		}

		$id = $this->insertBoard(board: $titled, color: $board->color);
		foreach ($board->groups as $group) {
			$this->shareBoard(board: $id, group: $group);
		}

		return ['id' => $id, 'created' => true];
	}//end board()

	/**
	 * Write a board and return its id.
	 *
	 * @param DeckTitled $board The board's owner and title.
	 * @param string     $color The board's colour, hex without '#'.
	 *
	 * @return int
	 */
	private function insertBoard(DeckTitled $board, string $color): int {
		return $this->insert(
			table: 'deck_boards',
			values: ['title' => $board->title, 'owner' => $board->parent, 'color' => $color, 'archived' => false, 'deleted_at' => 0, 'last_modified' => time()]
		);
	}//end insertBoard()

	/**
	 * Share a board for editing with a group.
	 *
	 * @param int    $board The board id.
	 * @param string $group The group.
	 *
	 * @return void
	 */
	private function shareBoard(int $board, string $group): void {
		$this->insert(
			table: 'deck_board_acl',
			values: [
				'board_id'          => $board,
				'type'              => 1,
				'participant'       => $group,
				'permission_edit'   => true,
				'permission_share'  => false,
				'permission_manage' => false,
			]
		);
	}//end shareBoard()

	/**
	 * The board's live list with this title, written at the end of the board when absent.
	 *
	 * @param int    $board The board id.
	 * @param string $title The list title.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function stack(int $board, string $title): array {
		$found = $this->liveId(rows: new DeckColumn(table: 'deck_stacks', column: 'board_id'), row: new DeckTitled(parent: $board, title: $title));
		if ($found !== null) {
			return ['id' => $found, 'created' => false];
		}

		$order = $this->count(rows: new DeckColumn(table: 'deck_stacks', column: 'board_id'), id: $board);
		$id    = $this->insert(
			table: 'deck_stacks',
			values: ['title' => $title, 'board_id' => $board, 'order' => $order, 'deleted_at' => 0, 'last_modified' => time()]
		);

		return ['id' => $id, 'created' => true];
	}//end stack()

	/**
	 * The id of the first row of a table, not deleted, that points at a value and has this title; null when none does.
	 *
	 * @param DeckColumn $rows The table and the column holding the value.
	 * @param DeckTitled $row  The value the row points at, and its title.
	 *
	 * @return int|null
	 */
	private function liveId(DeckColumn $rows, DeckTitled $row): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($rows->table)
			->where($qb->expr()->eq($rows->column, $qb->createNamedParameter($row->parent, $this->type(value: $row->parent))))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($row->title)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		return $this->firstId(qb: $qb);
	}//end liveId()

	/**
	 * The board's label with this title, written when absent.
	 *
	 * @param int    $board The board id.
	 * @param string $title The label title.
	 * @param string $color The colour of a label written now, hex without '#'.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function label(int $board, string $title, string $color): array {
		$found = $this->labelId(label: new DeckTitled(parent: $board, title: $title));
		if ($found !== null) {
			return ['id' => $found, 'created' => false];
		}

		$id = $this->insert(table: 'deck_labels', values: ['title' => $title, 'color' => $color, 'board_id' => $board, 'last_modified' => time()]);

		return ['id' => $id, 'created' => true];
	}//end label()

	/**
	 * The id of the board's label with this title, or null when it has none.
	 *
	 * @param DeckTitled $label The board id and the label title.
	 *
	 * @return int|null
	 */
	private function labelId(DeckTitled $label): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('deck_labels')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($label->parent, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($label->title)))
			->setMaxResults(1);

		return $this->firstId(qb: $qb);
	}//end labelId()

	/**
	 * Write a card with its labels and assignees, at the end of its list.
	 *
	 * @param int                  $stack The list id.
	 * @param array<string, mixed> $card  The card: owner (string), title (string), description (string), due and
	 *                                    done (?DateTime), labels (label ids) and assignees (user ids).
	 *
	 * @phpstan-param array{
	 *     owner: string, title: string, description: string, due: ?DateTime, done: ?DateTime,
	 *     labels: array<int, int>, assignees: array<int, string>
	 * } $card
	 *
	 * @return int The card id.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function insertCard(int $stack, array $card): int {
		$id = $this->insert(table: 'deck_cards', values: $this->cardValues(stack: $stack, card: $card));
		foreach ($card['labels'] as $label) {
			$this->insert(table: 'deck_assigned_labels', values: ['label_id' => $label, 'card_id' => $id]);
		}

		foreach ($card['assignees'] as $uid) {
			$this->insert(table: 'deck_assigned_users', values: ['participant' => $uid, 'card_id' => $id, 'type' => 0]);
		}

		return $id;
	}//end insertCard()

	/**
	 * The column values of a new card at the end of its list.
	 *
	 * @param int                  $stack The list id.
	 * @param array<string, mixed> $card  The card, as insertCard() takes it.
	 *
	 * @return array<string, mixed>
	 */
	private function cardValues(int $stack, array $card): array {
		$order = $this->count(rows: new DeckColumn(table: 'deck_cards', column: 'stack_id'), id: $stack);
		return [
			'title'       => $card['title'],
			'description' => $card['description'],
			'stack_id'    => $stack,
			'type'        => 'plain',
			'owner'       => $card['owner'],
			'order'       => $order,
			...$this->cardState(card: $card),
		];
	}//end cardValues()

	/**
	 * The state columns of a new card: not archived, its dates, not notified, not deleted, written now.
	 *
	 * @param array<string, mixed> $card The card, as insertCard() takes it.
	 *
	 * @return array<string, mixed>
	 */
	private function cardState(array $card): array {
		return [
			'archived'      => false,
			'duedate'       => $card['due'],
			'done'          => $card['done'],
			'notified'      => false,
			'deleted_at'    => 0,
			'created_at'    => time(),
			'last_modified' => time(),
		];
	}//end cardState()

	/**
	 * Whether the card is still there.
	 *
	 * @param int $card The card id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function cardExists(int $card): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('deck_cards')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($card, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->firstId(qb: $qb) !== null;
	}//end cardExists()

	/**
	 * Delete a card with its label and user assignments.
	 *
	 * @param int $card The card id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function deleteCard(int $card): void {
		$this->deleteWhere(rows: new DeckColumn(table: 'deck_assigned_labels', column: 'card_id'), id: $card);
		$this->deleteWhere(rows: new DeckColumn(table: 'deck_assigned_users', column: 'card_id'), id: $card);
		$this->deleteWhere(rows: new DeckColumn(table: 'deck_cards', column: 'id'), id: $card);
	}//end deleteCard()

	/**
	 * Delete a label with its assignments.
	 *
	 * @param int $label The label id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function deleteLabel(int $label): void {
		$this->deleteWhere(rows: new DeckColumn(table: 'deck_assigned_labels', column: 'label_id'), id: $label);
		$this->deleteWhere(rows: new DeckColumn(table: 'deck_labels', column: 'id'), id: $label);
	}//end deleteLabel()

	/**
	 * Delete a list the set wrote when it holds no cards, then a board the set
	 * wrote when it holds no lists, with its labels and shares. What someone
	 * else added to stays, and keeps its list and board.
	 *
	 * @param int|null $stack The list the set wrote, or null.
	 * @param int|null $board The board the set wrote, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function deleteUnused(?int $stack, ?int $board): void {
		if ($stack !== null) {
			$this->deleteEmptyStack(stack: $stack);
		}

		if ($board !== null) {
			$this->deleteEmptyBoard(board: $board);
		}
	}//end deleteUnused()

	/**
	 * Delete a list when it holds no cards.
	 *
	 * @param int $stack The list id.
	 *
	 * @return void
	 */
	private function deleteEmptyStack(int $stack): void {
		if ($this->count(rows: new DeckColumn(table: 'deck_cards', column: 'stack_id'), id: $stack) === 0) {
			$this->deleteWhere(rows: new DeckColumn(table: 'deck_stacks', column: 'id'), id: $stack);
		}
	}//end deleteEmptyStack()

	/**
	 * Delete a board, with its labels and shares, when it holds no lists.
	 *
	 * @param int $board The board id.
	 *
	 * @return void
	 */
	private function deleteEmptyBoard(int $board): void {
		if ($this->count(rows: new DeckColumn(table: 'deck_stacks', column: 'board_id'), id: $board) === 0) {
			$this->deleteWhere(rows: new DeckColumn(table: 'deck_labels', column: 'board_id'), id: $board);
			$this->deleteWhere(rows: new DeckColumn(table: 'deck_board_acl', column: 'board_id'), id: $board);
			$this->deleteWhere(rows: new DeckColumn(table: 'deck_boards', column: 'id'), id: $board);
		}
	}//end deleteEmptyBoard()

	/**
	 * Insert a row and return its id.
	 *
	 * @param string               $table  The table, without prefix.
	 * @param array<string, mixed> $values The column values.
	 *
	 * @return int
	 */
	private function insert(string $table, array $values): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter($value, $this->type(value: $value)));
		}

		$qb->executeStatement();

		return $qb->getLastInsertId();
	}//end insert()

	/**
	 * The parameter type of a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|string
	 */
	private function type(mixed $value): int|string {
		return match (true) {
			is_bool($value) => IQueryBuilder::PARAM_BOOL,
			is_int($value) => IQueryBuilder::PARAM_INT,
			$value instanceof DateTime => IQueryBuilder::PARAM_DATE,
			$value === null => IQueryBuilder::PARAM_NULL,
			default => IQueryBuilder::PARAM_STR,
		};
	}//end type()

	/**
	 * How many rows of a table point at an id.
	 *
	 * @param DeckColumn $rows The table and the column holding the id.
	 * @param int        $id   The id.
	 *
	 * @return int
	 */
	private function count(DeckColumn $rows, int $id): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($rows->table)
			->where($qb->expr()->eq($rows->column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($rows->table !== 'deck_assigned_labels' && $rows->table !== 'deck_labels') {
			$qb->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$count  = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end count()

	/**
	 * Delete the rows of a table that point at an id.
	 *
	 * @param DeckColumn $rows The table and the column holding the id.
	 * @param int        $id   The id.
	 *
	 * @return void
	 */
	private function deleteWhere(DeckColumn $rows, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($rows->table)->where($qb->expr()->eq($rows->column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end deleteWhere()

	/**
	 * The first row's id of a select, or null.
	 *
	 * @param IQueryBuilder $qb The select.
	 *
	 * @return int|null
	 */
	private function firstId(IQueryBuilder $qb): ?int {
		$result = $qb->executeQuery();
		$id     = $result->fetchOne();
		$result->closeCursor();

		if ($id === false) {
			return null;
		}

		return (int) $id;
	}//end firstId()
}//end class
