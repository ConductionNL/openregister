<?php

/**
 * Evaluates the task rule set's SCHEDULED rules over open tasks.
 *
 * ScheduledNotificationJob sweeps register objects per stored schema; a task
 * is not an object, so it has this sweep instead. Everything else is shared:
 * the same operator-object filter grammar (ScheduledFilterEvaluator), the
 * same per-(rule, task) dedupe state, and the same dispatcher. The candidate
 * set comes from the inbox query with the derived-overdue filter (the ONE
 * predicate the inbox and the API also use, handed the job's clock instant);
 * the rule's declared filter is then evaluated over the adapter payload, so
 * no rule filters a stored `overdue` because no such field exists.
 *
 * The task row is never written by this job: a task becomes notifiable by
 * the clock alone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use OCA\OpenRegister\Db\ListQuery;
use DateTime;
use OCA\OpenRegister\Notifications\Db\DedupeSighting;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeKey;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxDueWindow;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxOrder;
use OCA\OpenRegister\Db\TaskInboxSelection;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterTarget;
use OCA\OpenRegister\Notifications\Service\Notification\TaskObjectAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Throwable;

/**
 * The task-side scheduled sweep.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The sweep joins the rule
 * registry, the inbox query, the filter evaluator, the dedupe state and the
 * dispatcher; each is the platform's one implementation of that concern.
 * @SuppressWarnings(PHPMD.StaticAccess) DateTime conversions between the
 * mutable clock the inbox takes and the immutable one the evaluator takes.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */
class TaskScheduledNotificationJob extends TimedJob {

	/**
	 * Page size of the candidate sweep.
	 */
	private const PAGE = 200;

	/**
	 * Upper bound on tasks inspected per run.
	 */
	private const MAX_PER_RUN = 5000;

	/**
	 * The synthetic schema id the dedupe rows are keyed under. Tasks have no
	 * stored schema; this constant is the registry's stand-in.
	 */
	public const DEDUPE_SCHEMA_ID = -1;

	/**
	 * The uid the sweep queries as: an administrator's view, which the inbox
	 * does not narrow. It is never a recipient.
	 */
	private const SWEEP_UID = '__openregister_task_sweep__';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory               $time       The clock.
	 * @param TaskNotificationCandidates $candidates The rules, the candidate query, the inbox row and the filter evaluator.
	 * @param TaskNotificationDelivery   $delivery   The dedupe state, the dispatcher, the app config and the logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TaskNotificationCandidates $candidates,
		private readonly TaskNotificationDelivery $delivery,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Fire every due scheduled task rule.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	protected function run($argument): void {
		$now = $this->time->getTime();

		foreach ($this->candidates->rules->getRules() as $name => $rule) {
			$trigger = $this->scheduledTrigger(rule: $rule);
			if ($trigger === null) {
				continue;
			}

			$interval = max(60, (int)($trigger['intervalSec'] ?? 86400));
			if (($now - $this->lastFiredAt(name: (string)$name)) < $interval) {
				continue;
			}

			$this->fire(name: (string)$name, rule: $rule);
			$this->delivery->appConfig->setValueString('openregister', $this->stateKey(name: (string)$name), (string)$now);
		}//end foreach
	}//end run()

	/**
	 * The trigger block of an enabled scheduled rule, or null for any other rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return array<string, mixed>|null The trigger block.
	 */
	private function scheduledTrigger(array $rule): ?array {
		$trigger = ($rule['trigger'] ?? null);
		if (is_array($trigger) === false || (string)($trigger['type'] ?? '') !== 'scheduled') {
			return null;
		}

		if (($rule['enabled'] ?? true) === false) {
			return null;
		}

		return $trigger;
	}//end scheduledTrigger()

	/**
	 * Evaluate one scheduled rule over the open, past-due tasks.
	 *
	 * The rule's trigger block was already checked by scheduledTrigger().
	 *
	 * @param string $name The rule name.
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	private function fire(string $name, array $rule): void {
		$trigger = (array)$rule['trigger'];
		// One clock instant for the whole sweep: the candidate query's
		// derived-overdue filter and the rule's declared filter read the same
		// "now", so the two derivations cannot disagree within a run.
		$run = new TaskSweepRun(
			sweep: new TaskSweep(
				name: $name,
				filter: (array)($trigger['filter'] ?? []),
				watched: $this->watchedFields(trigger: $trigger),
				schema: $this->schemaFor(name: $name, rule: $rule)
			),
			now: $this->time->getDateTime()
		);

		$this->sweepCandidates(run: $run);

		$this->delivery->logger->info(
			sprintf('[TaskScheduledNotificationJob] Rule "%s": matched=%d dispatched=%d', $name, $run->matched, $run->dispatched)
		);
	}//end fire()

	/**
	 * Page through the candidates and evaluate each, up to MAX_PER_RUN.
	 *
	 * @param TaskSweepRun $run The sweep; its tally is updated.
	 *
	 * @return void
	 */
	private function sweepCandidates(TaskSweepRun $run): void {
		$offset = 0;
		while ($offset < self::MAX_PER_RUN) {
			$page = $this->candidates(now: $run->now, offset: $offset);
			if ($page === []) {
				break;
			}

			$offset += count($page);
			foreach ($page as $task) {
				$this->fireTask(task: $task, run: $run);
			}

			if (count($page) < self::PAGE) {
				break;
			}
		}
	}//end sweepCandidates()

