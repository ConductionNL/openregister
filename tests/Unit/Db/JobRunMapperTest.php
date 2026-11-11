<?php

/**
 * JobRunMapperTest: the queries of the job-run ledger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunFilter;
use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\JobRunMapper
 * @uses   \OCA\OpenRegister\Db\JobRun
 * @uses   \OCA\OpenRegister\Db\JobRunFilter
 */
class JobRunMapperTest extends TestCase {
	use RecordsQueries;

	private const TABLE = 'from(openregister_job_runs)';

	/**
	 * The mapper on a recording connection.
	 *
	 * @return JobRunMapper
	 */
	private function mapper(): JobRunMapper {
		return new JobRunMapper($this->recordingConnection());
	}//end mapper()

	/**
	 * A fixed UTC moment.
	 *
	 * @param string $time The time.
	 *
	 * @return DateTime
	 */
	private static function at(string $time): DateTime {
		return new DateTime($time, new DateTimeZone('UTC'));
	}//end at()

	public function testFindRecentWithoutFilterPagesNewestFirst(): void {
		$this->rowSets = [[['id' => 3, 'job_class' => 'A', 'outcome' => 'completed'], ['id' => 2, 'job_class' => 'B', 'outcome' => 'failed']]];

		$runs = $this->mapper()->findRecent(null, 20, 40);

		$this->assertSame([3, 2], array_map(static fn (JobRun $run): int => $run->getId(), $runs));
		$this->assertSame('B', $runs[1]->getJobClass());
		$this->assertSame(
			['select(*)', self::TABLE, 'orderBy(started, DESC)', 'addOrderBy(id, DESC)', 'setMaxResults(20)', 'setFirstResult(40)', 'executeQuery'],
			$this->queries[0]
		);
	}//end testFindRecentWithoutFilterPagesNewestFirst()

	public function testFindRecentClampsTheLimitAndOffset(): void {
		$this->mapper()->findRecent(null, 0, -5);
		$this->mapper()->findRecent(null, 10000);

		$this->assertContains('setMaxResults(1)', $this->queries[0]);
		$this->assertContains('setFirstResult(0)', $this->queries[0]);
		$this->assertContains('setMaxResults(500)', $this->queries[1]);
	}//end testFindRecentClampsTheLimitAndOffset()

	public function testFindRecentNarrowsByEveryFilterField(): void {
		$filter = new JobRunFilter('OCA\\Job', 'failed', self::at('2026-01-01 00:00:00'), self::at('2026-01-31 23:59:59'));

		$this->mapper()->findRecent($filter);

		$this->assertSame(
			[
				"andWhere(eq(job_class, 'OCA\\Job'))",
				"andWhere(eq(outcome, 'failed'))",
				"andWhere(gte(started, '2026-01-01 00:00:00'))",
				"andWhere(lte(started, '2026-01-31 23:59:59'))",
			],
			array_values(array_filter($this->queries[0], static fn (string $call): bool => str_starts_with($call, 'andWhere')))
		);
		$this->assertContains('setMaxResults(50)', $this->queries[0]);
	}//end testFindRecentNarrowsByEveryFilterField()

	public function testEmptyFilterValuesDoNotNarrow(): void {
		$this->mapper()->findRecent(new JobRunFilter('', null));

		$this->assertSame([], array_filter($this->queries[0], static fn (string $call): bool => str_starts_with($call, 'andWhere')));
	}//end testEmptyFilterValuesDoNotNarrow()

	public function testCountRecentReadsTheTotalOfTheNarrowedQuery(): void {
		$this->rowSets = [[['total' => '17']]];

		$this->assertSame(17, $this->mapper()->countRecent('OCA\\Job', null, self::at('2026-02-01 00:00:00')));
		$this->assertSame(
			['select(count(*, total))', self::TABLE, "andWhere(eq(job_class, 'OCA\\Job'))", "andWhere(gte(started, '2026-02-01 00:00:00'))", 'executeQuery'],
			$this->queries[0]
		);
	}//end testCountRecentReadsTheTotalOfTheNarrowedQuery()

	public function testCountRecentIsZeroWithoutARow(): void {
		$this->rowSets = [[]];

		$this->assertSame(0, $this->mapper()->countRecent(null, 'running', null, self::at('2026-03-01 00:00:00')));
		$this->assertContains("andWhere(lte(started, '2026-03-01 00:00:00'))", $this->queries[0]);
	}//end testCountRecentIsZeroWithoutARow()

	public function testCountRecentIsZeroWhenTheRowHasNoTotal(): void {
		$this->rowSets = [[['other' => 4]]];

		$this->assertSame(0, $this->mapper()->countRecent());
	}//end testCountRecentIsZeroWhenTheRowHasNoTotal()

	public function testFindRunningReturnsTheNewestRunningRun(): void {
		$this->rowSets = [[['id' => 8, 'job_class' => 'OCA\\Job', 'outcome' => 'running']]];

		$run = $this->mapper()->findRunning('OCA\\Job');

		$this->assertSame(8, $run?->getId());
		$this->assertSame(
			['select(*)', self::TABLE, "where(eq(job_class, 'OCA\\Job'))", "andWhere(eq(outcome, 'running'))", 'orderBy(started, DESC)', 'setMaxResults(1)', 'executeQuery'],
			$this->queries[0]
		);
	}//end testFindRunningReturnsTheNewestRunningRun()

	public function testFindRunningIsNullWhenNothingRuns(): void {
		$this->rowSets = [[]];

		$this->assertNull($this->mapper()->findRunning('OCA\\Job'));
	}//end testFindRunningIsNullWhenNothingRuns()

	public function testFailuresSinceListsFailedRunsOldestFirst(): void {
		$this->rowSets = [[['id' => 1, 'outcome' => 'failed'], ['id' => 4, 'outcome' => 'failed']]];

		$runs = $this->mapper()->failuresSince('OCA\\Job', self::at('2026-04-01 12:00:00'));

		$this->assertSame([1, 4], array_map(static fn (JobRun $run): int => $run->getId(), $runs));
		$this->assertSame(
			[
				'select(*)',
				self::TABLE,
				"where(eq(job_class, 'OCA\\Job'))",
				"andWhere(eq(outcome, 'failed'))",
				"andWhere(gte(started, '2026-04-01 12:00:00'))",
				'orderBy(started, ASC)',
				'addOrderBy(id, ASC)',
				'setMaxResults(500)',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testFailuresSinceListsFailedRunsOldestFirst()

	public function testPruneBeforeDeletesOlderRunsAndReportsHowMany(): void {
		$this->statementResults = [12];

		$this->assertSame(12, $this->mapper()->pruneBefore(self::at('2025-12-01 00:00:00')));
		$this->assertSame(['delete(openregister_job_runs)', "where(lt(started, '2025-12-01 00:00:00'))", 'executeStatement'], $this->queries[0]);
	}//end testPruneBeforeDeletesOlderRunsAndReportsHowMany()

	public function testRenameJobClassMovesRunsToTheNewClass(): void {
		$this->statementResults = [3];

		$this->assertSame(3, $this->mapper()->renameJobClass('OCA\\Old', 'OCA\\New'));
		$this->assertSame(['update(openregister_job_runs)', "set(job_class, 'OCA\\New')", "where(eq(job_class, 'OCA\\Old'))", 'executeStatement'], $this->queries[0]);
	}//end testRenameJobClassMovesRunsToTheNewClass()
}//end class
