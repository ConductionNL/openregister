<?php

/**
 * Golden test for the writes of ExampleSetDeckStore.
 *
 * Pins every query board(), stack(), label() and insertCard() build and run,
 * in order, ahead of splitting them into smaller units.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckStore;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\ExampleSet\DeckBoardRequest;

/**
 * Golden pins for the Deck rows an example set removes.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckStore
 */
class ExampleSetDeckStoreWritesGoldenTest extends TestCase {

	/**
	 * Every builder call, in order, one string per call.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * What each select answers, in order: an id, a count, or false for no row.
	 *
	 * @var array<int, int|false>
	 */
	private array $answers = [];

	/**
	 * The id the next insert gets.
	 *
	 * @var int
	 */
	private int $nextId = 100;

	/**
	 * A store over a recording query builder.
	 *
	 * @return ExampleSetDeckStore
	 */
	private function store(): ExampleSetDeckStore {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

		return new ExampleSetDeckStore(db: $db, apps: $this->createMock(IAppManager::class));
	}//end store()

	/**
	 * One recording query builder.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(): IQueryBuilder {
		$this->log[] = 'new';
		$qb   = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($column, $value): string => $column.' = '.$value);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(function ($what, $alias): IQueryFunction {
			$this->log[] = 'count('.$what.', '.$alias.')';
			return $this->createMock(IQueryFunction::class);
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			fn ($value, $type = IQueryBuilder::PARAM_STR): string => (($value instanceof DateTime) ? $value->format('Y-m-d') : var_export($value, true)).'/'.var_export($type, true)
		);
		foreach (['insert', 'select', 'from', 'where', 'andWhere', 'setValue', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method): IQueryBuilder {
				$this->log[] = $method.'('.implode(', ', array_map(static fn ($arg): string => ($arg instanceof IQueryFunction ? 'count-function' : (string) $arg), array_filter($args, static fn ($arg): bool => $arg !== null))).')';
				return $qb;
			});
		}

		$qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->log[] = 'executeStatement';
			return 1;
		});
		$qb->method('getLastInsertId')->willReturnCallback(fn (): int => $this->nextId++);
		$qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$this->log[] = 'executeQuery';
			$result = $this->createMock(IResult::class);
			$answer = array_shift($this->answers);
			$result->method('fetchOne')->willReturn(($answer === false) ? false : (string) $answer);
			return $result;
		});

		return $qb;
	}//end builder()

	/**
	 * The log without the time columns, which follow the clock.
	 *
	 * @return array<int, string>
	 */
	private function logWithoutTimes(): array {
		return array_values(
			array_filter(
				$this->log,
				static fn (string $line): bool => preg_match('/^setValue\((last_modified|created_at),/', $line) !== 1
			)
		);
	}//end logWithoutTimes()

	/**
	 * A found board is used as it is; a missing one is written and shared with each group.
	 *
	 * @return void
	 */
	public function testBoard(): void {
		$store         = $this->store();
		$this->answers = [7, false];
		$this->assertSame(['id' => 7, 'created' => false], $store->board(board: new DeckBoardRequest(owner: 'alice', title: 'Work', color: 'abcdef', groups: ['g1'])));
		$this->assertSame(['id' => 100, 'created' => true], $store->board(board: new DeckBoardRequest(owner: 'alice', title: 'New', color: 'abcdef', groups: ['g1', 'g2'])));

		$find = static fn (string $title): array => [
			'new', 'select(id)', 'from(deck_boards)', "where(owner = 'alice'/2)", "andWhere(title = '".$title."'/2)",
			'andWhere(deleted_at = 0/1)', 'setMaxResults(1)', 'executeQuery',
		];
		$acl  = static fn (string $group): array => [
			'new', 'insert(deck_board_acl)', 'setValue(board_id, 100/1)', 'setValue(type, 1/1)', "setValue(participant, '".$group."'/2)",
			'setValue(permission_edit, true/\'boolean\')', 'setValue(permission_share, false/\'boolean\')', 'setValue(permission_manage, false/\'boolean\')', 'executeStatement',
		];
		$this->assertSame(
			[
				...$find('Work'),
				...$find('New'),
				'new', 'insert(deck_boards)', "setValue(title, 'New'/2)", "setValue(owner, 'alice'/2)", "setValue(color, 'abcdef'/2)",
				'setValue(archived, false/\'boolean\')', 'setValue(deleted_at, 0/1)', 'executeStatement',
				...$acl('g1'),
				...$acl('g2'),
			],
			$this->logWithoutTimes()
		);
	}//end testBoard()

