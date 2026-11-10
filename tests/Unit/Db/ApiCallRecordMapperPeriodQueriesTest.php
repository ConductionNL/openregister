<?php

/**
 * ApiCallRecordMapper period and prune query tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ApiCall\ApiCallPeriod;
use DateTime;
use OCA\OpenRegister\Db\ApiCallKey;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use RuntimeException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The usage report reads a window, optionally one version; pruning cuts by last_seen.
 *
 * count() increments a known key, inserts a new one, and retries a lost insert race.
 */
class ApiCallRecordMapperPeriodQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var ApiCallRecordMapper
	 */
	private ApiCallRecordMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new ApiCallRecordMapper($this->recordingDb());
	}//end setUp()

	/**
	 * findInPeriod() binds both bounds as datetimes, busiest first, default limit 500.
	 *
	 * @return void
	 */
	public function testFindInPeriodReadsTheWindowBusiestFirst(): void {
		$from            = new DateTime('2026-01-01T00:00:00+00:00');
		$to              = new DateTime('2026-01-31T00:00:00+00:00');
		$this->results[] = [['id' => 1, 'route' => '/api/a', 'call_count' => 9]];

		$records = $this->mapper->findInPeriod(new ApiCallPeriod($from, $to));

		$this->assertSame('/api/a', $records[0]->getRoute());
		$this->assertSame(
			'select(*) from(openregister_api_calls) where(gte(last_seen, :p0)) andWhere(lte(last_seen, :p1)) orderBy(call_count, DESC) setMaxResults(500) executeQuery()',
			$this->sql()
		);
		$this->assertSame([$from, IQueryBuilder::PARAM_DATETIME_MUTABLE], $this->params[':p0']);
		$this->assertSame([$to, IQueryBuilder::PARAM_DATETIME_MUTABLE], $this->params[':p1']);
	}//end testFindInPeriodReadsTheWindowBusiestFirst()

	/**
	 * A version narrows the window; an empty version does not.
	 *
	 * @return void
	 */
	public function testFindInPeriodNarrowsToAVersionOnlyWhenGiven(): void {
		$from = new DateTime('2026-01-01T00:00:00+00:00');
		$to   = new DateTime('2026-01-02T00:00:00+00:00');

		$this->mapper->findInPeriod(new ApiCallPeriod($from, $to, 'v2', 10));
		$this->mapper->findInPeriod(new ApiCallPeriod($from, $to, '', 10));

		$this->assertStringEndsWith('setMaxResults(10) andWhere(eq(api_version, :p2)) executeQuery()', $this->sql(0));
		$this->assertSame('v2', $this->bound(':p2'));
		$this->assertStringNotContainsString('api_version', $this->sql(1));
	}//end testFindInPeriodNarrowsToAVersionOnlyWhenGiven()

	/**
	 * A non-positive limit is raised to one row.
	 *
	 * @return void
	 */
	public function testFindInPeriodNeverAsksForZeroRows(): void {
		$from = new DateTime('2026-01-01T00:00:00+00:00');

		$this->mapper->findInPeriod(new ApiCallPeriod($from, $from, null, 0));
		$this->mapper->findInPeriod(new ApiCallPeriod($from, $from, null, -5));

		$this->assertStringContainsString('setMaxResults(1)', $this->sql(0));
		$this->assertStringContainsString('setMaxResults(1)', $this->sql(1));
	}//end testFindInPeriodNeverAsksForZeroRows()

	/**
	 * pruneBefore() deletes rows last seen before the cut and reports the count.
	 *
	 * @return void
	 */
	public function testPruneBeforeDeletesOlderRows(): void {
		$cut            = new DateTime('2025-12-01T00:00:00+00:00');
		$this->affected = 17;

		$this->assertSame(17, $this->mapper->pruneBefore($cut));
		$this->assertSame('delete(openregister_api_calls) where(lt(last_seen, :p0)) executeStatement()', $this->sql());
		$this->assertSame([$cut, IQueryBuilder::PARAM_DATETIME_MUTABLE], $this->params[':p0']);
	}//end testPruneBeforeDeletesOlderRows()

	/**
	 * The key every count test records.
	 *
	 * @return ApiCallKey
	 */
	private function key(): ApiCallKey {
		return new ApiCallKey('leverancier', '/api/objects/{register}', 'POST', 'v1');
	}//end key()

	/**
	 * A known key is counted by one increment and no insert.
	 *
	 * @return void
	 */
	public function testCountIncrementsAKnownKey(): void {
		$moment         = new DateTime('2026-03-04T05:06:07+00:00');
		$this->outcomes = [1];

		$this->assertTrue($this->mapper->count($this->key(), $moment));
		$this->assertCount(1, $this->queries);
		$this->assertSame(
			'update(openregister_api_calls) set(call_count, call_count + 1) set(last_seen, :p0) where(eq(principal, :p1)) andWhere(eq(route, :p2)) andWhere(eq(method, :p3)) andWhere(eq(api_version, :p4)) executeStatement()',
			$this->sql()
		);
		$this->assertSame([$moment, 'leverancier', '/api/objects/{register}', 'POST', 'v1'], $this->boundValues());
	}//end testCountIncrementsAKnownKey()

	/**
	 * A new key is inserted with a count of one, first and last seen at the moment.
	 *
	 * @return void
	 */
	public function testCountInsertsANewKey(): void {
		$moment         = new DateTime('2026-03-04T05:06:07+00:00');
		$this->outcomes = [0, 1];

		$this->assertTrue($this->mapper->count($this->key(), $moment));
		$this->assertCount(2, $this->queries);
		$insert = $this->sql(1);
		$this->assertStringStartsWith('insert(openregister_api_calls)', $insert);
		foreach (['principal', 'route', 'method', 'api_version', 'call_count', 'first_seen', 'last_seen'] as $column) {
			$this->assertStringContainsString('setValue(' . $column . ', ', $insert);
		}

		$values = array_slice($this->boundValues(), 5);
		$this->assertSame(['leverancier', '/api/objects/{register}', 'POST', 'v1', 1, $moment, $moment], $values);
	}//end testCountInsertsANewKey()

	/**
	 * A lost insert race is retried as an increment, which decides the answer.
	 *
	 * @return void
	 */
	public function testCountRetriesALostInsertRace(): void {
		$moment         = new DateTime('2026-03-04T05:06:07+00:00');
		$this->outcomes = [0, new RuntimeException('duplicate key'), 1];

		$this->assertTrue($this->mapper->count($this->key(), $moment));
		$this->assertCount(3, $this->queries);
		$this->assertStringStartsWith('update(openregister_api_calls)', $this->sql(2));
	}//end testCountRetriesALostInsertRace()

	/**
	 * When the retried increment also finds no row, the call was not counted.
	 *
	 * @return void
	 */
	public function testCountReportsFailureWhenTheRetryFindsNothing(): void {
		$this->outcomes = [0, new RuntimeException('table locked'), 0];

		$this->assertFalse($this->mapper->count($this->key(), new DateTime('2026-03-04T05:06:07+00:00')));
	}//end testCountReportsFailureWhenTheRetryFindsNothing()
}//end class
