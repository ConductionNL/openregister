<?php

/**
 * Unit tests for SearchTrailStatistics: the period expression per database,
 * the per-session averages and the summary of an empty store.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\SearchTrail\CreatedRange;
use OCA\OpenRegister\Db\SearchTrail\SearchTrailStatistics;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SearchTrail\SearchTrailStatistics
 * @uses   \OCA\OpenRegister\Db\SearchTrail\CreatedRange
 */
class SearchTrailStatisticsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * Statistics over a recording builder, on the given database platform.
	 *
	 * @param AbstractPlatform|null $platform The platform the connection reports.
	 *
	 * @return SearchTrailStatistics The statistics.
	 */
	private function statistics(?AbstractPlatform $platform = null): SearchTrailStatistics {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		if ($platform !== null) {
			$db->method('getDatabasePlatform')->willReturn($platform);
		}

		return new SearchTrailStatistics($db, 'openregister_search_trails');
	}//end statistics()

	/**
	 * The logged lines that select the date period.
	 *
	 * @return array<int, string> The lines.
	 */
	private function periodSelects(): array {
		return array_values(
			array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'AS date_period'))
		);
	}//end periodSelects()

	/**
	 * The period expression per database and interval, with a day fallback for an unknown interval.
	 *
	 * @return array<string, array{0: AbstractPlatform, 1: string, 2: string}>
	 */
	public static function periods(): array {
		return [
			'mysql week'        => [new MySQLPlatform(), 'week', "DATE_FORMAT(created, '%Y-%u') AS date_period"],
			'mysql hour'        => [new MySQLPlatform(), 'hour', "DATE_FORMAT(created, '%Y-%m-%d %H:00:00') AS date_period"],
			'mysql unknown'     => [new MySQLPlatform(), 'decade', "DATE_FORMAT(created, '%Y-%m-%d') AS date_period"],
			'postgres week'     => [new PostgreSQLPlatform(), 'week', "TO_CHAR(created, 'IYYY-IW') AS date_period"],
			'postgres month'    => [new PostgreSQLPlatform(), 'month', "TO_CHAR(created, 'YYYY-MM') AS date_period"],
			'postgres unknown'  => [new PostgreSQLPlatform(), '', "TO_CHAR(created, 'YYYY-MM-DD') AS date_period"],
			'sqlite week'       => [new SqlitePlatform(), 'week', "strftime('%Y-%W', created) AS date_period"],
			'sqlite day'        => [new SqlitePlatform(), 'day', "strftime('%Y-%m-%d', created) AS date_period"],
			'sqlite unknown'    => [new SqlitePlatform(), 'year', "strftime('%Y-%m-%d', created) AS date_period"],
		];
	}//end periods()

	/**
	 * Each database gets its own period expression for the interval.
	 *
	 * @param AbstractPlatform $platform The platform.
	 * @param string           $interval The interval asked for.
	 * @param string           $expected The period expression selected.
	 *
	 * @return void
	 *
	 * @dataProvider periods
	 */
	public function testActivityByTimeSelectsThePeriodForTheDatabase(AbstractPlatform $platform, string $interval, string $expected): void {
		$this->queuedRows = [[['date_period' => '2026-46', 'search_count' => '3', 'avg_results' => '1.555', 'avg_response_time' => '20']]];

		$rows = $this->statistics($platform)->activityByTime($interval, new CreatedRange());

		$selects = $this->periodSelects();
		$this->assertCount(1, $selects);
		$this->assertStringContainsString($expected, $selects[0]);
		$this->assertSame([['period' => '2026-46', 'count' => 3, 'avg_results' => 1.56, 'avg_response_time' => 20.0]], $rows);
	}//end testActivityByTimeSelectsThePeriodForTheDatabase()

	/**
	 * A database the class does not know gets no period expression at all.
	 *
	 * @return void
	 */
	public function testAnUnknownDatabaseSelectsNoPeriod(): void {
		$this->queuedRows = [[]];

		$rows = $this->statistics($this->createMock(AbstractPlatform::class))->activityByTime('day', new CreatedRange());

		$this->assertSame([], $this->periodSelects());
		$this->assertSame([], $rows);
	}//end testAnUnknownDatabaseSelectsNoPeriod()

	/**
	 * Searches per session are the count over the distinct sessions, rounded to two decimals.
	 *
	 * @return void
	 */
	public function testSearchesPerSessionDividesAndRounds(): void {
		$this->queuedRows = [[['total_searches' => '7', 'unique_sessions' => '3']]];

		$this->assertSame(2.33, $this->statistics()->searchesPerSession(new CreatedRange()));
	}//end testSearchesPerSessionDividesAndRounds()

	/**
	 * No sessions, or no row at all, answer zero instead of dividing by zero.
	 *
	 * @return void
	 */
	public function testPerSessionWithoutSessionsIsZero(): void {
		$this->queuedRows = [[['total_views' => '9', 'unique_sessions' => '0']], []];
		$statistics = $this->statistics();

		$this->assertSame(0.0, $statistics->objectViewsPerSession(new CreatedRange()));
		$this->assertSame(0.0, $statistics->searchesPerSession(new CreatedRange()));
	}//end testPerSessionWithoutSessionsIsZero()

	/**
	 * Object views per session read the read actions of the audit trail, not the search trail.
	 *
	 * @return void
	 */
	public function testObjectViewsPerSessionReadsTheAuditTrail(): void {
		$this->queuedRows = [[['total_views' => '10', 'unique_sessions' => '4']]];

		$this->assertSame(2.5, $this->statistics()->objectViewsPerSession(new CreatedRange()));
		$this->assertContains('#1 from(openregister_audit_trails, NULL)', $this->queryLog);
		$this->assertContains('#1 param :p1 = read, 2', $this->queryLog);
	}//end testObjectViewsPerSessionReadsTheAuditTrail()

	/**
	 * The range bounds are written as inclusive created limits; an open bound adds nothing.
	 *
	 * @return void
	 */
	public function testTheRangeBoundsTheCreatedColumn(): void {
		$this->queuedRows = [[], []];
		$statistics = $this->statistics();

		$statistics->distinctCount('user', new CreatedRange(new DateTime('2026-01-01 08:00:00'), new DateTime('2026-01-31 18:30:00')));
		$params = array_values(array_filter($this->queryLog, static fn (string $l): bool => str_contains($l, ' param ')));

		$this->assertSame(['#1 param :p1 = , 2', '#1 param :p2 = 2026-01-01 08:00:00, 2', '#1 param :p3 = 2026-01-31 18:30:00, 2'], $params);
		$this->assertContains('#1 expr.gte(created, :p2, NULL)', $this->queryLog);
		$this->assertContains('#1 expr.lte(created, :p3, NULL)', $this->queryLog);

		$this->queryLog = [];
		$statistics->distinctCount('user', new CreatedRange(null, new DateTime('2026-02-01 00:00:00')));
		$params = array_values(array_filter($this->queryLog, static fn (string $l): bool => str_contains($l, ' param ')));

		$this->assertSame(['#2 param :p4 = , 2', '#2 param :p5 = 2026-02-01 00:00:00, 2'], $params);
	}//end testTheRangeBoundsTheCreatedColumn()

	/**
	 * An empty store summarises as zeros, not as missing keys.
	 *
	 * @return void
	 */
	public function testTheSummaryOfAnEmptyStoreIsZeros(): void {
		$this->queuedRows = [[]];

		$this->assertSame(
			[
				'total_searches' => 0,
				'total_results' => 0,
				'avg_results_per_search' => 0.0,
				'avg_response_time' => 0.0,
				'non_empty_searches' => 0,
			],
			$this->statistics()->summary(new CreatedRange())
		);
	}//end testTheSummaryOfAnEmptyStoreIsZeros()
}//end class
