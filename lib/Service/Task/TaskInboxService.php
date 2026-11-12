<?php

/**
 * The inbox: "what is waiting for me?", answered in one query.
 *
 * This is the capability `AwaitSignalNode` provably lacks — its answer lives
 * in `$run->getContext()['signal']`, which is not listable, not countable
 * and not poolable. Here, filtering, sorting, pagination AND the total all
 * run in the datastore over one shared predicate set
 * ({@see \OCA\OpenRegister\Db\TaskMapper}), and visibility is part of the
 * WHERE clause — never a post-filter over a wider result, because a
 * filtered-down page silently drops rows and a filtered-down total leaks
 * what it excluded (design D-9).
 *
 * Each returned row carries the subject object's identifying context
 * (register, schema, uuid, display title), fetched in ONE batch for the
 * page, so a list is readable without a second request per row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskBlockerMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists and counts tasks for a caller, with subject context attached.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PortalTaskDelivery::summarise is a
 * stateless fold over rows; an instance to call it would be a second copy.
 */
class TaskInboxService implements TaskInboxServiceInterface {

	/**
	 * The ONE overdue derivation: a pure function of a task and a clock, so it is built here, not injected.
	 *
	 * @var TaskTemporalProjection
	 */
	private readonly TaskTemporalProjection $temporal;

	/**
	 * Constructor.
	 *
	 * @param TaskMapper $tasks The datastore queries.
	 * @param LoggerInterface $logger Failure reporting.
	 * @param MagicMapperInterface|null $objects Resolves subject objects for
	 *                                           row context. Nullable so the
	 *                                           service stays constructible
	 *                                           without the object store; a
	 *                                           row's subject context then
	 *                                           reads null, which is honest —
	 *                                           the TASK list never fails
	 *                                           over a context lookup.
	 * @param PortalTaskDeliveryMapper|null $deliveries Reads the delivery
	 *                                                  state of an EXTERNAL
	 *                                                  task's ask, attached
	 *                                                  to its row so the
	 *                                                  caseworker sees "not
	 *                                                  yet delivered" instead
	 *                                                  of silence. Nullable
	 *                                                  for the same reason as
	 *                                                  the object store; absent,
	 *                                                  the row says so.
	 * @param TaskSubjectLocator|null $locator Reads located subjects from
	 *                                         their own tables. Nullable for
	 *                                         hand-built test services;
	 *                                         absent, every subject is
	 *                                         resolved by the cross-table search.
	 * @param TaskBlockerMapperInterface|null $blockers Answers which blockers are still
	 *                                         open, for the derived `blocked`
	 *                                         flag. Absent, no row is blocked.
	 */
	public function __construct(
		private readonly TaskMapper $tasks,
		private readonly LoggerInterface $logger,
		private readonly ?MagicMapperInterface $objects = null,
		private readonly ?PortalTaskDeliveryMapper $deliveries = null,
		private readonly ?TaskSubjectLocator $locator = null,
		private readonly ?TaskBlockerMapperInterface $blockers = null,
	) {
		$this->temporal = new TaskTemporalProjection();
	}//end __construct()

