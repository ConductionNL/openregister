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

use DateTime;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The usage report reads a window, optionally one version; pruning cuts by last_seen.
 *
 * count() itself is covered by ApiCallRecordMapperCountGoldenTest.
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

		$records = $this->mapper->findInPeriod($from, $to);

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

		$this->mapper->findInPeriod($from, $to, 'v2', 10);
		$this->mapper->findInPeriod($from, $to, '', 10);

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

		$this->mapper->findInPeriod($from, $from, null, 0);
		$this->mapper->findInPeriod($from, $from, null, -5);

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
}//end class
