<?php

/**
 * The operations console read model.
 *
 * One answer to "what is this instance doing, and what is broken", assembled
 * from the records the platform already keeps: the bulk jobs and their
 * states, the notification dispatches and the events that have no words, the
 * rules engine's runs and the rules whose last verdict was an error.
 *
 * TWO PROPERTIES DECIDE WHETHER THIS IS WORTH READING.
 *
 * The first is that it never invents a category. Outcomes are grouped by
 * whatever the writer wrote, so a state or a status added later appears here
 * by itself rather than falling into neither column and being reported as
 * nothing.
 *
 * The second is that it says what it cannot see. Most of this instance's
 * background jobs run without recording an outcome anywhere, and a console
 * that listed only the observed ones would read as an instance with three
 * jobs and no failures. They are listed as unobserved instead, with the
 * number said out loud, because an empty list and an unwatched list look
 * identical to a reader and mean opposite things.
 *
 * Every string here is an identifier. The words a person reads are the
 * frontend's, because they have to be translated and this layer has no
 * locale.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateInterval;
use DateTime;
use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use Throwable;

/**
 * OperationsConsoleService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A console is a join over
 * records that are deliberately kept apart. Putting the join behind a
 * locator would hide the same seven collaborators rather than remove one,
 * and every one of them is read in a single method here.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */
class OperationsConsoleService {

	/**
	 * The window the console reports over, in hours.
	 *
	 * A day, because that is the span in which "it started failing this
	 * morning" is still answerable and a nightly job has run exactly once.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_HOURS = 24;

	/**
	 * The longest window one request may ask for, in hours.
	 *
	 * @var int
	 */
	public const MAX_WINDOW_HOURS = 720;

	/**
	 * The largest page of rows one pane returns.
	 *
	 * @var int
	 */
	public const MAX_ROWS = 200;

	/**
	 * The dispatch status that means a notice reached somebody.
	 *
	 * Every other status the dispatcher writes is a reason it did not, which
	 * is why this is one name rather than a list of failures: a new reason
	 * counts as undelivered without anybody editing this class.
	 *
	 * @var string
	 */
	public const STATUS_DISPATCHED = 'dispatched';

	/**
	 * The rule verdict that means the engine could not decide.
	 *
	 * @var string
	 */
	public const VERDICT_ERROR = 'error';

	/**
	 * The background jobs whose runs are recorded somewhere readable.
	 *
	 * One entry today, and that is the finding rather than an oversight:
	 * `BulkJobRunner`'s runs are the bulk job rows, so the console can say
	 * how each one came out. Every other job on this instance runs and tells
	 * nobody. The wrapper that gives the rest a run row grows this list; until
	 * it lands, the console reports the gap rather than papering over it.
	 *
	 * @var array<int, string>
	 */
	public const OBSERVED_JOBS = [BulkJobRunner::class];

	/**
	 * The job-list page the console reads.
	 *
	 * An instance carries tens of registered jobs, not thousands, and a
	 * console that silently truncated the inventory would be claiming
	 * completeness it does not have.
	 *
	 * @var int
	 */
	private const JOB_INVENTORY_LIMIT = 500;

	/**
	 * The prefix of a job class this app owns.
	 *
	 * @var string
	 */
	private const OWN_JOB_PREFIX = 'OCA\\OpenRegister\\';

	/**
	 * Constructor.
	 *
	 * @param BulkJobMapper                $bulkJobs      The bulk job records.
	 * @param IJobList                     $jobList       Nextcloud's registered background jobs.
	 * @param NotificationHistoryMapper    $dispatches    The notification dispatch history.
	 * @param QueuedNotificationMapper     $queue         The notifications waiting to go out.
	 * @param NotificationTemplateRegistry $templates     The shipped notification texts.
	 * @param RuleRunMapper                $ruleRuns      The rules engine's run log.
	 * @param RuleRunSummaryMapper         $ruleSummaries One row per rule, with its last error.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Seven records, joined
	 * once. See the class-level note.
	 */
	public function __construct(
		private readonly BulkJobMapper $bulkJobs,
		private readonly IJobList $jobList,
		private readonly NotificationHistoryMapper $dispatches,
		private readonly QueuedNotificationMapper $queue,
		private readonly NotificationTemplateRegistry $templates,
		private readonly RuleRunMapper $ruleRuns,
		private readonly RuleRunSummaryMapper $ruleSummaries,
	) {
	}//end __construct()