	/**
	 * One inbox page: rows with subject context and temporal projection,
	 * plus the total the SAME predicates count.
	 *
	 * The caller's identity facts (uid, group ids, admin) arrive resolved:
	 * the controller owns the session, this service owns the query. An empty
	 * uid returns nothing rather than everything — no identity, no
	 * visibility.
	 *
	 * @param TaskInboxCriteria  $criteria Scope, filters, sort and identity.
	 * @param TaskInboxPage|null $page     The page window; the first 25 when null.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function inbox(TaskInboxCriteria $criteria, ?TaskInboxPage $page = null): array {
		$page = ($page ?? new TaskInboxPage());

		if (trim($criteria->uid) === '') {
			return $page->envelope(results: [], total: 0);
		}

		$tasks = $this->tasks->findInbox(criteria: $criteria, page: new ListQuery(limit: $page->limit, offset: $page->offset));
		$total = $this->tasks->countInbox(criteria: $criteria);
		$subjects = $this->subjectContexts(tasks: $tasks);
		$openBlockers = $this->openBlockers(tasks: $tasks);

		$listing = new InboxListing(subjects: $subjects, now: $this->temporal->now(), openBlockers: $openBlockers);
		$results = [];
		foreach ($tasks as $task) {
			$results[] = $this->row(task: $task, listing: $listing);
		}

		return $page->envelope(results: $results, total: $total);
	}//end inbox()

	/**
	 * One task as an API row: stored fields, DERIVED temporal projection,
	 * subject context, and a display title that is synthesized when the
	 * stored title is null — and never written back.
	 *
	 * @param TaskInterface $task    The task.
	 * @param InboxListing  $listing The subjects (context by object uuid), the clock instant for the
	 *                               projection and the open blocker uuids the listing shares.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function row(TaskInterface $task, InboxListing $listing): array {
		$row = $task->jsonSerialize();

		// Derived, never stored: blocked while the named blocker is open.
		$blockedBy = (string)$task->getBlockedBy();
		$row['blocked'] = ($blockedBy !== '' && in_array($blockedBy, $listing->openBlockers, true) === true);
		$row['subject'] = self::subjectIn(task: $task, subjects: $listing->subjects);
		$row['displayTitle'] = $this->displayTitle(task: $task, subject: $row['subject']);
		$row = array_merge($row, $this->dueFields(task: $task, now: $listing->now));
		if ((string)$task->getPerformerType() === TaskInterface::PERFORMER_EXTERNAL) {
			$row['delivery'] = $this->deliveryState(task: $task);
		}

		return $row;
	}//end row()

	/**
	 * The subject context of a task, when the listing read one.
	 *
	 * @param TaskInterface $task The task.
	 * @param array<string, mixed> $subjects Subject contexts by object uuid.
	 *
	 * @return mixed The subject context, or null.
	 */
	private static function subjectIn(TaskInterface $task, array $subjects): mixed {
		$objectUuid = (string)$task->getObjectUuid();
		if ($objectUuid !== '' && array_key_exists($objectUuid, $subjects) === true) {
			return $subjects[$objectUuid];
		}

		return null;
	}//end subjectIn()

	/**
	 * The due fields of a row. Derived, never stored: the projection is
	 * attached to the ROW, and the row alone.
	 *
	 * @param TaskInterface $task The task.
	 * @param \DateTimeInterface $now The moment to derive them at.
	 *
	 * @return array{overdue: mixed, daysUntilDue: mixed, daysOverdue: mixed} The fields.
	 */
	private function dueFields(TaskInterface $task, \DateTimeInterface $now): array {
		$projection = $this->temporal->project(task: $task, now: $now);

		return [
			'overdue' => $projection['overdue'],
			'daysUntilDue' => $projection['daysUntilDue'],
			'daysOverdue' => $projection['daysOverdue'],
		];
	}//end dueFields()

	/**
	 * The delivery state of an external task's ask, for its row.
	 *
	 * Summarised from the delivery request records: `requested` until every
	 * channel reports, `delivered` when the portal inbox message went out,
	 * `failed` when a channel failed, and `not-recorded` when no request row
	 * exists at all (the outage case the spec wants visible). Never throws:
	 * the task list does not fail over a delivery lookup.
	 *
	 * @param TaskInterface $task The external task.
	 *
	 * @return array<string, mixed> {state, channels, requestedAt, deliveredAt}.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	public function deliveryState(TaskInterface $task): array {
		$rows = null;
		if ($this->deliveries !== null) {
			$rows = $this->deliveryRows(deliveries: $this->deliveries, task: $task);
		}

		if ($rows === null) {
			return [
				'state' => PortalTaskDelivery::STATE_NOT_RECORDED,
				'channels' => [],
				'requestedAt' => null,
				'deliveredAt' => null,
			];
		}

		return PortalTaskDelivery::summarise(rows: $rows);
	}//end deliveryState()

	/**
	 * The task's recorded delivery rows, or null when they cannot be read.
	 *
	 * @param PortalTaskDeliveryMapper $deliveries The delivery store.
	 * @param TaskInterface            $task       The task.
	 *
	 * @return array|null The rows, or null after logging why they could not be read.
	 */
	private function deliveryRows(PortalTaskDeliveryMapper $deliveries, TaskInterface $task): ?array {
		try {
			return $deliveries->findForTask(taskUuid: (string)$task->getUuid());
		} catch (Throwable $failure) {
			$this->logger->debug(
				'[TaskInboxService] Could not read delivery state: ' . $failure->getMessage(),
				['task' => $task->getUuid()]
			);

			return null;
		}
	}//end deliveryRows()

