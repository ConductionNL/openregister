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
	 * @param string             $owner  The board owner.
	 * @param string             $title  The board title.
	 * @param string             $color  The colour of a board written now, hex without '#'.
	 * @param array<int, string> $groups The groups that may edit a board written now.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function board(string $owner, string $title, string $color, array $groups): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('deck_boards')
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$found = $this->firstId(qb: $qb);
		if ($found !== null) {
			return ['id' => $found, 'created' => false];
		}

		$id = $this->insert(
			table: 'deck_boards',
			values: ['title' => $title, 'owner' => $owner, 'color' => $color, 'archived' => false, 'deleted_at' => 0, 'last_modified' => time()]
		);
		foreach ($groups as $group) {
			$this->insert(
				table: 'deck_board_acl',
				values: [
					'board_id'          => $id,
					'type'              => 1,
					'participant'       => $group,
					'permission_edit'   => true,
					'permission_share'  => false,
					'permission_manage' => false,
				]
			);
		}

		return ['id' => $id, 'created' => true];
	}//end board()

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
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('deck_stacks')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($board, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$found = $this->firstId(qb: $qb);
		if ($found !== null) {
			return ['id' => $found, 'created' => false];
		}

		$order = $this->count(table: 'deck_stacks', column: 'board_id', id: $board);
		$id    = $this->insert(
			table: 'deck_stacks',
			values: ['title' => $title, 'board_id' => $board, 'order' => $order, 'deleted_at' => 0, 'last_modified' => time()]
		);

		return ['id' => $id, 'created' => true];
	}//end stack()

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
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('deck_labels')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($board, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)))
			->setMaxResults(1);
		$found = $this->firstId(qb: $qb);
		if ($found !== null) {
			return ['id' => $found, 'created' => false];
		}

		$id = $this->insert(table: 'deck_labels', values: ['title' => $title, 'color' => $color, 'board_id' => $board, 'last_modified' => time()]);

		return ['id' => $id, 'created' => true];
	}//end label()

	/**
	 * Write a card with its labels and assignees.
	 *
	 * @param int                $stack       The list id.
	 * @param string             $owner       The card owner.
	 * @param string             $title       The card title.
	 * @param string             $description The card description.
	 * @param DateTime|null      $due         The due date.
	 * @param DateTime|null      $done        When the card was marked done.
	 * @param array<int, int>    $labels      The label ids.
	 * @param array<int, string> $assignees   The assigned user ids.
	 *
	 * @return int The card id.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function insertCard(
		int $stack,
		string $owner,
		string $title,
		string $description,
		?DateTime $due,
		?DateTime $done,
		array $labels,
		array $assignees
	): int {
		$card = $this->insert(
			table: 'deck_cards',
			values: [
				'title'         => $title,
				'description'   => $description,
				'stack_id'      => $stack,
				'type'          => 'plain',
				'owner'         => $owner,
				'order'         => $this->count(table: 'deck_cards', column: 'stack_id', id: $stack),
				'archived'      => false,
				'duedate'       => $due,
				'done'          => $done,
				'notified'      => false,
				'deleted_at'    => 0,
				'created_at'    => time(),
				'last_modified' => time(),
			]
		);
		foreach ($labels as $label) {
			$this->insert(table: 'deck_assigned_labels', values: ['label_id' => $label, 'card_id' => $card]);
		}

		foreach ($assignees as $uid) {
			$this->insert(table: 'deck_assigned_users', values: ['participant' => $uid, 'card_id' => $card, 'type' => 0]);
		}

		return $card;
	}//end insertCard()

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
		$this->deleteWhere(table: 'deck_assigned_labels', column: 'card_id', id: $card);
		$this->deleteWhere(table: 'deck_assigned_users', column: 'card_id', id: $card);
		$this->deleteWhere(table: 'deck_cards', column: 'id', id: $card);
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
		$this->deleteWhere(table: 'deck_assigned_labels', column: 'label_id', id: $label);
		$this->deleteWhere(table: 'deck_labels', column: 'id', id: $label);
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
		if ($stack !== null && $this->count(table: 'deck_cards', column: 'stack_id', id: $stack) === 0) {
			$this->deleteWhere(table: 'deck_stacks', column: 'id', id: $stack);
		}

		if ($board !== null && $this->count(table: 'deck_stacks', column: 'board_id', id: $board) === 0) {
			$this->deleteWhere(table: 'deck_labels', column: 'board_id', id: $board);
			$this->deleteWhere(table: 'deck_board_acl', column: 'board_id', id: $board);
			$this->deleteWhere(table: 'deck_boards', column: 'id', id: $board);
		}
	}//end deleteUnused()

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
	 * @param string $table  The table, without prefix.
	 * @param string $column The column holding the id.
	 * @param int    $id     The id.
	 *
	 * @return int
	 */
	private function count(string $table, string $column, int $id): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($table !== 'deck_assigned_labels' && $table !== 'deck_labels') {
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
	 * @param string $table  The table, without prefix.
	 * @param string $column The column holding the id.
	 * @param int    $id     The id.
	 *
	 * @return void
	 */
	private function deleteWhere(string $table, string $column, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
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
