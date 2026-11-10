<?php

/**
 * Behaviour tests for ExampleSetDeckStore: reuse-or-create of boards, stacks and
 * labels, the rows a card writes, and what a delete removes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

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
 * Behaviour of the Deck table store against a recording query builder.
 */
class ExampleSetDeckStoreBehaviourTest extends TestCase {

	/** @var list<array> Writes in order: ['insert', table, values] or ['delete', table, column, id]. */
	private array $writes = [];

	/** @var list<mixed> Answers fetchOne gives, in order. */
	private array $answers = [];

	/** @var list<array{string, mixed, mixed}> Every named parameter: [column-or-'', value, type]. */
	private array $params = [];

	private int $nextId = 100;

	private ?IAppManager $apps = null;

	private function store(): ExampleSetDeckStore {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		return new ExampleSetDeckStore($db, ($this->apps ?? $this->createMock(IAppManager::class)));
	}//end store()

	private function builder(): IQueryBuilder {
		$qb      = $this->createMock(IQueryBuilder::class);
		$current = null;
		$where   = [];
		foreach (['select', 'from', 'andWhere', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('where')->willReturnCallback(
			static function (mixed $clause) use ($qb, &$where): IQueryBuilder {
				$where[] = $clause;
				return $qb;
			}
		);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (mixed $column, mixed $param): string => $column.'='.$param);
		$qb->method('expr')->willReturn($expr);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type=IQueryBuilder::PARAM_STR) use (&$current): string {
				$this->params[] = [$value, $type];
				if (is_array($current) === true && $current[0] === 'insert') {
					return ':'.count($this->params);
				}

				return var_export($value, true);
			}
		);
		$qb->method('insert')->willReturnCallback(
			static function (string $table) use ($qb, &$current): IQueryBuilder {
				$current = ['insert', $table, []];
				return $qb;
			}
		);
		$qb->method('setValue')->willReturnCallback(
			function (string $column, mixed $param) use ($qb, &$current): IQueryBuilder {
				$current[2][$column] = $this->params[count($this->params) - 1][0];
				return $qb;
			}
		);
		$qb->method('delete')->willReturnCallback(
			static function (string $table) use ($qb, &$current): IQueryBuilder {
				$current = ['delete', $table];
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$current, &$where): int {
				$this->writes[] = ($current[0] === 'delete' ? [...$current, $where[0]] : $current);
				return 1;
			}
		);
		$qb->method('getLastInsertId')->willReturnCallback(fn (): int => $this->nextId++);
		$qb->method('executeQuery')->willReturnCallback(
			function (): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn(array_shift($this->answers) ?? false);
				return $result;
			}
		);
		return $qb;
	}//end builder()

	public function testAvailableFollowsWhetherDeckIsInstalled(): void {
		$this->apps = $this->createMock(IAppManager::class);
		$this->apps->method('isInstalled')->willReturnMap([['deck', true]]);

		$this->assertTrue($this->store()->available());
	}

	public function testAnExistingBoardIsReused(): void {
		$this->answers = ['7'];

		$this->assertSame(['id' => 7, 'created' => false], $this->store()->board(new DeckBoardRequest('alice', 'Zaken', 'ff0000', ['g1'])));
		$this->assertSame([], $this->writes);
	}

	public function testANewBoardIsCreatedAndSharedWithEachGroupForEditing(): void {
		$this->answers = [false];

		$result = $this->store()->board(new DeckBoardRequest('alice', 'Zaken', 'ff0000', ['g1', 'g2']));

		$this->assertSame(['id' => 100, 'created' => true], $result);
		$this->assertSame('deck_boards', $this->writes[0][1]);
		$this->assertSame(['title' => 'Zaken', 'owner' => 'alice', 'color' => 'ff0000', 'archived' => false, 'deleted_at' => 0], array_diff_key($this->writes[0][2], ['last_modified' => true]));
		$this->assertSame(
			['insert', 'deck_board_acl', ['board_id' => 100, 'type' => 1, 'participant' => 'g1', 'permission_edit' => true, 'permission_share' => false, 'permission_manage' => false]],
			$this->writes[1]
		);
		$this->assertSame('g2', $this->writes[2][2]['participant']);
		$this->assertCount(3, $this->writes);
	}

	public function testABoardWithoutGroupsIsNotShared(): void {
		$this->answers = [false];

		$this->store()->board(new DeckBoardRequest('alice', 'Zaken', 'ff0000', []));

		$this->assertCount(1, $this->writes);
	}

	public function testAnExistingStackIsReused(): void {
		$this->answers = ['8'];

		$this->assertSame(['id' => 8, 'created' => false], $this->store()->stack(1, 'Nieuw'));
	}

	public function testANewStackGoesAfterTheLiveStacks(): void {
		$this->answers = [false, '3'];

		$this->assertSame(['id' => 100, 'created' => true], $this->store()->stack(1, 'Nieuw'));
		$this->assertSame(['title' => 'Nieuw', 'board_id' => 1, 'order' => 3, 'deleted_at' => 0], array_diff_key($this->writes[0][2], ['last_modified' => true]));
	}

	public function testLabelIsReusedOrCreated(): void {
		$this->answers = ['5', false];
		$store         = $this->store();

		$this->assertSame(['id' => 5, 'created' => false], $store->label(1, 'Urgent', 'ff0000'));
		$this->assertSame(['id' => 100, 'created' => true], $store->label(1, 'Nieuw', '00ff00'));
		$this->assertSame(['title' => 'Nieuw', 'color' => '00ff00', 'board_id' => 1], array_diff_key($this->writes[0][2], ['last_modified' => true]));
	}

	public function testInsertCardWritesTheCardItsLabelsAndAssignees(): void {
		$this->answers = ['2'];
		$due           = new DateTime('2026-02-01T00:00:00+00:00');

		$id = $this->store()->insertCard(
			9,
			['title' => 'Bellen', 'description' => 'd', 'owner' => 'alice', 'due' => $due, 'done' => null, 'labels' => [31], 'assignees' => ['bob']]
		);

		$this->assertSame(100, $id);
		$card = $this->writes[0][2];
		$this->assertSame(['Bellen', 'd', 9, 'plain', 'alice', 2, false, $due, null, false, 0], [
			$card['title'], $card['description'], $card['stack_id'], $card['type'], $card['owner'], $card['order'],
			$card['archived'], $card['duedate'], $card['done'], $card['notified'], $card['deleted_at'],
		]);
		$this->assertSame(['insert', 'deck_assigned_labels', ['label_id' => 31, 'card_id' => 100]], $this->writes[1]);
		$this->assertSame(['insert', 'deck_assigned_users', ['participant' => 'bob', 'card_id' => 100, 'type' => 0]], $this->writes[2]);
		$bellen = array_values(array_filter($this->params, static fn (array $param): bool => $param[0] === 'Bellen'));
		$this->assertContains(IQueryBuilder::PARAM_DATE, array_column($this->params, 1));
		$this->assertContains(IQueryBuilder::PARAM_NULL, array_column($this->params, 1));
		$this->assertContains(IQueryBuilder::PARAM_BOOL, array_column($this->params, 1));
		$this->assertSame(IQueryBuilder::PARAM_STR, $bellen[0][1]);
	}

	public function testCardExistsFollowsTheLookup(): void {
		$this->answers = ['40', false];
		$store         = $this->store();

		$this->assertTrue($store->cardExists(40));
		$this->assertFalse($store->cardExists(41));
	}

	public function testDeleteCardRemovesItsLabelsAssigneesAndTheCard(): void {
		$this->store()->deleteCard(40);

		$this->assertSame(
			[
				['delete', 'deck_assigned_labels', 'card_id=40'],
				['delete', 'deck_assigned_users', 'card_id=40'],
				['delete', 'deck_cards', 'id=40'],
			],
			$this->writes
		);
	}

	public function testDeleteLabelRemovesItsAssignmentsAndTheLabel(): void {
		$this->store()->deleteLabel(31);

		$this->assertSame([['delete', 'deck_assigned_labels', 'label_id=31'], ['delete', 'deck_labels', 'id=31']], $this->writes);
	}

	public function testDeleteUnusedRemovesAnEmptyStackAndAnEmptyBoard(): void {
		$this->answers = ['0', '0'];

		$this->store()->deleteUnused(20, 10);

		$this->assertSame(
			[
				['delete', 'deck_stacks', 'id=20'],
				['delete', 'deck_labels', 'board_id=10'],
				['delete', 'deck_board_acl', 'board_id=10'],
				['delete', 'deck_boards', 'id=10'],
			],
			$this->writes
		);
	}

	public function testDeleteUnusedKeepsAStackOrBoardThatIsStillUsed(): void {
		$this->answers = ['1', '2'];

		$this->store()->deleteUnused(20, 10);

		$this->assertSame([], $this->writes);
	}

	public function testDeleteUnusedOfNothingQueriesNothing(): void {
		$this->answers = ['0'];

		$this->store()->deleteUnused(null, null);

		$this->assertSame([], $this->writes);
		$this->assertSame(['0'], $this->answers);
	}
}//end class