	/**
	 * A found list is used as it is; a missing one is written at the end of the board.
	 *
	 * @return void
	 */
	public function testStack(): void {
		$store         = $this->store();
		$this->answers = [8, false, 3];
		$this->assertSame(['id' => 8, 'created' => false], $store->stack(board: 2, title: 'Todo'));
		$this->assertSame(['id' => 100, 'created' => true], $store->stack(board: 2, title: 'Done'));

		$find = static fn (string $title): array => [
			'new', 'select(id)', 'from(deck_stacks)', 'where(board_id = 2/1)', "andWhere(title = '".$title."'/2)",
			'andWhere(deleted_at = 0/1)', 'setMaxResults(1)', 'executeQuery',
		];
		$this->assertSame(
			[
				...$find('Todo'),
				...$find('Done'),
				'new', 'count(*, n)', 'select(count-function)', 'from(deck_stacks)', 'where(board_id = 2/1)',
				'andWhere(deleted_at = 0/1)', 'executeQuery',
				'new', 'insert(deck_stacks)', "setValue(title, 'Done'/2)", 'setValue(board_id, 2/1)', 'setValue(order, 3/1)',
				'setValue(deleted_at, 0/1)', 'executeStatement',
			],
			$this->logWithoutTimes()
		);
	}//end testStack()

	/**
	 * A found label is used as it is; a missing one is written.
	 *
	 * @return void
	 */
	public function testLabel(): void {
		$store         = $this->store();
		$this->answers = [9, false];
		$this->assertSame(['id' => 9, 'created' => false], $store->label(board: 2, title: 'Urgent', color: 'ff0000'));
		$this->assertSame(['id' => 100, 'created' => true], $store->label(board: 2, title: 'Later', color: '00ff00'));

		$find = static fn (string $title): array => [
			'new', 'select(id)', 'from(deck_labels)', 'where(board_id = 2/1)', "andWhere(title = '".$title."'/2)",
			'setMaxResults(1)', 'executeQuery',
		];
		$this->assertSame(
			[
				...$find('Urgent'),
				...$find('Later'),
				'new', 'insert(deck_labels)', "setValue(title, 'Later'/2)", "setValue(color, '00ff00'/2)", 'setValue(board_id, 2/1)',
				'executeStatement',
			],
			$this->logWithoutTimes()
		);
	}//end testLabel()

	/**
	 * A card is written at the end of its list, then its labels and assignees.
	 *
	 * @return void
	 */
	public function testInsertCard(): void {
		$store         = $this->store();
		$this->answers = [4];
		$card          = $this->insertCard(store: $store);
		$this->assertSame(100, $card);

		$this->assertSame(
			[
				'new', 'count(*, n)', 'select(count-function)', 'from(deck_cards)', 'where(stack_id = 3/1)',
				'andWhere(deleted_at = 0/1)', 'executeQuery',
				'new', 'insert(deck_cards)', "setValue(title, 'Call back'/2)", "setValue(description, 'About the permit'/2)",
				'setValue(stack_id, 3/1)', "setValue(type, 'plain'/2)", "setValue(owner, 'alice'/2)", 'setValue(order, 4/1)',
				'setValue(archived, false/\'boolean\')', 'setValue(duedate, 2026-03-01/'.var_export(IQueryBuilder::PARAM_DATE, true).')',
				'setValue(done, NULL/0)', 'setValue(notified, false/\'boolean\')', 'setValue(deleted_at, 0/1)', 'executeStatement',
				'new', 'insert(deck_assigned_labels)', 'setValue(label_id, 5/1)', 'setValue(card_id, 100/1)', 'executeStatement',
				'new', 'insert(deck_assigned_labels)', 'setValue(label_id, 6/1)', 'setValue(card_id, 100/1)', 'executeStatement',
				'new', 'insert(deck_assigned_users)', "setValue(participant, 'bob'/2)", 'setValue(card_id, 100/1)', 'setValue(type, 0/1)',
				'executeStatement',
			],
			$this->logWithoutTimes()
		);
	}//end testInsertCard()

	/**
	 * Write the card of testInsertCard().
	 *
	 * @param ExampleSetDeckStore $store The store.
	 *
	 * @return int The card id.
	 */
	private function insertCard(ExampleSetDeckStore $store): int {
		return $store->insertCard(
			stack: 3,
			card: [
				'owner'       => 'alice',
				'title'       => 'Call back',
				'description' => 'About the permit',
				'due'         => new DateTime('2026-03-01'),
				'done'        => null,
				'labels'      => [5, 6],
				'assignees'   => ['bob'],
			]
		);
	}//end insertCard()
}//end class
