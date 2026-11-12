<?php

/**
 * Golden test for the deletes of ExampleSetDeckStore.
 *
 * Pins every query deleteCard(), deleteLabel() and deleteUnused() build and
 * run, in order, ahead of a parameter refactor of the private deleteWhere().
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckStore;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for the Deck rows an example set removes.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckStore
 */
class ExampleSetDeckStoreDeletesGoldenTest extends TestCase {

	/**
	 * Every builder call, in order, one string per call.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * What each count query answers, in order.
	 *
	 * @var array<int, int>
	 */
	private array $counts = [];

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
		$qb->method('createNamedParameter')->willReturnCallback(fn ($value, $type): string => var_export($value, true).'/'.var_export($type, true));
		foreach (['delete', 'select', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method): IQueryBuilder {
				$this->log[] = $method.'('.implode(', ', array_map(static fn ($arg): string => ($arg instanceof IQueryFunction ? 'count-function' : (string) $arg), array_filter($args, static fn ($arg): bool => $arg !== null))).')';
				return $qb;
			});
		}

		$qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->log[] = 'executeStatement';
			return 1;
		});
		$qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$this->log[] = 'executeQuery';
			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturn((string) array_shift($this->counts));
			return $result;
		});

		return $qb;
	}//end builder()

	/**
	 * A card and a label go with their assignments.
	 *
	 * @return void
	 */
	public function testDeleteCardAndLabel(): void {
		$store = $this->store();
		$store->deleteCard(card: 11);
		$store->deleteLabel(label: 5);

		$this->assertSame(
			[
				'new', 'delete(deck_assigned_labels)', 'where(card_id = 11/1)', 'executeStatement',
				'new', 'delete(deck_assigned_users)', 'where(card_id = 11/1)', 'executeStatement',
				'new', 'delete(deck_cards)', 'where(id = 11/1)', 'executeStatement',
				'new', 'delete(deck_assigned_labels)', 'where(label_id = 5/1)', 'executeStatement',
				'new', 'delete(deck_labels)', 'where(id = 5/1)', 'executeStatement',
			],
			$this->log
		);
	}//end testDeleteCardAndLabel()

	/**
	 * An empty list and an empty board go; nulls and a used list are left alone.
	 *
	 * @return void
	 */
	public function testDeleteUnused(): void {
		$store        = $this->store();
		$this->counts = [0, 0];
		$store->deleteUnused(stack: 3, board: 2);
		$store->deleteUnused(stack: null, board: null);
		$this->counts = [4, 1];
		$store->deleteUnused(stack: 3, board: 2);

		$this->assertSame(
			[
				'new', 'count(*, n)', 'select(count-function)', 'from(deck_cards)', 'where(stack_id = 3/1)',
				'andWhere(deleted_at = 0/1)', 'executeQuery',
				'new', 'delete(deck_stacks)', 'where(id = 3/1)', 'executeStatement',
				'new', 'count(*, n)', 'select(count-function)', 'from(deck_stacks)', 'where(board_id = 2/1)',
				'andWhere(deleted_at = 0/1)', 'executeQuery',
				'new', 'delete(deck_labels)', 'where(board_id = 2/1)', 'executeStatement',
				'new', 'delete(deck_board_acl)', 'where(board_id = 2/1)', 'executeStatement',
				'new', 'delete(deck_boards)', 'where(id = 2/1)', 'executeStatement',
				'new', 'count(*, n)', 'select(count-function)', 'from(deck_cards)', 'where(stack_id = 3/1)',
				'andWhere(deleted_at = 0/1)', 'executeQuery',
				'new', 'count(*, n)', 'select(count-function)', 'from(deck_stacks)', 'where(board_id = 2/1)',
				'andWhere(deleted_at = 0/1)', 'executeQuery',
			],
			$this->log
		);
	}//end testDeleteUnused()
}//end class