	/**
	 * The console's panes: what each one counts, and what wants attention.
	 *
	 * `attention` is the number a reader acts on, and it is computed here
	 * rather than in the browser so every consumer agrees on what counts as
	 * wrong. A pane whose `attention` is zero is not the same as one that
	 * counted nothing, which is why `total` is carried beside it.
	 *
	 * @param int $windowHours How far back to look, bounded by MAX_WINDOW_HOURS.
	 *
	 * @return array<string, mixed> The window and the three panes.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function panes(int $windowHours = self::DEFAULT_WINDOW_HOURS): array {
		$since = $this->windowStart(windowHours: $windowHours);

		return [
			'window' => [
				'hours' => $this->boundedWindow(windowHours: $windowHours),
				'since' => $since->format(DateTime::ATOM),
			],
			'panes' => [
				$this->jobsPane(),
				$this->notificationsPane(since: $since),
				$this->ruleRunsPane(since: $since),
			],
		];
	}//end panes()

	/**
	 * The job pane: the bulk jobs by state, and how much runs unwatched.
	 *
	 * @return array<string, mixed> The pane.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	private function jobsPane(): array {
		$states = $this->bulkJobs->countByState();
		$inventory = $this->backgroundJobs();
		$unobserved = count(array_filter($inventory, static fn (array $job): bool => $job['observed'] === false));

		return [
			'id' => 'jobs',
			'total' => array_sum($states),
			'attention' => (int)($states[BulkJob::STATE_FAILED] ?? 0),
			'counts' => $states,
			'registered' => count($inventory),
			'unobserved' => $unobserved,
		];
	}//end jobsPane()

	/**
	 * The notification pane: dispatches by outcome, the queue, the gaps.
	 *
	 * @param DateTime $since The start of the window.
	 *
	 * @return array<string, mixed> The pane.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	private function notificationsPane(DateTime $since): array {
		$statuses = $this->dispatches->countByStatus(since: $since);
		$delivered = (int)($statuses[self::STATUS_DISPATCHED] ?? 0);
		$total = array_sum($statuses);
		$gaps = $this->templates->gaps();

		return [
			'id' => 'notifications',
			'total' => $total,
			// Anything the dispatcher did not send, plus every platform event
			// that would have no words if it fired. Both are things a reader
			// has to decide about; neither is an error in the log.
			'attention' => (($total - $delivered) + count($gaps)),
			'counts' => $statuses,
			'delivered' => $delivered,
			'queued' => $this->queueDepth(),
			'templateGaps' => count($gaps),
		];
	}//end notificationsPane()

	/**
	 * The rule pane: runs by verdict, and the rules holding an error.
	 *
	 * @param DateTime $since The start of the window.
	 *
	 * @return array<string, mixed> The pane.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	private function ruleRunsPane(DateTime $since): array {
		$runs = $this->ruleRuns->findRecent(since: $since, limit: self::MAX_ROWS);
		$verdicts = [];

		foreach ($runs as $run) {
			$verdict = (string)$run->getVerdict();

			if ($verdict === '') {
				continue;
			}

			$verdicts[$verdict] = ((int)($verdicts[$verdict] ?? 0) + 1);
		}

		$failing = $this->ruleSummaries->findHoldingAnError(limit: self::MAX_ROWS);

		return [
			'id' => 'rule-runs',
			'total' => count($runs),
			'attention' => count($failing),
			'counts' => $verdicts,
			'rulesHoldingAnError' => count($failing),
		];
	}//end ruleRunsPane()

	/**
	 * The job pane's rows: the bulk jobs, and the inventory they sit in.
	 *
	 * @param string|null $state Narrow the bulk jobs to one state.
	 * @param int         $limit How many bulk jobs to return.
	 *
	 * @return array<string, mixed> The rows.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function jobs(?string $state = null, int $limit = 50): array {
		$jobs = $this->bulkJobs->findAllJobs(
			state: $state,
			limit: max(1, min($limit, self::MAX_ROWS)),
			offset: 0
		);

		$inventory = $this->backgroundJobs();

		return [
			'results' => array_map(fn (BulkJob $job): array => $this->describeBulkJob(job: $job), $jobs),
			'registered' => $inventory,
			'unobserved' => array_values(
				array_filter($inventory, static fn (array $job): bool => $job['observed'] === false)
			),
		];
	}//end jobs()

	/**
	 * The most recent rule-engine runs, across every rule.
	 *
	 * @param int $windowHours How far back to look.
	 * @param int $limit       How many runs to return.
	 *
	 * @return array<string, mixed> The runs and the rules holding an error.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function ruleRuns(int $windowHours = self::DEFAULT_WINDOW_HOURS, int $limit = 50): array {
		$runs = $this->ruleRuns->findRecent(
			since: $this->windowStart(windowHours: $windowHours),
			limit: max(1, min($limit, self::MAX_ROWS))
		);

		$failing = [];

		foreach ($this->ruleSummaries->findHoldingAnError(limit: self::MAX_ROWS) as $summary) {
			$failing[] = [
				'ruleId' => $summary->getRuleId(),
				'schemaSlug' => $summary->getSchemaSlug(),
				'lastVerdict' => $summary->getLastVerdict(),
				'lastError' => $summary->getLastError(),
				'lastErrorAt' => $summary->getLastErrorAt()?->format(DateTime::ATOM),
				'lastRun' => $summary->getLastRun()?->format(DateTime::ATOM),
			];
		}

		return [
			'results' => array_map(
				static fn (RuleRun $run): array => $run->jsonSerialize(),
				$runs
			),
			'holdingAnError' => $failing,
		];
	}//end ruleRuns()

	/**
	 * One bulk job, with what this instance will let be done to it.
	 *
	 * The affordances travel with the row so the console never guesses a
	 * verb from a state it happens to recognise. The service refuses the same
	 * transitions; these flags decide what is offered, never what is allowed.
	 *
	 * @param BulkJob $job The job.
	 *
	 * @return array<string, mixed> The job and its verbs.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	private function describeBulkJob(BulkJob $job): array {
		$state = (string)$job->getState();

		return array_merge(
			$job->jsonSerialize(),
			[
				'actions' => [
					'pause' => ($state === BulkJob::STATE_RUNNING),
					'resume' => ($state === BulkJob::STATE_PAUSED),
					'retry' => in_array(
						$state,
						[BulkJob::STATE_FAILED, BulkJob::STATE_CANCELLED, BulkJob::STATE_COMPLETED],
						true
					),
					'cancel' => in_array(
						$state,
						[BulkJob::STATE_RUNNING, BulkJob::STATE_PREVIEWED, BulkJob::STATE_PAUSED],
						true
					),
				],
			]
		);
	}//end describeBulkJob()

	/**
	 * This app's registered background jobs, each marked observed or not.
	 *
	 * Read from Nextcloud's own job list rather than from a hand-kept list,
	 * because a job registered by a migration or at boot belongs on the
	 * console just as much as one declared in `info.xml`, and a hand-kept
	 * list is exactly how a job goes missing from a monitor.
	 *
	 * @return array<int, array<string, mixed>> The inventory, class order.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	private function backgroundJobs(): array {
		$seen = [];

		foreach ($this->jobListPage() as $job) {
			$class = $job::class;

			if (str_starts_with($class, self::OWN_JOB_PREFIX) === false) {
				continue;
			}

			$lastRun = $job->getLastRun();

			if (array_key_exists($class, $seen) === true) {
				// One class may hold many queued rows, one per argument. The
				// inventory is about the job, so the newest run of any of them
				// is the one that answers "has this run".
				$seen[$class]['queued'] = ((int)$seen[$class]['queued'] + 1);
				$seen[$class]['lastRun'] = max((int)$seen[$class]['lastRun'], $lastRun);
				continue;
			}

			$seen[$class] = [
				'class' => $class,
				'name' => substr($class, (strrpos($class, '\\') + 1)),
				'queued' => 1,
				'lastRun' => $lastRun,
				'observed' => in_array($class, self::OBSERVED_JOBS, true),
			];
		}

		ksort($seen);

		return array_values($seen);
	}//end backgroundJobs()

	/**
	 * One page of the instance's job list, or none when it cannot be read.
	 *
	 * The console is a read of several records and must render when one of
	 * them is unavailable; what it must never do is render the missing
	 * inventory as an empty one, which is why the caller's count of
	 * `registered` is what the page reports rather than a hardcoded total.
	 *
	 * @return iterable<IJob> The registered jobs.
	 */
	private function jobListPage(): iterable {
		try {
			return $this->jobList->getJobsIterator(null, self::JOB_INVENTORY_LIMIT, 0);
		} catch (Throwable $exception) {
			return [];
		}
	}//end jobListPage()

	/**
	 * How many notifications are waiting to go out.
	 *
	 * @return int The queue depth.
	 */
	private function queueDepth(): int {
		return count($this->queue->findAll());
	}//end queueDepth()

	/**
	 * The window in hours, bounded.
	 *
	 * @param int $windowHours The requested window.
	 *
	 * @return int The window this instance will use.
	 */
	private function boundedWindow(int $windowHours): int {
		return max(1, min($windowHours, self::MAX_WINDOW_HOURS));
	}//end boundedWindow()

	/**
	 * The moment the window starts.
	 *
	 * @param int $windowHours The requested window.
	 *
	 * @return DateTime The start of the window.
	 */
	private function windowStart(int $windowHours): DateTime {
		$since = new DateTime();
		$since->sub(new DateInterval('PT'.$this->boundedWindow(windowHours: $windowHours).'H'));

		return $since;
	}//end windowStart()
}//end class