	/**
	 * Subject context for a set of tasks, for readers outside this service
	 * (the portal seam lists a subject's tasks WITH their case context).
	 *
	 * @param array<int, TaskInterface> $tasks The tasks.
	 *
	 * @return array<string, array<string, mixed>> Context by object uuid.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function subjectContextsFor(array $tasks): array {
		return $this->subjectContexts(tasks: $tasks);
	}//end subjectContextsFor()

	/**
	 * One task as an API row, with its subject context resolved.
	 *
	 * The single-task form of {@see inbox()}: the projections (notification
	 * payload, calendar VTODO) read the SAME row the inbox serves, so a
	 * display title or a derived overdue flag cannot differ between the
	 * inbox and the notification about it.
	 *
	 * @param TaskInterface $task The task.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	public function enrich(TaskInterface $task): array {
		$listing = new InboxListing(
			subjects: $this->subjectContexts(tasks: [$task]),
			now: $this->temporal->now(),
			openBlockers: $this->openBlockers(tasks: [$task])
		);

		return $this->row(task: $task, listing: $listing);
	}//end enrich()

	/**
	 * Which blockers of these tasks are still open, in ONE lookup.
	 *
	 * Never throws: a failed lookup reads as "not blocked", logged at debug,
	 * because the inbox does not fail over a derived flag.
	 *
	 * @param array<int, TaskInterface> $tasks The page.
	 *
	 * @return array<int, string> The open blocker uuids.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	private function openBlockers(array $tasks): array {
		$uuids = self::blockerUuids(tasks: $tasks);
		if ($uuids === [] || $this->blockers === null) {
			return [];
		}

		try {
			return $this->blockers->openBlockerUuids(uuids: array_values(array_unique($uuids)));
		} catch (Throwable $failure) {
			$this->logger->debug('[TaskInboxService] Could not read blocker state: ' . $failure->getMessage());

			return [];
		}
	}//end openBlockers()

	/**
	 * The uuids the tasks are blocked by, in task order, blanks left out.
	 *
	 * @param array<int, TaskInterface> $tasks The tasks.
	 *
	 * @return array<int, string> The blocker uuids.
	 */
	private static function blockerUuids(array $tasks): array {
		$uuids = [];
		foreach ($tasks as $task) {
			$blockedBy = (string)$task->getBlockedBy();
			if ($blockedBy !== '') {
				$uuids[] = $blockedBy;
			}
		}

		return $uuids;
	}//end blockerUuids()

	/**
	 * Synthesize a display title for a titleless task — on read, never persisted.
	 *
	 * A stored synthesized title would go stale the moment the subject is
	 * renamed, which is why the spec forbids persisting it.
	 *
	 * @param TaskInterface $task The task.
	 * @param array<string, mixed>|null $subject The subject context, when resolved.
	 *
	 * @return string A non-empty display title.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function displayTitle(TaskInterface $task, ?array $subject): string {
		$stored = trim((string)$task->getTitle());
		if ($stored !== '') {
			return $stored;
		}

		$action = trim((string)$task->getLastAction());
		if ($action === '') {
			$action = 'task';
		}

		$subjectName = self::subjectNameOf(task: $task, subject: $subject);
		if ($subjectName === '') {
			return ucfirst($action);
		}

		return sprintf('%s: %s', ucfirst($action), $subjectName);
	}//end displayTitle()

	/**
	 * The name a derived title gives the subject: its title, else the task's object uuid.
	 *
	 * @param TaskInterface             $task    The task.
	 * @param array<string, mixed>|null $subject The subject context, when one was read.
	 *
	 * @return string The name, or '' when neither is known.
	 */
	private static function subjectNameOf(TaskInterface $task, ?array $subject): string {
		$subjectName = trim((string)($subject['title'] ?? ''));
		if ($subjectName === '') {
			return trim((string)$task->getObjectUuid());
		}

		return $subjectName;
	}//end subjectNameOf()

	/**
	 * Resolve subject context for a page of tasks, in ONE batch.
	 *
	 * A failed or absent object store yields empty context, logged at debug:
	 * the inbox never fails over enrichment, and never fires a query per row.
	 *
	 * @param array<int, TaskInterface> $tasks The page.
	 *
	 * @return array<string, array<string, mixed>> Context by object uuid:
	 *         {uuid, registerId, schemaId, title}.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function subjectContexts(array $tasks): array {
		$uuids = self::subjectUuids(tasks: $tasks);
		if ($uuids === [] || $this->objects === null) {
			return [];
		}

		return $this->contextsOf(objects: $this->subjectObjects(tasks: $tasks, uuids: $uuids));
	}//end subjectContexts()

	/**
	 * The distinct subject uuids of a page, in first-seen order.
	 *
	 * @param array<int, TaskInterface> $tasks The page.
	 *
	 * @return array<int, int|string> The uuids.
	 */
	private static function subjectUuids(array $tasks): array {
		$uuids = [];
		foreach ($tasks as $task) {
			$objectUuid = trim((string)$task->getObjectUuid());
			if ($objectUuid !== '') {
				$uuids[$objectUuid] = true;
			}
		}

		return array_keys($uuids);
	}//end subjectUuids()

