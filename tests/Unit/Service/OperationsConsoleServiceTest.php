<?php

/**
 * Unit tests for OperationsConsoleService — what the console can and cannot see.
 *
 * The two properties worth asserting are the ones a reader's conclusions rest
 * on. A console that groups outcomes by whatever the writer wrote reports a
 * state nobody taught it about; one that counts a fixed list drops it. And a
 * console that lists only the jobs whose runs are recorded shows an instance
 * with no failures, which is indistinguishable from an instance that is fine.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\BackgroundJob\NotificationQueueFlushJob;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\OperationsConsoleService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

final class OperationsConsoleServiceTest extends TestCase {

	/**
	 * The bulk job records.
	 *
	 * @var BulkJobMapper
	 */
	private BulkJobMapper $bulkJobs;

	/**
	 * Nextcloud's registered background jobs.
	 *
	 * @var IJobList
	 */
	private IJobList $jobList;

	/**
	 * The notification dispatch history.
	 *
	 * @var NotificationHistoryMapper
	 */
	private NotificationHistoryMapper $dispatches;

	/**
	 * The notifications waiting to go out.
	 *
	 * @var QueuedNotificationMapper
	 */
	private QueuedNotificationMapper $queue;

	/**
	 * The shipped notification texts.
	 *
	 * @var NotificationTemplateRegistry
	 */
	private NotificationTemplateRegistry $templates;

	/**
	 * The rules engine's run log.
	 *
	 * @var RuleRunMapper
	 */
	private RuleRunMapper $ruleRuns;

	/**
	 * One row per rule, with its last error.
	 *
	 * @var RuleRunSummaryMapper
	 */
	private RuleRunSummaryMapper $ruleSummaries;

	protected function setUp(): void {
		parent::setUp();

		$this->bulkJobs = $this->createMock(BulkJobMapper::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->dispatches = $this->createMock(NotificationHistoryMapper::class);
		$this->queue = $this->createMock(QueuedNotificationMapper::class);
		$this->templates = $this->createMock(NotificationTemplateRegistry::class);
		$this->ruleRuns = $this->createMock(RuleRunMapper::class);
		$this->ruleSummaries = $this->createMock(RuleRunSummaryMapper::class);

		// The empty instance, so each test states only what it is about.
		$this->bulkJobs->method('countByState')->willReturn([]);
		$this->bulkJobs->method('findAllJobs')->willReturn([]);
		$this->jobList->method('getJobsIterator')->willReturn([]);
		$this->dispatches->method('countByStatus')->willReturn([]);
		$this->queue->method('findAll')->willReturn([]);
		$this->templates->method('gaps')->willReturn([]);
		$this->ruleRuns->method('findRecent')->willReturn([]);
		$this->ruleSummaries->method('findHoldingAnError')->willReturn([]);
	}

	private function service(): OperationsConsoleService {
		return new OperationsConsoleService(
			$this->bulkJobs,
			$this->jobList,
			$this->dispatches,
			$this->queue,
			$this->templates,
			$this->ruleRuns,
			$this->ruleSummaries
		);
	}

	/**
	 * @param array<string, mixed> $panes The console's panes.
	 * @param string               $id    The pane wanted.
	 *
	 * @return array<string, mixed> That pane.
	 */
	private function pane(array $panes, string $id): array {
		foreach ($panes['panes'] as $pane) {
			if ($pane['id'] === $id) {
				return $pane;
			}
		}

		$this->fail('The console reported no "'.$id.'" pane.');
	}

	private function ruleRun(string $verdict): RuleRun {
		$run = new RuleRun();
		$run->setId(1);
		$run->setRuleId('rule-a');
		$run->setSchemaSlug('zaak');
		$run->setVerdict($verdict);
		$run->setCreated(new DateTime());

		return $run;
	}

	private function summary(string $error): RuleRunSummary {
		$summary = new RuleRunSummary();
		$summary->setId(1);
		$summary->setRuleId('rule-a');
		$summary->setSchemaSlug('zaak');
		$summary->setLastRun(new DateTime());
		$summary->setLastVerdict('allow');
		$summary->setLastError($error);

		return $summary;
	}

	public function testTheJobPaneReportsEveryStateTheInstanceHasReachedAndInventsNone(): void {
		$this->bulkJobs = $this->createMock(BulkJobMapper::class);
		$this->bulkJobs->method('countByState')->willReturn(
			[
				BulkJob::STATE_RUNNING => 2,
				BulkJob::STATE_FAILED => 3,
				// A state this class knows nothing about. It must still be
				// counted, because the alternative is a total that does not
				// add up and a category nobody can see.
				'quarantined' => 1,
			]
		);
		$this->bulkJobs->method('findAllJobs')->willReturn([]);

		$pane = $this->pane($this->service()->panes(), 'jobs');

		$this->assertSame(6, $pane['total']);
		$this->assertSame(3, $pane['attention'], 'The failed jobs are what a reader acts on.');
		$this->assertSame(1, $pane['counts']['quarantined']);
		$this->assertArrayNotHasKey(
			BulkJob::STATE_COMPLETED,
			$pane['counts'],
			'A state nothing has reached is absent, not zero: zero claims the instance measured it.'
		);
	}

	public function testAJobWhoseRunsAreRecordedNowhereIsListedAsUnobserved(): void {
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturn(
			[
				$this->realJob(BulkJobRunner::class),
				$this->realJob(NotificationQueueFlushJob::class),
			]
		);

		$jobs = $this->service()->jobs();
		$names = array_column($jobs['registered'], 'name');

		$this->assertContains('BulkJobRunner', $names);
		$this->assertContains('NotificationQueueFlushJob', $names);

		$observed = array_column($jobs['registered'], 'observed', 'name');
		$this->assertTrue($observed['BulkJobRunner'], 'Its runs are the bulk job rows.');
		$this->assertFalse($observed['NotificationQueueFlushJob'], 'Nothing records how this one came out.');

		$this->assertSame(
			['NotificationQueueFlushJob'],
			array_column($jobs['unobserved'], 'name'),
			'An unobserved job is named rather than left out, so an empty failure list cannot be read as a healthy one.'
		);
	}

	public function testTheNotificationPaneCountsEveryUndeliveredOutcomeAndTheEventsWithNoWords(): void {
		$this->dispatches = $this->createMock(NotificationHistoryMapper::class);
		$this->dispatches->method('countByStatus')->willReturn(
			[
				'dispatched' => 10,
				'rate-limited' => 2,
				// A reason invented after this class was written.
				'transport-refused' => 1,
			]
		);
		$this->templates = $this->createMock(NotificationTemplateRegistry::class);
		$this->templates->method('gaps')->willReturn(['object.merged', 'object.split']);

		$pane = $this->pane($this->service()->panes(), 'notifications');

		$this->assertSame(13, $pane['total']);
		$this->assertSame(10, $pane['delivered']);
		$this->assertSame(
			5,
			$pane['attention'],
			'Three undelivered, whatever the reason was called, plus two events that would fire with no words.'
		);
		$this->assertSame(2, $pane['templateGaps']);
	}

	public function testTheRulePaneCountsVerdictsAndTheRulesHoldingAnError(): void {
		$this->ruleRuns = $this->createMock(RuleRunMapper::class);
		$this->ruleRuns->method('findRecent')->willReturn(
			[$this->ruleRun('allow'), $this->ruleRun('allow'), $this->ruleRun('error')]
		);
		$this->ruleSummaries = $this->createMock(RuleRunSummaryMapper::class);
		// The narrowing is the mapper's `WHERE last_error IS NOT NULL`, not a
		// filter here: ordering every rule by last_error_at and filtering after
		// puts NULLs first on Postgres and pushes the errored rules off the
		// page. So the double returns what that query returns, and this asserts
		// the service counts it rather than re-deciding it.
		$this->ruleSummaries->method('findHoldingAnError')->willReturn(
			[$this->summary('Property "zaaktype" is not on the schema')]
		);

		$pane = $this->pane($this->service()->panes(), 'rule-runs');

		$this->assertSame(3, $pane['total']);
		$this->assertSame(2, $pane['counts']['allow']);
		$this->assertSame(1, $pane['counts']['error']);
		$this->assertSame(1, $pane['attention'], 'One rule is holding an error.');
	}

	public function testTheRuleRunListingNamesTheRuleAndItsLastError(): void {
		$this->ruleSummaries = $this->createMock(RuleRunSummaryMapper::class);
		$this->ruleSummaries->method('findHoldingAnError')->willReturn(
			[$this->summary('Property "zaaktype" is not on the schema')]
		);

		$holding = $this->service()->ruleRuns()['holdingAnError'];

		$this->assertCount(1, $holding);
		$this->assertSame('rule-a', $holding[0]['ruleId']);
		$this->assertSame('Property "zaaktype" is not on the schema', $holding[0]['lastError']);
	}

	public function testEachJobRowCarriesTheVerbsItsStateAllows(): void {
		$this->bulkJobs = $this->createMock(BulkJobMapper::class);
		$this->bulkJobs->method('countByState')->willReturn([]);
		$this->bulkJobs->method('findAllJobs')->willReturn(
			[$this->bulkJob(BulkJob::STATE_RUNNING), $this->bulkJob(BulkJob::STATE_PAUSED), $this->bulkJob(BulkJob::STATE_FAILED)]
		);

		$actions = array_column($this->service()->jobs()['results'], 'actions');

		$this->assertTrue($actions[0]['pause'], 'A running job can be held.');
		$this->assertFalse($actions[0]['resume']);
		$this->assertTrue($actions[1]['resume'], 'A paused job can be set going again.');
		$this->assertFalse($actions[1]['pause']);
		$this->assertTrue($actions[1]['cancel'], 'A paused job can still be given up on.');
		$this->assertTrue($actions[2]['retry'], 'A failed job can be retried.');
		$this->assertFalse($actions[2]['pause']);
	}

	public function testAWindowLongerThanTheCeilingIsBoundedRatherThanHonoured(): void {
		$panes = $this->service()->panes(windowHours: 99999);

		$this->assertSame(OperationsConsoleService::MAX_WINDOW_HOURS, $panes['window']['hours']);
	}

	public function testAJobListThatCannotBeReadLeavesTheConsoleRenderable(): void {
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willThrowException(new \RuntimeException('no such table'));

		$pane = $this->pane($this->service()->panes(), 'jobs');

		$this->assertSame(0, $pane['registered'], 'Nothing was read, and the pane says so rather than failing the page.');
		$this->assertSame(0, $pane['unobserved']);
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

	/**
	 * A real background job of the named class, built from doubles.
	 *
	 * The inventory is keyed on the class of the object the job list yields,
	 * so a double of `IJob` would be reported under PHPUnit's generated class
	 * name and this test would assert nothing about the real one. Building the
	 * genuine job with mocked collaborators is what keeps the assertion about
	 * OpenRegister's jobs rather than about the test's own fixtures.
	 *
	 * @param class-string<IJob> $class The job class.
	 *
	 * @return IJob The job.
	 */
	private function realJob(string $class): IJob {
		$constructor = (new \ReflectionClass($class))->getConstructor();
		$arguments = [];

		foreach ($constructor->getParameters() as $parameter) {
			$arguments[] = $this->createMock((string)$parameter->getType()?->getName());
		}

		return new $class(...$arguments);
	}
}
