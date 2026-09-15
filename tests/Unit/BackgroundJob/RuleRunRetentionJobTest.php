<?php

/**
 * The daily prune of the rule run log.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\RuleRunRetentionJob;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exercises the cut-off, the summary it must not touch, and the failure it
 * must not propagate.
 *
 * @package OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleRunRetentionJobTest extends TestCase {

	/**
	 * Build the job, capturing the cut-off it prunes at.
	 *
	 * @param int $days The configured retention period.
	 * @param array<int, DateTime> $cutoffs Captures the cut-offs.
	 * @param bool $pruneThrows Whether the prune refuses.
	 *
	 * @return RuleRunRetentionJob The job.
	 */
	private function job(int $days, array &$cutoffs, bool $pruneThrows = false): RuleRunRetentionJob {
		$runs = $this->createMock(originalClassName: RuleRunMapper::class);
		$runs->method('pruneBefore')->willReturnCallback(
			static function (DateTime $before, int $batches = 25) use (&$cutoffs, $pruneThrows): int {
				if ($pruneThrows === true) {
					throw new RuntimeException('the log table is locked');
				}

				$cutoffs[] = $before;
				return 12;
			}
		);

		$recorder = $this->createMock(originalClassName: RuleRunRecorder::class);
		$recorder->method('retentionDays')->willReturn($days);

		return new RuleRunRetentionJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			runs: $runs,
			recorder: $recorder,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end job()

	/**
	 * Run the job, reaching its protected entry point the way the scheduler does.
	 *
	 * @param RuleRunRetentionJob $job The job.
	 *
	 * @return void
	 */
	private function sweep(RuleRunRetentionJob $job): void {
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

	}//end sweep()

	/**
	 * The prune cuts at the configured period, not at a constant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testThePruneCutsAtTheConfiguredPeriod(): void {
		$cutoffs = [];
		$this->sweep(job: $this->job(days: 7, cutoffs: $cutoffs));

		$this->assertCount(expectedCount: 1, haystack: $cutoffs);
		$elapsed = ((new DateTime())->getTimestamp() - $cutoffs[0]->getTimestamp());
		// Seven days, give or take the second the test took to get here.
		$this->assertGreaterThanOrEqual(expected: (7 * 86400 - 5), actual: $elapsed);
		$this->assertLessThanOrEqual(expected: (7 * 86400 + 5), actual: $elapsed);

	}//end testThePruneCutsAtTheConfiguredPeriod()

	/**
	 * The job touches the detail log and nothing else.
	 *
	 * This is D-3 as a test: the summary is what the inventory reads after the
	 * detail is gone, so a prune that reached it would take "when did this rule
	 * last run" with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testThePruneNeverReachesTheSummary(): void {
		$source = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/BackgroundJob/RuleRunRetentionJob.php'
		);

		$this->assertStringNotContainsString(needle: 'RuleRunSummaryMapper', haystack: $source);
		$this->assertStringContainsString(needle: 'pruneBefore', haystack: $source);

	}//end testThePruneNeverReachesTheSummary()

	/**
	 * A prune that fails is logged and the job ends; a background job that
	 * throws is retried forever against a table that is still locked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAFailingPruneEndsTheJobRatherThanThrowing(): void {
		$cutoffs = [];

		$this->sweep(job: $this->job(days: 30, cutoffs: $cutoffs, pruneThrows: true));

		$this->assertSame(expected: [], actual: $cutoffs);

	}//end testAFailingPruneEndsTheJobRatherThanThrowing()
}//end class