	/**
	 * The context of every subject object that yields one, by uuid.
	 *
	 * @param array<array-key, mixed> $objects The subject objects.
	 *
	 * @return array<string, array<string, mixed>> Context by object uuid.
	 */
	private function contextsOf(array $objects): array {
		$contexts = [];
		foreach ($objects as $object) {
			$serialised = [];
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
			}

			$context = $this->contextRow(serialised: (array)$serialised);
			if ($context !== null) {
				$contexts[(string)$context['uuid']] = $context;
			}
		}

		return $contexts;
	}//end contextsOf()

	/**
	 * The subject objects of a page: located ones from their own tables, the
	 * rest through the cross-table search.
	 *
	 * @param array<int, TaskInterface> $tasks The page.
	 * @param array<int, string> $uuids The page's subject uuids.
	 *
	 * @return array<array-key, mixed> The subject objects.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function subjectObjects(array $tasks, array $uuids): array {
		$found = ($this->locator?->readLocated(tasks: $tasks) ?? []);
		$unlocated = array_values(array_diff($uuids, array_keys($found)));
		if ($unlocated === [] || $this->objects === null) {
			return $found;
		}

		try {
			return array_merge($found, $this->objects->findMultiple(ids: $unlocated));
		} catch (Throwable $failure) {
			$this->logger->debug(
				'[TaskInboxService] Could not resolve subject objects: ' . $failure->getMessage(),
				['count' => count($unlocated)]
			);

			return $found;
		}
	}//end subjectObjects()

	/**
	 * One subject's context row, read from its REAL serialised shape.
	 *
	 * 🔴 THE REAL SHAPE. ObjectEntity::jsonSerialize() puts the object's own
	 * data at top level and its identity under `@self` (uuid keyed `id` there,
	 * mirrored as top-level `id`); there is no top-level `uuid`, `register` or
	 * `schema` key. Reading those flat keys made every row's subject null —
	 * the portal contract (flow-portal-task: "with their case context")
	 * shipped null case context while the unit test's hand-rolled stub agreed
	 * with the wrong shape. The flat keys stay as fallbacks for stores that
	 * serialise flat.
	 *
	 * @param array<string, mixed> $serialised The object's serialised form.
	 *
	 * @return array<string, mixed>|null The context row, or null without an identity.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	private function contextRow(array $serialised): ?array {
		$self = ($serialised['@self'] ?? null);
		if (is_array($self) === false) {
			$self = [];
		}

		$uuid = trim((string)($self['id'] ?? ($serialised['id'] ?? ($serialised['uuid'] ?? ''))));
		if ($uuid === '') {
			return null;
		}

		return [
			'uuid' => $uuid,
			'register' => ($self['register'] ?? ($serialised['register'] ?? null)),
			'schema' => ($self['schema'] ?? ($serialised['schema'] ?? null)),
			'title' => ($self['name'] ?? ($serialised['name'] ?? ($serialised['title'] ?? null))),
			'identifier' => $this->subjectIdentifier(serialised: $serialised),
		];
	}//end contextRow()

	/**
	 * The subject's own human number, such as a case number ("2026-0074"):
	 * its `identifier` property when that is a non-empty string or number,
	 * else null. A list shows it beside the subject's title.
	 *
	 * @param array<string, mixed> $serialised The object's serialised form.
	 *
	 * @return string|null The identifier, or null when the object has none.
	 *
	 * @spec openspec/changes/task-subject-identifier/specs/flow-tasks/spec.md#requirement-the-task-subject-context-carries-the-subjects-identifier
	 */
	private function subjectIdentifier(array $serialised): ?string {
		$identifier = ($serialised['identifier'] ?? null);
		if (is_string($identifier) === false && is_int($identifier) === false) {
			return null;
		}

		$identifier = trim((string)$identifier);
		if ($identifier === '') {
			return null;
		}

		return $identifier;
	}//end subjectIdentifier()
}//end class
