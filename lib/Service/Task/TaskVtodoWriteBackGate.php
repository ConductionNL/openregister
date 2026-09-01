<?php

/**
 * THE one path from a calendar back into the engine.
 *
 * What crosses it is a `(task_uuid, requested_verb, actor)` triple and
 * nothing else: never a state, never a field value (flow-task-inbox-
 * projections, design D-2 rule 3). `STATUS:COMPLETED` can only REQUEST
 * `complete`; the entity TaskService decides whether that verb is legal and
 * permitted, through the same authorization every other caller gets.
 *
 * Fail-closed by construction (ADR-005): an unresolvable task, an unknown
 * property shape, an illegal transition or an unavailable authorization all
 * refuse. Refusal is visible (D-2 rule 4): the engine is unchanged, the
 * projection is re-rendered to the engine's truth, a denial is audited with
 * the acting identity and the reason, and the actor is told through the
 * declarative rule set. There is no silent-revert branch.
 *
 * Both DAV hooks (the Sabre plugin and the backend event listener) end here.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The write-back gate.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The gate is the one
 * place the calendar, the lifecycle, the audit, the projection and the
 * refusal notice meet; splitting it would create a second path back.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 */
class TaskVtodoWriteBackGate {

	/**
	 * The verbs a VTODO status may request. Nothing else crosses.
	 *
	 * @var array<int, string>
	 */
	public const VERBS = ['complete', 'cancel'];

