<?php

/**
 * TaskManagerInterface: the task lifecycle operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\Value\ChecklistTick;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskAuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskDelegation;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskResolution;

/**
 * Creates, claims, assigns, resolves and cancels tasks and records their history. Implemented by Task\TaskService.
 */
interface TaskManagerInterface {

	/**
	 * Assign a task to a performer directly.
	 *
	 * @param TaskActorRef $ref      The task and the assigning identity (requester or admin).
	 * @param string       $assignee The new assignee.
	 *
	 * @return TaskInterface The assigned task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function assign(TaskActorRef $ref, string $assignee): TaskInterface;

	/**
	 * The audit trail of a task, oldest first.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\TaskAudit> The entries.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function auditTrail(string $uuid): array;

	/**
	 * Cancel: the requester (or an administrator) terminates the task.
	 *
	 * @param TaskActorRef $ref    The task and the cancelling identity.
	 * @param string|null  $reason Why the task is cancelled.
	 *
	 * @return TaskInterface The terminated task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function cancel(TaskActorRef $ref, ?string $reason): TaskInterface;

	/**
	 * Check or uncheck ONE checklist item, addressed by its id.
	 *
	 * @param TaskActorRef  $ref  The task and the acting identity, which must be the assignee.
	 * @param ChecklistTick $tick The checklist item id and the flag it is set to.
	 *
	 * @return TaskInterface The task with the one item changed.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskValidationException When no item carries that id.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
	 */
	public function checkChecklistItem(TaskActorRef $ref, ChecklistTick $tick): TaskInterface;

	/**
	 * Claim: assign IF still unassigned — the database decides the race.
	 *
	 * Authorization (pool membership) runs first; then ONE conditional
	 * UPDATE either wins or affects nothing. The loser receives a conflict
	 * naming the current holder's existence, never a silent overwrite.
	 *
	 * @param string $uuid The task uuid.
	 * @param string|null $actor The claiming identity.
	 *
	 * @return TaskInterface The claimed task.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskConflictException When another claimer won, or the task is terminal.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function claim(string $uuid, ?string $actor): TaskInterface;

	/**
	 * Create a task.
	 *
	 * Validation happens at THIS boundary, through the published
	 * vocabularies: a legacy status resolves through {@see TaskState} (an
	 * unmapped one is refused naming itself), priority through
	 * {@see TaskPriority}, `expires_at` earlier than `due_at` is refused,
	 * and the checklist must be a typed array. The template, when named, is
	 * FROZEN: `template_snapshot` is written now and all later evaluation
	 * reads it, never the live template.
	 *
	 * This is the HTTP path: unless the actor is an administrator, the
	 * requester is pinned to the actor and a terminal creation state is
	 * refused. In-process callers that need either use {@see import()}.
	 *
	 * @param array<string, mixed> $data The task fields, canonical or legacy.
	 * @param string|null $actor The creating identity.
	 *
	 * @return TaskInterface The created task, with candidates indexed and creation audited.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskValidationException On any refused value.
	 * @throws \OCA\OpenRegister\Exception\TaskAccessDeniedException Without an acting identity.
	 * @throws \OCA\OpenRegister\Exception\TaskSubjectNotFoundException When the caller may not read an
	 *                                                                  object the payload attaches the
	 *                                                                  task to.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function create(array $data, ?string $actor): TaskInterface;

	/**
	 * Delegate: the assignee hands the task to a delegate, with a mandate.
	 *
	 * The delegate becomes the assignee; `on_behalf_of` names the original
	 * performer and `mandate` the authority relied on. Every later action by
	 * the delegate carries both identities into the audit — a delegated
	 * completion is NEVER recorded as the original performer acting.
	 *
	 * @param TaskActorRef   $ref        The task and the delegating identity, which must be the assignee.
	 * @param TaskDelegation $delegation The identity taking over and the mandate (the authority relied on).
	 *
	 * @return TaskInterface The delegated task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function delegate(TaskActorRef $ref, TaskDelegation $delegation): TaskInterface;

	/**
	 * Edit a task's own fields: title, description, due date, priority.
	 *
	 * Authorization runs first (the assignee, a pool member or an
	 * administrator; a denial is audited and writes nothing), then the
	 * values are validated, then the change and its audit entry are written
	 * in one transaction. An edit that changes nothing writes nothing.
	 *
	 * @param TaskActorRef         $ref     The task and the editing identity.
	 * @param array<string, mixed> $changes The new values by field.
	 *
	 * @return TaskInterface The task as it now stands.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskValidationException When a field is unknown or invalid.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it
	 */
	public function edit(TaskActorRef $ref, array $changes): TaskInterface;

	/**
	 * Fetch a task by uuid.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return TaskInterface The task.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function get(string $uuid): TaskInterface;

	/**
	 * Offer a task to a candidate pool, optionally routing it.
	 *
	 * Rewrites the pool (JSON and index together), runs the routing strategy,
	 * and either assigns its answer or leaves the task pooled — NEVER
	 * implicitly assigned. A strategy that finds nobody, with no fallback,
	 * is a pooled task, full stop.
	 *
	 * @param TaskActorRef         $ref  The task and the offering identity.
	 * @param array<string, mixed> $pool candidateUsers / candidateGroups / candidateRole / routingStrategy / routingFallback, each optional.
	 *
	 * @return TaskInterface The offered task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function offer(TaskActorRef $ref, array $pool): TaskInterface;

	/**
	 * Reassign a task to a different performer.
	 *
	 * @param TaskActorRef $ref      The task and the reassigning identity (requester or admin).
	 * @param string       $assignee The new assignee.
	 *
	 * @return TaskInterface The reassigned task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function reassign(TaskActorRef $ref, string $assignee): TaskInterface;

	/**
	 * Append an audit entry that records a FACT about the task without
	 * moving it: the party a portal task was matched to, and the role it was
	 * matched from. The state is unchanged; only the trail grows.
	 *
	 * @param TaskAuditEntry $entry The task uuid, the audit action, the identity recorded as acting and the reason.
	 *
	 * @return TaskInterface The task, unchanged.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function record(TaskAuditEntry $entry): TaskInterface;

	/**
	 * Resolve: the assignee finishes the work with a `resolved` outcome.
	 *
	 * @param TaskActorRef   $ref        The task and the resolving identity, which must be the assignee.
	 * @param TaskResolution $resolution The result text and comment.
	 *
	 * @return TaskInterface The resolved task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function resolve(TaskActorRef $ref, TaskResolution $resolution): TaskInterface;

	/**
	 * Cancellation propagation: a run reached a terminal status.
	 *
	 * Terminates every NON-TERMINAL task carrying that `run_uuid`, with a
	 * reason naming the run and its status, audited with the propagation
	 * source as actor. Idempotent by construction — the read only selects
	 * non-terminal tasks, so observing terminality twice (the reaper races a
	 * completing run) terminates nothing twice. A task with `run_uuid` null
	 * is structurally unreachable: nothing about it derives from a run.
	 *
	 * @param string $runUuid The terminal run's uuid.
	 * @param string $runStatus Its terminal status.
	 *
	 * @return int How many tasks were terminated.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function terminateForRun(string $runUuid, string $runStatus): int;

	/**
	 * Unclaim: the assignee returns the task to its pool.
	 *
	 * @param string $uuid The task uuid.
	 * @param string|null $actor The returning identity — must be the assignee.
	 *
	 * @return TaskInterface The pooled task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function unclaim(string $uuid, ?string $actor): TaskInterface;
}//end interface
