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

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
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
	 * @param ITimeFactory $time The clock.
	 * @param TaskNotificationRules $rules The task rule registry.
	 * @param TaskMapper $tasks The candidate query.
	 * @param TaskInboxService $inbox The row the adapter is built from.
	 * @param ScheduledFilterEvaluator $filters The operator-object filter evaluator.
	 * @param NotificationDedupeStateMapper $dedupe Per-(rule, task) fire state.
	 * @param AnnotationNotificationDispatcher $dispatcher The one dispatcher.
	 * @param IAppConfig $appConfig Last-fire timestamps per rule.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TaskNotificationRules $rules,
		private readonly TaskMapper $tasks,
		private readonly TaskInboxService $inbox,
		private readonly ScheduledFilterEvaluator $filters,
		private readonly NotificationDedupeStateMapper $dedupe,
		private readonly AnnotationNotificationDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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

		foreach ($this->rules->getRules() as $name => $rule) {
			$trigger = ($rule['trigger'] ?? null);
			if (is_array($trigger) === false || (string)($trigger['type'] ?? '') !== 'scheduled') {
				continue;
			}

			if (($rule['enabled'] ?? true) === false) {
				continue;
			}

			$interval = max(60, (int)($trigger['intervalSec'] ?? 86400));
			if ($this->isDue(name: (string)$name, intervalSec: $interval, now: $now) === false) {
				continue;
			}

			$this->fire(name: (string)$name, rule: $rule, trigger: $trigger);
			$this->appConfig->setValueString('openregister', $this->stateKey(name: (string)$name), (string)$now);
		}//end foreach
	}//end run()

	/**
	 * Evaluate one scheduled rule over the open, past-due tasks.
	 *
	 * @param string $name The rule name.
	 * @param array<string, mixed> $rule The rule.
	 * @param array<string, mixed> $trigger Its trigger block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	private function fire(string $name, array $rule, array $trigger): void {
		$filter = (array)($trigger['filter'] ?? []);
		// One clock instant for the whole sweep: the candidate query's
		// derived-overdue filter and the rule's declared filter read the same
		// "now", so the two derivations cannot disagree within a run.
		$nowMutable = $this->time->getDateTime();
		$nowDt = DateTimeImmutable::createFromMutable($nowMutable);
		$schema = $this->schemaFor(name: $name, rule: $rule);
		$watched = $this->watchedFields(trigger: $trigger);

		$matched = 0;
		$dispatched = 0;
		$offset = 0;
		while ($offset < self::MAX_PER_RUN) {
			$page = $this->candidates(now: $nowMutable, offset: $offset);
			if ($page === []) {
				break;
			}

			$offset += count($page);

			foreach ($page as $task) {
				$row = $this->inbox->enrich(task: $task);
				$adapter = new TaskObjectAdapter(task: $task, row: $row);
				$payload = ($adapter->getObject() ?? []);

				if ($this->filters->matches(objectData: $payload, filter: $filter, now: $nowDt) === false) {
					continue;
				}

				$matched++;
				$uuid = (string)$task->getUuid();
				$fingerprint = $this->fingerprint(payload: $payload, watched: $watched);
				$existing = $this->dedupe->findOne(schemaId: self::DEDUPE_SCHEMA_ID, ruleKey: $name, objectUuid: $uuid);
				if ($existing !== null && (string)$existing->getFingerprint() === $fingerprint) {
					continue;
				}

				try {
					$this->dispatcher->dispatchWithSchema(
						object: $adapter,
						trigger: 'scheduled',
						context: ['notificationName' => $name],
						schema: $schema
					);
					$this->dedupe->upsert(
						schemaId: self::DEDUPE_SCHEMA_ID,
						ruleKey: $name,
						objectUuid: $uuid,
						fingerprint: $fingerprint,
						now: $nowMutable,
						dispatched: true
					);
					$dispatched++;
				} catch (Throwable $failure) {
					$this->logger->warning(
						sprintf('[TaskScheduledNotificationJob] Rule "%s" failed for task %s: %s', $name, $uuid, $failure->getMessage())
					);
				}
			}//end foreach

			if (count($page) < self::PAGE) {
				break;
			}
		}//end while

		$this->logger->info(
			sprintf('[TaskScheduledNotificationJob] Rule "%s": matched=%d dispatched=%d', $name, $matched, $dispatched)
		);
	}//end fire()

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
			uid: self::SWEEP_UID,
			isAdmin: true,
			scope: TaskInboxCriteria::SCOPE_ALL,
			isTerminal: false,
			overdueAt: $now,
			sort: TaskInboxCriteria::SORT_CREATED
		);

		try {
			return $this->tasks->findInbox(criteria: $criteria, limit: self::PAGE, offset: $offset);
		} catch (Throwable $failure) {
			$this->logger->warning('[TaskScheduledNotificationJob] Candidate query failed: ' . $failure->getMessage());

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
		$schema = $this->rules->buildSchema();
		$schema->setConfiguration(['x-openregister-notifications' => [$name => $rule]]);

		return $schema;
	}//end schemaFor()

	/**
	 * Whether a rule's interval has elapsed since it last fired.
	 *
	 * @param string $name The rule name.
	 * @param int $intervalSec The declared interval.
	 * @param int $now The clock.
	 *
	 * @return bool True when due.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
	 */
	private function isDue(string $name, int $intervalSec, int $now): bool {
		$last = (int)$this->appConfig->getValueString('openregister', $this->stateKey(name: $name), '0');

		return ($now - $last) >= $intervalSec;
	}//end isDue()

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
