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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\AbstractObjectMapper;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists and counts tasks for a caller, with subject context attached.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PortalTaskDelivery::summarise is a
 * stateless fold over rows; an instance to call it would be a second copy.
 */
class TaskInboxService {

	/**
	 * Constructor.
	 *
	 * @param TaskMapper $tasks The datastore queries.
	 * @param TaskTemporalProjection $temporal The ONE overdue derivation.
	 * @param LoggerInterface $logger Failure reporting.
	 * @param AbstractObjectMapper|null $objects Resolves subject objects for
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
	 */
	public function __construct(
		private readonly TaskMapper $tasks,
		private readonly TaskTemporalProjection $temporal,
		private readonly LoggerInterface $logger,
		private readonly ?AbstractObjectMapper $objects = null,
		private readonly ?PortalTaskDeliveryMapper $deliveries = null,
	) {

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
	 * @param TaskInboxCriteria $criteria Scope, filters, sort and identity.
	 * @param int $limit Page size (clamped to 1..500).
	 * @param int $offset Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function inbox(TaskInboxCriteria $criteria, int $limit = 25, int $offset = 0): array {
		$limit = max(1, min($limit, 500));
		$offset = max(0, $offset);

		if (trim($criteria->uid) === '') {
			return [
				'results' => [],
				'total' => 0,
				'limit' => $limit,
				'offset' => $offset,
			];
		}

		$page = $this->tasks->findInbox(criteria: $criteria, limit: $limit, offset: $offset);
		$total = $this->tasks->countInbox(criteria: $criteria);
		$subjects = $this->subjectContexts(tasks: $page);

		$now = $this->temporal->now();
		$results = [];
		foreach ($page as $task) {
			$results[] = $this->row(task: $task, subjects: $subjects, now: $now);
		}

		return [
			'results' => $results,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end inbox()

	/**
	 * One task as an API row: stored fields, DERIVED temporal projection,
	 * subject context, and a display title that is synthesized when the
	 * stored title is null — and never written back.
	 *
	 * @param Task $task The task.
	 * @param array<string, array<string, mixed>> $subjects Subject context by object uuid.
	 * @param \DateTimeInterface $now The clock instant for the projection.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function row(Task $task, array $subjects, \DateTimeInterface $now): array {
		$row = $task->jsonSerialize();

		$subject = null;
		$objectUuid = (string)$task->getObjectUuid();
		if ($objectUuid !== '' && array_key_exists($objectUuid, $subjects) === true) {
			$subject = $subjects[$objectUuid];
		}

		$row['subject'] = $subject;
		$row['displayTitle'] = $this->displayTitle(task: $task, subject: $subject);
		// Derived, never stored: the projection is attached to the ROW, and
		// the row alone.
		$projection = $this->temporal->project(task: $task, now: $now);
		$row['overdue'] = $projection['overdue'];
		$row['daysUntilDue'] = $projection['daysUntilDue'];
		$row['daysOverdue'] = $projection['daysOverdue'];

		if ((string)$task->getPerformerType() === Task::PERFORMER_EXTERNAL) {
			$row['delivery'] = $this->deliveryState(task: $task);
		}

		return $row;
	}//end row()

	/**
	 * The delivery state of an external task's ask, for its row.
	 *
	 * Summarised from the delivery request records: `requested` until every
	 * channel reports, `delivered` when the portal inbox message went out,
	 * `failed` when a channel failed, and `not-recorded` when no request row
	 * exists at all (the outage case the spec wants visible). Never throws:
	 * the task list does not fail over a delivery lookup.
	 *
	 * @param Task $task The external task.
	 *
	 * @return array<string, mixed> {state, channels, requestedAt, deliveredAt}.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	public function deliveryState(Task $task): array {
		$unknown = [
			'state' => PortalTaskDelivery::STATE_NOT_RECORDED,
			'channels' => [],
			'requestedAt' => null,
			'deliveredAt' => null,
		];
		if ($this->deliveries === null) {
			return $unknown;
		}

		try {
			$rows = $this->deliveries->findForTask(taskUuid: (string)$task->getUuid());
		} catch (Throwable $failure) {
			$this->logger->debug(
				'[TaskInboxService] Could not read delivery state: ' . $failure->getMessage(),
				['task' => $task->getUuid()]
			);

			return $unknown;
		}

		return PortalTaskDelivery::summarise(rows: $rows);
	}//end deliveryState()

	/**
	 * Subject context for a set of tasks, for readers outside this service
	 * (the portal seam lists a subject's tasks WITH their case context).
	 *
	 * @param array<int, Task> $tasks The tasks.
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
	 * @param Task $task The task.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	public function enrich(Task $task): array {
		return $this->row(task: $task, subjects: $this->subjectContexts(tasks: [$task]), now: $this->temporal->now());
	}//end enrich()

	/**
	 * Synthesize a display title for a titleless task — on read, never persisted.
	 *
	 * A stored synthesized title would go stale the moment the subject is
	 * renamed, which is why the spec forbids persisting it.
	 *
	 * @param Task $task The task.
	 * @param array<string, mixed>|null $subject The subject context, when resolved.
	 *
	 * @return string A non-empty display title.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function displayTitle(Task $task, ?array $subject): string {
		$stored = trim((string)$task->getTitle());
		if ($stored !== '') {
			return $stored;
		}

		$action = trim((string)$task->getLastAction());
		if ($action === '') {
			$action = 'task';
		}

		$subjectName = trim((string)($subject['title'] ?? ''));
		if ($subjectName === '') {
			$subjectName = trim((string)$task->getObjectUuid());
		}

		if ($subjectName === '') {
			return ucfirst($action);
		}

		return sprintf('%s: %s', ucfirst($action), $subjectName);
	}//end displayTitle()

	/**
	 * Resolve subject context for a page of tasks, in ONE batch.
	 *
	 * A failed or absent object store yields empty context, logged at debug:
	 * the inbox never fails over enrichment, and never fires a query per row.
	 *
	 * @param array<int, Task> $tasks The page.
	 *
	 * @return array<string, array<string, mixed>> Context by object uuid:
	 *         {uuid, registerId, schemaId, title}.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function subjectContexts(array $tasks): array {
		$uuids = [];
		foreach ($tasks as $task) {
			$objectUuid = trim((string)$task->getObjectUuid());
			if ($objectUuid !== '') {
				$uuids[$objectUuid] = true;
			}
		}

		if ($uuids === [] || $this->objects === null) {
			return [];
		}

		try {
			$found = $this->objects->findMultiple(ids: array_keys($uuids));
		} catch (Throwable $failure) {
			$this->logger->debug(
				'[TaskInboxService] Could not resolve subject objects: ' . $failure->getMessage(),
				['count' => count($uuids)]
			);

			return [];
		}

		$contexts = [];
		foreach ($found as $object) {
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
	}//end subjectContexts()

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
		];
	}//end contextRow()
}//end class
