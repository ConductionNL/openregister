<?php

/**
 * Golden test: what ActivityFilterService::getActivityEntries() returns and queries.
 *
 * Pins, on fixed inputs, the refused reads (activity app missing, a
 * public-only read in any spelling) and the allowed ones (every other
 * visibility), the page and count queries each filter combination builds
 * (the count never carries the cursor), limit clamping, slicing,
 * nextCursor derivation and the degraded path on a database error.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ActivityFilterService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of the activity entry page.
 */
class ActivityFilterServiceEntriesGoldenTest extends TestCase {

	/**
	 * Every query built, as a list of clause strings per query.
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $queries = [];

	/**
	 * Build the service over staged page rows and a staged count.
	 *
	 * @param array<int,array<string,mixed>> $rows      Page rows.
	 * @param int                            $count     The count row value.
	 * @param bool                           $installed Whether the activity app is there.
	 * @param bool                           $fail      Whether executeQuery throws.
	 *
	 * @return ActivityFilterService
	 */
	private function service(array $rows, int $count = 0, bool $installed = true, bool $fail = false): ActivityFilterService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => ($app === 'activity' && $installed === true)
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->queryBuilder(rows: $rows, count: $count, fail: $fail)
		);

		return new ActivityFilterService(db: $db, appManager: $appManager, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A recording query builder.
	 *
	 * @param array<int,array<string,mixed>> $rows  Page rows.
	 * @param int                            $count Count value.
	 * @param bool                           $fail  Whether executeQuery throws.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(array $rows, int $count, bool $fail): IQueryBuilder {
		$index = count($this->queries);
		$this->queries[$index] = [];
		$log   = function (string $clause) use ($index): void {
			$this->queries[$index][] = $clause;
		};

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['iLike', 'eq', 'gte', 'lt'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static fn (string $column, mixed $param): string => $operator . '(' . $column . ',' . $param . ')'
			);
		}

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$isCount = false;
		$queue   = $rows;
		$result  = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			static function () use (&$queue, &$isCount, $count) {
				if ($isCount === true) {
					$isCount = false;
					return ['cnt' => (string) $count];
				}

				if ($queue === []) {
					return false;
				}

				return array_shift($queue);
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturnCallback(
			static function () use (&$isCount, $func): IFunctionBuilder {
				$isCount = true;
				return $func;
			}
		);
		foreach (['select', 'selectDistinct', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed ...$args) use (&$qb, $log, $method): IQueryBuilder {
					$log($method . ':' . implode('|', array_map(static fn ($a): string => self::clause(value: $a), $args)));
					return $qb;
				}
			);
		}

		$qb->method('createNamedParameter')->willReturnCallback(
			static fn (mixed $value, mixed $type = null): string => '[' . var_export($value, true) . '#' . self::clause(value: $type) . ']'
		);
		$qb->method('executeQuery')->willReturnCallback(
			static function () use ($fail, $result): IResult {
				if ($fail === true) {
					throw new RuntimeException('db down');
				}

				return $result;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A clause argument as text: a query function as 'fn'.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return string
	 */
	private static function clause(mixed $value): string {
		if (is_object($value) === true) {
			return 'fn';
		}

		return (string) $value;
	}//end clause()

	/**
	 * A raw activity row.
	 *
	 * @param int $id        Activity id.
	 * @param int $timestamp Timestamp.
	 *
	 * @return array<string,mixed>
	 */
	private function row(int $id, int $timestamp): array {
		return [
			'activity_id' => $id,
			'subject' => 'S' . $id . ' [or:u1]',
			'type' => 'files',
			'timestamp' => $timestamp,
			'affecteduser' => 'alice',
			'object_id' => 'u1',
		];
	}//end row()

	public function testRefusedWhenTheActivityAppIsMissing(): void {
		$page = $this->service(rows: [$this->row(1, 10)], count: 1, installed: false)->getActivityEntries(objectUuid: 'u1');

		self::assertSame(['results' => [], 'total' => 0, 'nextCursor' => null], $page);
		self::assertSame([], $this->queries);
	}//end testRefusedWhenTheActivityAppIsMissing()

	public function testRefusedForAPublicOnlyReadInAnySpelling(): void {
		foreach (['public', ' PUBLIC ', 'Public'] as $visibility) {
			$page = $this->service(rows: [$this->row(1, 10)], count: 1)->getActivityEntries(objectUuid: 'u1', visibility: $visibility);
			self::assertSame(['results' => [], 'total' => 0, 'nextCursor' => null], $page, $visibility);
		}

		self::assertSame([], $this->queries);
	}//end testRefusedForAPublicOnlyReadInAnySpelling()

	public function testAllowedForEveryOtherVisibility(): void {
		foreach ([null, 'internal', '', 'publicx'] as $visibility) {
			$this->queries = [];
			$page = $this->service(rows: [$this->row(1, 10)], count: 1)->getActivityEntries(objectUuid: 'u1', visibility: $visibility);
			self::assertCount(1, $page['results'], (string) $visibility);
			self::assertSame(1, $page['total']);
			self::assertCount(2, $this->queries);
		}
	}//end testAllowedForEveryOtherVisibility()

	public function testFullFilterPageAndCount(): void {
		$rows = [$this->row(9, 900), $this->row(8, 800), $this->row(7, 700)];
		$page = $this->service(rows: $rows, count: 42)->getActivityEntries(
			objectUuid: 'u1',
			type: 'files',
			actor: 'alice',
			after: 100,
			limit: 2,
			cursor: 1000,
		);

		self::assertSame(42, $page['total']);
		self::assertSame(800, $page['nextCursor']);
		self::assertSame(
			[
				[
					'id' => '9',
					'title' => 'S9 [or:u1]',
					'subject' => 'S9 [or:u1]',
					'type' => 'files',
					'timestamp' => 900,
					'affecteduser' => 'alice',
					'actor_id' => 'alice',
					'object_id' => 'u1',
					'url' => '/index.php/apps/activity/9',
					'visibility' => 'internal',
					'data' => $rows[0],
				],
				[
					'id' => '8',
					'title' => 'S8 [or:u1]',
					'subject' => 'S8 [or:u1]',
					'type' => 'files',
					'timestamp' => 800,
					'affecteduser' => 'alice',
					'actor_id' => 'alice',
					'object_id' => 'u1',
					'url' => '/index.php/apps/activity/8',
					'visibility' => 'internal',
					'data' => $rows[1],
				],
			],
			$page['results']
		);
		self::assertSame(
			[
				[
					'select:activity_id|subject|type|affecteduser|timestamp|object_id',
					'from:activity|',
					"where:iLike(subject,['%[or:u1]%'#2])",
					"andWhere:eq(type,['files'#2])",
					"andWhere:eq(affecteduser,['alice'#2])",
					'andWhere:gte(timestamp,[100#1])',
					'andWhere:lt(timestamp,[1000#1])',
					'orderBy:timestamp|DESC',
					'addOrderBy:activity_id|DESC',
					'setMaxResults:3',
				],
				[
					'select:fn',
					'from:activity|',
					"where:iLike(subject,['%[or:u1]%'#2])",
					"andWhere:eq(type,['files'#2])",
					"andWhere:eq(affecteduser,['alice'#2])",
					'andWhere:gte(timestamp,[100#1])',
				],
			],
			$this->queries
		);
	}//end testFullFilterPageAndCount()

	public function testEmptyFiltersAddNoClauses(): void {
		$page = $this->service(rows: [$this->row(1, 10)], count: 1)->getActivityEntries(objectUuid: 'u1', type: '', actor: '', limit: 5);

		self::assertNull($page['nextCursor']);
		self::assertSame(
			[
				[
					'select:activity_id|subject|type|affecteduser|timestamp|object_id',
					'from:activity|',
					"where:iLike(subject,['%[or:u1]%'#2])",
					'orderBy:timestamp|DESC',
					'addOrderBy:activity_id|DESC',
					'setMaxResults:6',
				],
				['select:fn', 'from:activity|', "where:iLike(subject,['%[or:u1]%'#2])"],
			],
			$this->queries
		);
	}//end testEmptyFiltersAddNoClauses()

	public function testLimitIsClamped(): void {
		$expected = [0 => 'setMaxResults:101', -3 => 'setMaxResults:101', 1 => 'setMaxResults:2', 200 => 'setMaxResults:201', 500 => 'setMaxResults:201'];
		foreach ($expected as $limit => $clause) {
			$this->queries = [];
			$this->service(rows: [], count: 0)->getActivityEntries(objectUuid: 'u1', limit: $limit);
			self::assertSame($clause, $this->queries[0][5], (string) $limit);
		}
	}//end testLimitIsClamped()

	public function testExactlyAFullPageHasNoNextCursor(): void {
		$page = $this->service(rows: [$this->row(2, 20), $this->row(1, 10)], count: 2)->getActivityEntries(objectUuid: 'u1', limit: 2);

		self::assertCount(2, $page['results']);
		self::assertNull($page['nextCursor']);
		self::assertSame(2, $page['total']);
	}//end testExactlyAFullPageHasNoNextCursor()

	public function testADatabaseErrorDegradesToAnEmptyPage(): void {
		$page = $this->service(rows: [$this->row(1, 10)], count: 1, fail: true)->getActivityEntries(objectUuid: 'u1');

		self::assertSame(['results' => [], 'total' => 0, 'nextCursor' => null], $page);
	}//end testADatabaseErrorDegradesToAnEmptyPage()
}//end class