	/**
	 * Constructor.
	 *
	 * @param TaskService $tasks The authorized lifecycle: the ONLY thing that mutates a task.
	 * @param TaskMapper $mapper Resolves the task the VTODO names.
	 * @param TaskAuditMapper $audits Records denials the lifecycle did not already record.
	 * @param TaskCalendarProjector $projector Renders the engine's truth back over a refused edit.
	 * @param TaskProjectionService $projections Failure-isolated reconciliation.
	 * @param TaskInboxService $inbox The row the refusal notice is built from.
	 * @param AnnotationNotificationDispatcher $dispatcher The one dispatcher, for the refusal notice.
	 * @param TaskNotificationRules $rules The task rule registry.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly TaskService $tasks,
		private readonly TaskMapper $mapper,
		private readonly TaskAuditMapper $audits,
		private readonly TaskCalendarProjector $projector,
		private readonly TaskProjectionService $projections,
		private readonly TaskInboxService $inbox,
		private readonly AnnotationNotificationDispatcher $dispatcher,
		private readonly TaskNotificationRules $rules,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Process a VTODO write arriving from a calendar.
	 *
	 * Returns the document the calendar SHOULD hold afterwards, or null when
	 * the write is not this capability's business (no task identity) or is
	 * the projector's own echo. A returned document is always the engine's
	 * rendering: after an accepted verb it reflects the new state; after a
	 * non-verb edit (SUMMARY, DUE, ...) it restores the projected values.
	 *
	 * @param string $calendarData The incoming VCALENDAR document.
	 * @param string|null $actor The acting identity, or null when unknown (denied).
	 *
	 * @return string|null The document to store, or null to leave the write alone.
	 *
	 * @throws TaskAccessDeniedException When the actor may not perform the requested verb.
	 * @throws TaskConflictException When the status names no legal transition.
	 * @throws TaskValidationException When the verb refuses its arguments.
	 * @throws DoesNotExistException When the identity names no task.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	public function handleWrite(string $calendarData, ?string $actor): ?string {
		$taskUuid = TaskCalendarProjector::taskUuidOf(calendarData: $calendarData);
		if ($taskUuid === null) {
			// An ordinary calendar task: not the engine's business, at any
			// point in its life.
			return null;
		}

		if ($this->projector->isEcho(taskUuid: $taskUuid, calendarData: $calendarData) === true) {
			return null;
		}

		$fields = TaskCalendarProjector::engineFields(calendarData: $calendarData);
		$task = $this->resolve(taskUuid: $taskUuid, actor: $actor, reason: 'The calendar entry names a task that does not exist.');

		try {
			$verb = TaskVtodoStatusMapping::requestedVerb(vtodoStatus: (string)($fields['status'] ?? ''), task: $task);
		} catch (TaskConflictException $illegal) {
			$this->refuse(task: $task, verb: 'status', actor: $actor, reason: $illegal->getMessage(), audited: false);
			throw $illegal;
		}

		if ($verb === null) {
			// SUMMARY, DESCRIPTION, DUE, PRIORITY or a status restating the
			// engine's own: projection-owned, so the render overwrites it.
			return $this->projector->render(task: $task);
		}

		$updated = $this->request(taskUuid: $taskUuid, verb: $verb, actor: $actor);

		return $this->projector->render(task: $updated);
	}//end handleWrite()

	/**
	 * Request a lifecycle verb on behalf of a calendar actor.
	 *
	 * The triple, and nothing else, reaches the lifecycle. Authorization
	 * denials are audited by the lifecycle itself; every other refusal is
	 * audited here, so no refused write-back goes unrecorded.
	 *
	 * @param string $taskUuid The task.
	 * @param string $verb One of VERBS.
	 * @param string|null $actor The acting identity.
	 *
	 * @return Task The task after the verb.
	 *
	 * @throws TaskAccessDeniedException When denied, including for a verb outside VERBS.
	 * @throws TaskConflictException When the task is already terminal.
	 * @throws TaskValidationException When the verb refuses its arguments.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	public function request(string $taskUuid, string $verb, ?string $actor): Task {
		$task = $this->resolve(taskUuid: $taskUuid, actor: $actor, reason: 'The calendar entry names a task that does not exist.');

		if (in_array($verb, self::VERBS, true) === false) {
			$denied = new TaskAccessDeniedException(
				message: sprintf("Verb '%s' cannot be requested from a calendar; only completion and cancellation can.", $verb)
			);
			$this->refuse(task: $task, verb: $verb, actor: $actor, reason: $denied->getMessage(), audited: false);
			throw $denied;
		}

		try {
			if ($verb === 'cancel') {
				return $this->tasks->cancel(uuid: $taskUuid, reason: 'Cancelled from the calendar.', actor: $actor);
			}

			return $this->tasks->complete(uuid: $taskUuid, outcome: 'done', resultText: null, comment: null, actor: $actor);
		} catch (TaskAccessDeniedException $denied) {
			// The lifecycle audited this denial already (authorizeOrRecord).
			$this->refuse(task: $task, verb: $verb, actor: $actor, reason: $denied->getMessage(), audited: true);
			throw $denied;
		} catch (TaskConflictException | TaskValidationException $refused) {
			$this->refuse(task: $task, verb: $verb, actor: $actor, reason: $refused->getMessage(), audited: false);
			throw $refused;
		}//end try
	}//end request()

	/**
	 * Whether a document is a projected VTODO.
	 *
	 * @param string $calendarData The VCALENDAR document.
	 *
	 * @return bool True when it carries a task identity.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	public function isProjected(string $calendarData): bool {
		return TaskCalendarProjector::taskUuidOf(calendarData: $calendarData) !== null;
	}//end isProjected()

	/**
	 * Resolve the task a write names, refusing (and telling the actor) when it does not exist.
	 *
	 * @param string $taskUuid The task uuid.
	 * @param string|null $actor The acting identity.
	 * @param string $reason The refusal reason when unresolvable.
	 *
	 * @return Task The task.
	 *
	 * @throws DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	private function resolve(string $taskUuid, ?string $actor, string $reason): Task {
		try {
			return $this->mapper->findByUuid(uuid: $taskUuid);
		} catch (DoesNotExistException $missing) {
			$this->logger->warning(
				'[TaskVtodoWriteBackGate] Refused: ' . $reason,
				['task' => $taskUuid, 'actor' => $actor]
			);
			throw $missing;
		}
	}//end resolve()

	/**
	 * Make a refusal visible: audit it, restore the projection, tell the actor.
	 *
	 * @param Task $task The task the write named.
	 * @param string $verb The verb attempted (or `status` for an illegal status edit).
	 * @param string|null $actor The acting identity.
	 * @param string $reason Why it was refused.
	 * @param bool $audited Whether the lifecycle already recorded the denial.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the denial is
	 * already on the audit is a fact about the caller, not a behaviour switch.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function refuse(Task $task, string $verb, ?string $actor, string $reason, bool $audited): void {
		if ($audited === false) {
			$this->recordDenial(task: $task, verb: $verb, actor: $actor, reason: $reason);
		}

		$this->projections->reconcileTask(task: $task);
		$this->notifyRefusal(task: $task, actor: $actor, reason: $reason);

		$this->logger->info(
			'[TaskVtodoWriteBackGate] Refused a calendar write-back: ' . $reason,
			['task' => $task->getUuid(), 'verb' => $verb, 'actor' => $actor]
		);
	}//end refuse()

	/**
	 * Append an unauthorized audit entry for a refusal the lifecycle did not see.
	 *
	 * @param Task $task The task.
	 * @param string $verb The verb attempted.
	 * @param string|null $actor The acting identity.
	 * @param string $reason The denial reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function recordDenial(Task $task, string $verb, ?string $actor, string $reason): void {
		if ($task->getId() === null) {
			return;
		}

		try {
			$entry = new TaskAudit();
			$entry->setTaskId((int)$task->getId());
			$entry->setAction($verb);
			$entry->setStateAfter($task->getState());
			$entry->setActor($actor);
			$entry->setPerformerType($task->getPerformerType());
			$entry->setOnBehalfOf($task->getOnBehalfOf());
			$entry->setMandate($task->getMandate());
			$entry->setReason('Calendar write-back refused: ' . $reason);
			$entry->setAuthorized(false);
			$this->audits->insert($entry);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskVtodoWriteBackGate] Could not record a write-back denial: ' . $failure->getMessage(),
				['task' => $task->getUuid(), 'verb' => $verb]
			);
		}
	}//end recordDenial()

	/**
	 * Tell the actor which task was affected and why the change did not take,
	 * through the declarative rule set (no imperative notification call).
	 *
	 * An actor who cannot be named cannot be told; that is logged, not
	 * widened to anyone else.
	 *
	 * @param Task $task The task.
	 * @param string|null $actor The acting identity.
	 * @param string $reason The refusal reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function notifyRefusal(Task $task, ?string $actor, string $reason): void {
		if ($actor === null || trim($actor) === '') {
			$this->logger->info(
				'[TaskVtodoWriteBackGate] Refusal has no nameable actor to notify.',
				['task' => $task->getUuid()]
			);

			return;
		}

		try {
			$adapter = new TaskObjectAdapter(
				task: $task,
				row: $this->inbox->enrich(task: $task),
				extra: [
					'writeBackActor' => $actor,
					'writeBackReason' => $reason,
				]
			);
			$this->dispatcher->dispatchWithSchema(
				object: $adapter,
				trigger: 'transition',
				context: ['action' => TaskNotificationRules::ACTION_WRITE_BACK_REFUSED, 'actor' => $actor],
				schema: $this->rules->buildSchema()
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskVtodoWriteBackGate] Could not notify the actor of a refusal: ' . $failure->getMessage(),
				['task' => $task->getUuid(), 'actor' => $actor]
			);
		}//end try
	}//end notifyRefusal()
}//end class
