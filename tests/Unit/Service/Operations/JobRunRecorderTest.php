<?php

/**
 * Unit tests for JobRunRecorder — the wrapper that makes a run a row.
 *
 * The log's whole value is that a failure is visible in it. A recorder that
 * writes only on success produces a run log in which nothing ever fails, which
 * reads exactly like an instance that is fine, so the failing path is the one
 * asserted hardest here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JobRunRecorderTest extends TestCase {

	/**
	 * The run log.
	 *
	 * @var JobRunMapper
	 */
	private JobRunMapper $runs;

	/**
	 * The failure threshold.
	 *
	 * @var JobAlertService
	 */
	private JobAlertService $alerts;

	/**
	 * The rows the recorder inserted, in order.
	 *
	 * @var array<int, JobRun>
	 */
	private array $inserted = [];

	/**
	 * The rows the recorder closed, in order.
	 *
	 * @var array<int, JobRun>
	 */
	private array $updated = [];

	protected function setUp(): void {
		parent::setUp();

		$this->runs = $this->createMock(JobRunMapper::class);
		$this->alerts = $this->createMock(JobAlertService::class);

		$this->runs->method('insert')->willReturnCallback(
			function (JobRun $run): JobRun {
				$this->inserted[] = $run;
				$run->setId(count($this->inserted));

				return $run;
			}
		);

		$this->runs->method('update')->willReturnCallback(
			function (JobRun $run): JobRun {
				$this->updated[] = $run;

				return $run;
			}
		);
	}

	private function recorder(): JobRunRecorder {
		return new JobRunRecorder(
			$this->runs,
			$this->createMock(LoggerInterface::class),
			$this->alerts
		);
	}

	/**
	 * A run that ends leaves a row saying when it started, when it ended and
	 * that it completed.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 *
	 * @return void
	 */
	public function testACompletedRunIsARowWithBothMomentsAndADuration(): void {
		$this->recorder()->around('Acme\\NightlyJob', static fn (): string => 'done');

		$this->assertCount(1, $this->inserted);
		$this->assertCount(1, $this->updated);

		$row = $this->updated[0];
		$this->assertSame('Acme\\NightlyJob', $row->getJobClass());
		$this->assertSame(JobRun::OUTCOME_COMPLETED, $row->getOutcome());
		$this->assertNotNull($row->getStarted());
		$this->assertNotNull($row->getEnded());
		$this->assertNotNull($row->getDurationMs());
		$this->assertNull($row->getMessage());
	}

	/**
	 * A run that throws is recorded as failed, WITH what it threw, and the
	 * throwable still reaches the caller.
	 *
	 * The re-throw is the half that is easy to lose: swallowing it here would
	 * change what the cron worker sees, and a recorder must observe an
	 * execution without altering it.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 *
	 * @return void
	 */
	public function testAFailedRunIsRecordedWithItsReasonAndStillThrows(): void {
		$recorder = $this->recorder();
		$thrown = null;

		try {
			$recorder->around(
				'Acme\\NightlyJob',
				static function (): void {
					throw new RuntimeException('the source refused the connection');
				}
			);
		} catch (RuntimeException $failure) {
			$thrown = $failure;
		}

		$this->assertNotNull($thrown, 'The recorder swallowed the failure.');
		$this->assertSame('the source refused the connection', $thrown->getMessage());

		$row = $this->updated[0];
		$this->assertSame(JobRun::OUTCOME_FAILED, $row->getOutcome());
		$this->assertStringContainsString('the source refused the connection', (string)$row->getMessage());
	}

	/**
	 * A failure is offered to the alert threshold; a completed run is not.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testOnlyAFailureReachesTheAlertThreshold(): void {
		$seen = [];
		$this->alerts->method('observeFailure')->willReturnCallback(
			function (string $jobClass) use (&$seen): ?array {
				$seen[] = $jobClass;

				return null;
			}
		);

		$recorder = $this->recorder();
		$recorder->around('Acme\\QuietJob', static fn (): bool => true);

		$this->assertSame([], $seen);

		try {
			$recorder->around('Acme\\NoisyJob', static fn (): never => throw new RuntimeException('boom'));
		} catch (RuntimeException) {
			// Expected: the recorder re-throws.
		}

		$this->assertSame(['Acme\\NoisyJob'], $seen);
	}

	/**
	 * One execution is one row, even when run now wraps a job that already
	 * records itself.
	 *
	 * Without the nesting guard, a manual run of a recorded job writes two
	 * rows, and the second one, the job's own, carries no actor. A reader then
	 * sees an unexplained run beside the explained one.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testANestedRecordDoesNotWriteASecondRow(): void {
		$recorder = $this->recorder();

		$recorder->around(
			'Acme\\NightlyJob',
			static function () use ($recorder): void {
				$recorder->around('Acme\\NightlyJob', static fn (): bool => true);
			},
			JobRun::CAUSE_MANUAL,
			'fatima'
		);

		$this->assertCount(1, $this->inserted);
		$this->assertSame('fatima', $this->inserted[0]->getActor());
		$this->assertSame(JobRun::CAUSE_MANUAL, $this->inserted[0]->getCause());
	}

	/**
	 * The row is open BEFORE the work runs, so "what is running now" is a
	 * question the log can answer.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testTheRowExistsWhileTheWorkIsStillRunning(): void {
		$outcomeDuringWork = null;

		$this->recorder()->around(
			'Acme\\NightlyJob',
			function () use (&$outcomeDuringWork): void {
				$outcomeDuringWork = $this->inserted[0]->getOutcome();
			}
		);

		$this->assertSame(JobRun::OUTCOME_RUNNING, $outcomeDuringWork);
	}

	/**
	 * A separate act is one completed row naming the actor and what it touched.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testAnActIsRecordedWithItsActorAndItsObjects(): void {
		$row = $this->recorder()->recordAct(
			'OperationsConsole::repair',
			'noor',
			['check' => 'orphan-relations', 'objects' => [['id' => 7]]],
			'Repaired 1 row(s).'
		);

		$this->assertNotNull($row);
		$this->assertSame('noor', $row->getActor());
		$this->assertSame(JobRun::CAUSE_MANUAL, $row->getCause());
		$this->assertSame(JobRun::OUTCOME_COMPLETED, $row->getOutcome());
		$this->assertSame('orphan-relations', $row->jsonSerialize()['details']['check']);
	}
}
