<?php

/**
 * Golden test pinning the exact rows OperationsConsoleService builds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Notifications\BackgroundJob\NotificationQueueFlushJob;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\OperationsConsoleJobs;
use OCA\OpenRegister\Service\OperationsConsoleNotifications;
use OCA\OpenRegister\Service\OperationsConsoleRules;
use OCA\OpenRegister\Service\OperationsConsoleService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

final class OperationsConsoleServiceGoldenTest extends TestCase {

	private function service(array $bulkJobs = [], array $jobRows = [], array $runs = [], array $summaries = []): OperationsConsoleService {
		$bulk = $this->createMock(BulkJobMapper::class);
		$bulk->method('countByState')->willReturn([]);
		$bulk->method('findAllJobs')->willReturn($bulkJobs);
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('getJobsIterator')->willReturn($jobRows);
		$dispatches = $this->createMock(NotificationHistoryMapper::class);
		$dispatches->method('countByStatus')->willReturn([]);
		$queue = $this->createMock(QueuedNotificationMapper::class);
		$queue->method('findAll')->willReturn([]);
		$templates = $this->createMock(NotificationTemplateRegistry::class);
		$templates->method('gaps')->willReturn([]);
		$ruleRuns = $this->createMock(RuleRunMapper::class);
		$ruleRuns->method('findRecent')->willReturn($runs);
		$ruleSummaries = $this->createMock(RuleRunSummaryMapper::class);
		$ruleSummaries->method('findHoldingAnError')->willReturn($summaries);

		return new OperationsConsoleService(
			new OperationsConsoleJobs($bulk, $jobList),
			new OperationsConsoleNotifications($dispatches, $queue, $templates),
			new OperationsConsoleRules($ruleRuns, $ruleSummaries)
		);
	}

	private function bulkJob(string $state): BulkJob {
		$job = new BulkJob();
		$job->setId(1);
		$job->setUuid('job-uuid');
		$job->setAction('openregister:assign');
		$job->setState($state);
		$job->setStartedBy('coordinator');

		return $job;
	}

	public function testEveryStateGetsExactlyItsVerbs(): void {
		$states = [
			BulkJob::STATE_PREVIEWED, BulkJob::STATE_RUNNING, BulkJob::STATE_PAUSED, BulkJob::STATE_CANCELLING,
			BulkJob::STATE_CANCELLED, BulkJob::STATE_COMPLETED, BulkJob::STATE_FAILED, 'invented',
		];
		$jobs = array_map(fn (string $state): BulkJob => $this->bulkJob($state), $states);

		$results = $this->service(bulkJobs: $jobs)->jobs()['results'];

		$this->assertSame(
			[
				['pause' => false, 'resume' => false, 'retry' => false, 'cancel' => true],
				['pause' => true, 'resume' => false, 'retry' => false, 'cancel' => true],
				['pause' => false, 'resume' => true, 'retry' => false, 'cancel' => true],
				['pause' => false, 'resume' => false, 'retry' => false, 'cancel' => false],
				['pause' => false, 'resume' => false, 'retry' => true, 'cancel' => false],
				['pause' => false, 'resume' => false, 'retry' => true, 'cancel' => false],
				['pause' => false, 'resume' => false, 'retry' => true, 'cancel' => false],
				['pause' => false, 'resume' => false, 'retry' => false, 'cancel' => false],
			],
			array_column($results, 'actions')
		);
		$this->assertSame(
			array_merge($jobs[1]->jsonSerialize(), ['actions' => $results[1]['actions']]),
			$results[1]
		);
	}

	public function testTheInventoryFoldsRowsOfOneClassAndSkipsForeignJobs(): void {
		$flushOld = $this->realJob(NotificationQueueFlushJob::class);
		$flushOld->setLastRun(9);
		$runnerA = $this->realJob(BulkJobRunner::class);
		$runnerA->setLastRun(5);
		$runnerB = $this->realJob(BulkJobRunner::class);
		$runnerB->setLastRun(12);
		$runnerC = $this->realJob(BulkJobRunner::class);
		$runnerC->setLastRun(7);
		$foreign = $this->createMock(IJob::class);
		$foreign->method('getLastRun')->willReturn(99);

		$jobs = $this->service(jobRows: [$flushOld, $runnerA, $foreign, $runnerB, $runnerC])->jobs();

		$this->assertSame(
			[
				['class' => BulkJobRunner::class, 'name' => 'BulkJobRunner', 'queued' => 3, 'lastRun' => 12, 'observed' => true],
				['class' => NotificationQueueFlushJob::class, 'name' => 'NotificationQueueFlushJob', 'queued' => 1, 'lastRun' => 9, 'observed' => false],
			],
			$jobs['registered']
		);
		$this->assertSame([$jobs['registered'][1]], $jobs['unobserved']);
	}

	public function testTheRuleRowsArePinned(): void {
		$at = new DateTime('2026-01-02T03:04:05+00:00');
		$runs = [];
		foreach (['allow', '', 'deny', 'allow'] as $verdict) {
			$run = new RuleRun();
			$run->setId(1);
			$run->setRuleId('rule-a');
			$run->setVerdict($verdict);
			$run->setCreated($at);
			$runs[] = $run;
		}

		$withError = new RuleRunSummary();
		$withError->setRuleId('rule-a');
		$withError->setSchemaSlug('zaak');
		$withError->setLastVerdict('deny');
		$withError->setLastError('boom');
		$withError->setLastErrorAt($at);
		$withError->setLastRun($at);
		$bare = new RuleRunSummary();
		$bare->setRuleId('rule-b');

		$service = $this->service(runs: $runs, summaries: [$withError, $bare]);
		$listing = $service->ruleRuns();

		$this->assertSame(
			[
				[
					'ruleId' => 'rule-a', 'schemaSlug' => 'zaak', 'lastVerdict' => 'deny', 'lastError' => 'boom',
					'lastErrorAt' => '2026-01-02T03:04:05+00:00', 'lastRun' => '2026-01-02T03:04:05+00:00',
				],
				[
					'ruleId' => 'rule-b', 'schemaSlug' => null, 'lastVerdict' => null, 'lastError' => null,
					'lastErrorAt' => null, 'lastRun' => null,
				],
			],
			$listing['holdingAnError']
		);
		$this->assertSame(array_map(static fn (RuleRun $run): array => $run->jsonSerialize(), $runs), $listing['results']);

		$pane = null;
		foreach ($service->panes()['panes'] as $candidate) {
			if ($candidate['id'] === 'rule-runs') {
				$pane = $candidate;
			}
		}

		$this->assertSame(
			['id' => 'rule-runs', 'total' => 4, 'attention' => 2, 'counts' => ['allow' => 2, 'deny' => 1], 'rulesHoldingAnError' => 2],
			$pane
		);
	}

	/**
	 * A real background job of the named class, built from doubles.
	 *
	 * @param class-string<IJob> $class The job class.
	 *
	 * @return IJob The job.
	 */
	private function realJob(string $class): IJob {
		$arguments = [];
		foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$arguments[] = $this->double((string)$parameter->getType()?->getName());
		}

		return new $class(...$arguments);
	}

	/**
	 * A double of one constructor argument; a final bundle is built from doubles of its own arguments.
	 *
	 * @param class-string $type The argument type.
	 *
	 * @return object The double.
	 */
	private function double(string $type): object {
		$reflection = new \ReflectionClass($type);
		if ($reflection->isFinal() === false) {
			return $this->createMock($type);
		}

		$arguments = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
			$arguments[] = $this->double((string)$parameter->getType()?->getName());
		}

		return $reflection->newInstanceArgs($arguments);
	}
}