	/**
	 * Evaluate one candidate task: filter, dedupe, then dispatch.
	 *
	 * @param Task         $task The candidate task.
	 * @param TaskSweepRun $run  The sweep; its tally is updated.
	 *
	 * @return void
	 */
	private function fireTask(Task $task, TaskSweepRun $run): void {
		$adapter = new TaskObjectAdapter(task: $task, row: $this->candidates->inbox->enrich(task: $task));
		$payload = ($adapter->getObject() ?? []);

		$target = new ScheduledFilterTarget(objectData: $payload, now: $run->nowDt);
		if ($this->candidates->filters->matchesTarget(filter: $run->sweep->filter, target: $target) === false) {
			return;
		}

		$run->matched++;
		$fingerprint = $this->fingerprint(payload: $payload, watched: $run->sweep->watched);
		$existing = $this->delivery->dedupe->findByKey(
			key: new NotificationDedupeKey(schemaId: self::DEDUPE_SCHEMA_ID, ruleKey: $run->sweep->name, objectUuid: (string)$task->getUuid())
		);
		if ($existing !== null && (string)$existing->getFingerprint() === $fingerprint) {
			return;
		}

		$run->dispatched += $this->dispatchTask(adapter: $adapter, run: $run);
	}//end fireTask()

	/**
	 * Dispatch one task and record its fingerprint; a failure is logged, not raised.
	 *
	 * @param TaskObjectAdapter $adapter The task as a notifiable object.
	 * @param TaskSweepRun      $run     The sweep.
	 *
	 * @return int 1 when dispatched, 0 when the dispatch failed.
	 */
	private function dispatchTask(TaskObjectAdapter $adapter, TaskSweepRun $run): int {
		// The adapter carries the task uuid as its own.
		$uuid = (string)$adapter->getUuid();
		$name = $run->sweep->name;

		try {
			$this->delivery->dispatcher->dispatchWithSchema(
				object: $adapter,
				trigger: 'scheduled',
				context: ['notificationName' => $name],
				schema: $run->sweep->schema
			);
			$this->delivery->dedupe->upsert(
				key: new NotificationDedupeKey(schemaId: self::DEDUPE_SCHEMA_ID, ruleKey: $name, objectUuid: $uuid),
				sighting: new DedupeSighting(
					fingerprint: $this->fingerprint(payload: ($adapter->getObject() ?? []), watched: $run->sweep->watched),
					now: $run->now,
					dispatched: true
				)
			);
			return 1;
		} catch (Throwable $failure) {
			$this->delivery->logger->warning(
				sprintf('[TaskScheduledNotificationJob] Rule "%s" failed for task %s: %s', $name, $uuid, $failure->getMessage())
			);
			return 0;
		}
	}//end dispatchTask()

	/**
	 * The candidate page: open tasks whose deadline lies before now, through
	 * the inbox query's derived-overdue filter.
	 *
	 * @param DateTime $now The clock instant.
	 * @param int $offset Page offset.
	 *
	 * @return array<int, Task> The page.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	private function candidates(DateTime $now, int $offset): array {
		$criteria = new TaskInboxCriteria(
			audience: new TaskInboxAudience(uid: self::SWEEP_UID, isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL),
			selection: new TaskInboxSelection(
				filter: new TaskInboxFilter(isTerminal: false),
				window: new TaskInboxDueWindow(overdueAt: $now),
				order: new TaskInboxOrder(sort: TaskInboxCriteria::SORT_CREATED)
			)
		);

		try {
			return $this->candidates->tasks->findInbox(criteria: $criteria, page: new ListQuery(limit: self::PAGE, offset: $offset));
		} catch (Throwable $failure) {
			$this->delivery->logger->warning('[TaskScheduledNotificationJob] Candidate query failed: ' . $failure->getMessage());

			return [];
		}
	}//end candidates()

	/**
	 * A synthetic schema carrying ONLY the named rule, so the dispatcher's
	 * `scheduled` trigger fires exactly that rule.
	 *
	 * @param string $name The rule name.
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return Schema The one-rule schema.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	private function schemaFor(string $name, array $rule): Schema {
		$schema = $this->candidates->rules->buildSchema();
		$schema->setConfiguration(['x-openregister-notifications' => [$name => $rule]]);

		return $schema;
	}//end schemaFor()

	/**
	 * When a rule last fired, as a Unix timestamp; 0 when it never did.
	 *
	 * @param string $name The rule name.
	 *
	 * @return int The last fire time.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	private function lastFiredAt(string $name): int {
		return (int)$this->delivery->appConfig->getValueString('openregister', $this->stateKey(name: $name), '0');
	}//end lastFiredAt()

	/**
	 * The app-config key holding a rule's last fire time.
	 *
	 * @param string $name The rule name.
	 *
	 * @return string The key.
	 */
	private function stateKey(string $name): string {
		return 'sched_task:' . $name;
	}//end stateKey()

	/**
	 * The payload fields whose values re-arm the rule when they change.
	 *
	 * @param array<string, mixed> $trigger The trigger block.
	 *
	 * @return array<int, string> Sorted field names; empty means fire once per task.
	 */
	private function watchedFields(array $trigger): array {
		$fields = [];
		foreach ((array)($trigger['dedupeFields'] ?? []) as $field) {
			if (is_string($field) === true && $field !== '') {
				$fields[] = $field;
			}
		}

		$fields = array_values(array_unique($fields));
		sort($fields);

		return $fields;
	}//end watchedFields()

	/**
	 * The dedupe fingerprint over the watched fields.
	 *
	 * @param array<string, mixed> $payload The adapter payload.
	 * @param array<int, string> $watched The watched field names.
	 *
	 * @return string SHA-1 of the watched values.
	 */
	private function fingerprint(array $payload, array $watched): string {
		$values = [];
		foreach ($watched as $field) {
			$values[$field] = ($payload[$field] ?? null);
		}

		return sha1((string)json_encode($values));
	}//end fingerprint()
}//end class
