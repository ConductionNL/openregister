<?php

/**
 * The keyset-paged audit list (audit-log-page task 1.1).
 *
 * The query builder is an evaluating fake: every condition the query adds is
 * recorded as a predicate and run over an in-memory table, so a filter that is
 * built but never applied, or applied to the wrong column, changes the rows
 * that come back. A recording double would only show that a method was called.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Filters, period, full text and the cursor over an in-memory trail.
 */
class AuditTrailPageQueryTest extends TestCase {

	/**
	 * The table.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $table = [];

	/**
	 * Build the query over the in-memory table.
	 *
	 * @return AuditTrailPageQuery The query.
	 */
	private function query(): AuditTrailPageQuery {
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $v): string => addcslashes($v, '%_\\'));
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		return new AuditTrailPageQuery(db: $db);
	}//end query()

	/**
	 * An evaluating query-builder fake.
	 *
	 * @return IQueryBuilder The fake.
	 */
	private function builder(): IQueryBuilder {
		$state = (object)['params' => [], 'conds' => [], 'preds' => [], 'limit' => null];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);

		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) use ($state): string {
				$state->params[] = $value;
				return ':p' . (count($state->params) - 1);
			}
		);
		$param = static fn (string $p) => $state->params[(int)substr($p, 2)];
		$add = static function (callable $pred) use ($state): string {
			$state->preds[] = $pred;
			return 'c' . (count($state->preds) - 1);
		};
		$expr->method('eq')->willReturnCallback(fn ($c, $p) => $add(static fn (array $r): bool => (string)($r[$c] ?? '') === (string)$param($p)));
		$expr->method('lt')->willReturnCallback(fn ($c, $p) => $add(static fn (array $r): bool => $r[$c] < $param($p)));
		$expr->method('gte')->willReturnCallback(fn ($c, $p) => $add(static fn (array $r): bool => $r[$c] >= $param($p)));
		$expr->method('lte')->willReturnCallback(fn ($c, $p) => $add(static fn (array $r): bool => $r[$c] <= $param($p)));
		$expr->method('in')->willReturnCallback(fn ($c, $p) => $add(static fn (array $r): bool => in_array((string)($r[$c] ?? ''), $param($p), true)));
		$expr->method('like')->willReturnCallback(
			fn ($c, $p) => $add(
				static fn (array $r): bool => str_contains((string)($r[$c] ?? ''), trim(stripslashes((string)$param($p)), '%'))
			)
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('andWhere')->willReturnCallback(
			static function (string $cond) use ($qb, $state) {
				$state->conds[] = (int)substr($cond, 1);
				return $qb;
			}
		);
		$qb->method('setMaxResults')->willReturnCallback(
			static function ($limit) use ($qb, $state) {
				$state->limit = $limit;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($state): IResult {
				$rows = array_values(
					array_filter(
						$this->table,
						static function (array $row) use ($state): bool {
							foreach ($state->conds as $i) {
								if (($state->preds[$i])($row) === false) {
									return false;
								}
							}

							return true;
						}
					)
				);
				usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
				$rows = array_slice($rows, 0, (int)$state->limit);
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
				return $result;
			}
		);

		return $qb;
	}//end builder()

	/**
	 * Fill the trail: two by anna yesterday, one by bob today, ids ascending in time.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->table = [
			['id' => 1, 'user' => 'anna', 'action' => 'create', 'register' => '1', 'schema' => '2', 'object_uuid' => 'o-1', 'changed' => '{"title":"Vergunning"}', 'created' => '2026-10-08 09:00:00'],
			['id' => 2, 'user' => 'anna', 'action' => 'update', 'register' => '1', 'schema' => '2', 'object_uuid' => 'o-1', 'changed' => '{"status":"open"}', 'created' => '2026-10-08 15:00:00'],
			['id' => 3, 'user' => 'bob', 'action' => 'update', 'register' => '5', 'schema' => '6', 'object_uuid' => 'o-2', 'changed' => '{"title":"Bezwaar"}', 'created' => '2026-10-09 08:00:00'],
		];
	}//end setUp()

	/**
	 * Actor and period filter the list, newest first (spec scenario "an admin
	 * filters by actor and period").
	 *
	 * @return void
	 */
	public function testActorAndPeriodFilterNewestFirst(): void {
		$page = $this->query()->page(filters: ['actor' => 'anna', 'from' => '2026-10-07', 'to' => '2026-10-09'], search: null, limit: 20);

		$this->assertSame([2, 1], array_map(static fn ($e): int => (int)$e->getId(), $page['results']));
		$this->assertNull($page['nextCursor']);
	}//end testActorAndPeriodFilterNewestFirst()

	/**
	 * A bare date as `to` keeps the whole of that day.
	 *
	 * @return void
	 */
	public function testABareToDateKeepsTheWholeDay(): void {
		$page = $this->query()->page(filters: ['to' => '2026-10-08'], search: null, limit: 20);

		$this->assertSame([2, 1], array_map(static fn ($e): int => (int)$e->getId(), $page['results']));
	}//end testABareToDateKeepsTheWholeDay()

	/**
	 * Register, schema, object, action and full text each narrow the list.
	 *
	 * @return void
	 */
	public function testEachFilterNarrows(): void {
		$q = $this->query();
		$ids = static fn (array $page): array => array_map(static fn ($e): int => (int)$e->getId(), $page['results']);

		$this->assertSame([3], $ids($q->page(filters: ['register' => '5'], search: null, limit: 20)));
		$this->assertSame([2, 1], $ids($q->page(filters: ['schema' => '2'], search: null, limit: 20)));
		$this->assertSame([3], $ids($q->page(filters: ['object' => 'o-2'], search: null, limit: 20)));
		$this->assertSame([1], $ids($q->page(filters: ['action' => 'create'], search: null, limit: 20)));
		$this->assertSame([3, 2], $ids($q->page(filters: ['action' => 'update,delete'], search: null, limit: 20)));
		$this->assertSame([3], $ids($q->page(filters: [], search: 'Bezwaar', limit: 20)));
	}//end testEachFilterNarrows()

	/**
	 * The cursor walks the trail with nothing lost or repeated, and the last
	 * page says so with a null cursor.
	 *
	 * @return void
	 */
	public function testTheCursorWalksWithoutLossOrRepeat(): void {
		$q = $this->query();

		$first = $q->page(filters: [], search: null, limit: 2);
		$second = $q->page(filters: [], search: null, limit: 2, beforeId: $first['nextCursor']);

		$this->assertSame([3, 2], array_map(static fn ($e): int => (int)$e->getId(), $first['results']));
		$this->assertSame(2, $first['nextCursor']);
		$this->assertSame([1], array_map(static fn ($e): int => (int)$e->getId(), $second['results']));
		$this->assertNull($second['nextCursor']);
	}//end testTheCursorWalksWithoutLossOrRepeat()

	/**
	 * Collect stops at the ceiling and says it did.
	 *
	 * @return void
	 */
	public function testCollectStopsAtTheCeilingAndSaysSo(): void {
		$collected = $this->query()->collect(filters: [], search: null, ceiling: 2);

		$this->assertCount(2, $collected['results']);
		$this->assertTrue($collected['truncated']);
		$this->assertFalse($this->query()->collect(filters: [], search: null, ceiling: 3)['truncated']);
	}//end testCollectStopsAtTheCeilingAndSaysSo()

	/**
	 * A period bound that is not a date is refused, not ignored.
	 *
	 * @return void
	 */
	public function testANonDatePeriodIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->query()->page(filters: ['from' => 'gisteren'], search: null, limit: 20);
	}//end testANonDatePeriodIsRefused()
}//end class
