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
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\WriteBack\WriteBackAttempt;
use OCA\OpenRegister\Service\Task\WriteBack\WriteBackRefusal;
use OCA\OpenRegister\Service\Task\WriteBack\WriteBackTaskResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef;

/**
 * The write-back gate.
 *
 * The calendar, the lifecycle and the refusal meet here; the refusal's
 * audit, projection restore and notice live in {@see WriteBackRefusal},
 * which only this gate calls, so there is still one path back.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The identity and status readers are
 * stateless parsers published by the projector and the mapping.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 */
class TaskVtodoWriteBackGate implements TaskVtodoWriteBackGateInterface {

	/**
	 * The verbs a VTODO status may request. Nothing else crosses.
	 *
	 * @var array<int, string>
	 */
	public const VERBS = ['complete', 'cancel'];

	/**
	 * Constructor.
	 *
	 * @param TaskServiceInterface  $tasks     The authorized lifecycle: the ONLY thing that mutates a task.
	 * @param WriteBackTaskResolver $resolver  Resolves the task the VTODO names, refusing visibly.
	 * @param TaskCalendarProjector $projector Renders the engine's truth back over a write.
	 * @param WriteBackRefusal      $refusal   Makes a refusal visible: audit, restore, notice, log.
	 */
	public function __construct(
		private readonly TaskServiceInterface $tasks,
		private readonly WriteBackTaskResolver $resolver,
		private readonly TaskCalendarProjector $projector,
		private readonly WriteBackRefusal $refusal,
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
		$taskUuid = $this->engineTaskOf(calendarData: $calendarData);
		if ($taskUuid === null) {
			return null;
		}

		$fields = TaskCalendarProjector::engineFields(calendarData: $calendarData);
		$task   = $this->resolver->resolve(taskUuid: $taskUuid, actor: $actor);
		$verb   = $this->requestedVerb(fields: $fields, edit: new WriteBackAttempt(taskUuid: $taskUuid, task: $task, verb: 'status', actor: $actor));
		if ($verb === null) {
			// SUMMARY, DESCRIPTION, DUE, PRIORITY or a status restating the
			// engine's own: projection-owned, so the render overwrites it.
			return $this->projector->render(task: $task);
		}

		return $this->projector->render(task: $this->request(taskUuid: $taskUuid, act: new TaskAct(verb: $verb, actor: $actor)));
	}//end handleWrite()

	/**
	 * Request a lifecycle verb on behalf of a calendar actor.
	 *
	 * The triple, and nothing else, reaches the lifecycle. Authorization
	 * denials are audited by the lifecycle itself; every other refusal is
	 * audited here, so no refused write-back goes unrecorded.
	 *
	 * @param string  $taskUuid The task.
	 * @param TaskAct $act      The verb (one of VERBS) and the acting identity.
	 *
	 * @return Task The task after the verb.
	 *
	 * @throws TaskAccessDeniedException When denied, including for a verb outside VERBS.
	 * @throws TaskConflictException When the task is already terminal.
	 * @throws TaskValidationException When the verb refuses its arguments.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	public function request(string $taskUuid, TaskAct $act): Task {
		$task    = $this->resolver->resolve(taskUuid: $taskUuid, actor: $act->actor);
		$attempt = new WriteBackAttempt(taskUuid: $taskUuid, task: $task, verb: $act->verb, actor: $act->actor);
		$this->assertCalendarVerb(attempt: $attempt);

		return $this->performOrRefuse(attempt: $attempt);
	}//end request()

	/**
	 * Perform an admitted calendar verb; a refusal from the lifecycle is
	 * recorded as a refused write-back and rethrown.
	 *
	 * @param WriteBackAttempt $attempt The admitted attempt.
	 *
	 * @return Task The task after the verb.
	 *
	 * @throws TaskAccessDeniedException When the lifecycle denies the verb.
	 * @throws TaskConflictException|TaskValidationException When it refuses it.
	 */
	private function performOrRefuse(WriteBackAttempt $attempt): Task {
		try {
			return $this->perform(attempt: $attempt);
		} catch (TaskAccessDeniedException $denied) {
			// The lifecycle audited this denial already (authorizeOrRecord).
			$this->refusal->refuseRecorded(attempt: $attempt, reason: $denied->getMessage());
			throw $denied;
		} catch (TaskConflictException | TaskValidationException $refused) {
			$this->refusal->refuse(attempt: $attempt, reason: $refused->getMessage());
			throw $refused;
		}
	}//end performOrRefuse()

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
	 * The task uuid of a write that is the engine's business: null for an
	 * ordinary calendar task (no task identity, at any point in its life) and
	 * for the projector's own echo.
	 *
	 * @param string $calendarData The incoming VCALENDAR document.
	 *
	 * @return string|null The task uuid, or null to leave the write alone.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	private function engineTaskOf(string $calendarData): ?string {
		$taskUuid = TaskCalendarProjector::taskUuidOf(calendarData: $calendarData);
		if ($taskUuid === null || $this->projector->isEcho(taskUuid: $taskUuid, calendarData: $calendarData) === true) {
			return null;
		}

		return $taskUuid;
	}//end engineTaskOf()

	/**
	 * The verb a VTODO status requests; an illegal status edit is refused
	 * (audited as `status`) and thrown.
	 *
	 * @param array<string, mixed> $fields The engine fields of the write.
	 * @param WriteBackAttempt     $edit   The status edit, verb `status`.
	 *
	 * @return string|null The verb, or null when the status requests none.
	 *
	 * @throws TaskConflictException When the status names no legal transition.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	private function requestedVerb(array $fields, WriteBackAttempt $edit): ?string {
		try {
			return TaskVtodoStatusMapping::requestedVerb(vtodoStatus: (string)($fields['status'] ?? ''), task: $edit->task);
		} catch (TaskConflictException $illegal) {
			$this->refusal->refuse(attempt: $edit, reason: $illegal->getMessage());
			throw $illegal;
		}
	}//end requestedVerb()

	/**
	 * Refuse any verb outside VERBS before the lifecycle is reached.
	 *
	 * @param WriteBackAttempt $attempt The attempt.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the verb cannot be requested from a calendar.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	private function assertCalendarVerb(WriteBackAttempt $attempt): void {
		if (in_array($attempt->verb, self::VERBS, true) === false) {
			$denied = new TaskAccessDeniedException(
				message: sprintf("Verb '%s' cannot be requested from a calendar; only completion and cancellation can.", $attempt->verb)
			);
			$this->refusal->refuse(attempt: $attempt, reason: $denied->getMessage());
			throw $denied;
		}
	}//end assertCalendarVerb()

	/**
	 * Run the verb through the authorized lifecycle.
	 *
	 * @param WriteBackAttempt $attempt The attempt, its verb one of VERBS.
	 *
	 * @return Task The task after the verb.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	private function perform(WriteBackAttempt $attempt): Task {
		if ($attempt->verb === 'cancel') {
			return $this->tasks->cancel(ref: new TaskActorRef(uuid: $attempt->taskUuid, actor: $attempt->actor), reason: 'Cancelled from the calendar.');
		}

		return $this->tasks->completeWithAnswer(uuid: $attempt->taskUuid, answer: new TaskAnswer(outcome: 'done'), actor: $attempt->actor);
	}//end perform()
}//end class
