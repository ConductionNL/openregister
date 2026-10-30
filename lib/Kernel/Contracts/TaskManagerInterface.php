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

use OCA\OpenRegister\Db\Task;

/**
 * Creates, claims, assigns, resolves and cancels tasks and records their history. Implemented by Task\TaskService.
 */
interface TaskManagerInterface {

	/**
	 * Assign a task to a performer directly.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $assignee The performer reference.
	 * @param string|null $actor The assigning identity — requester or admin.
	 *
	 * @return Task The assigned task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function assign(string $uuid, string $assignee, ?string $actor): Task;

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
	 * @param string $uuid The task uuid.
	 * @param string|null $reason Why, recorded on task and audit.
	 * @param string|null $actor The cancelling identity.
	 *
	 * @return Task The terminated task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function cancel(string $uuid, ?string $reason, ?string $actor): Task;

	/**
	 * Check or uncheck ONE checklist item, addressed by its id.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $itemId The checklist item id.
	 * @param bool $checked The new checked value.
	 * @param string|null $actor The acting identity — must be the assignee.
	 *
	 * @return Task The task with the one item changed.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskValidationException When no item carries that id.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
	 */
	public function checkChecklistItem(string $uuid, string $itemId, bool $checked, ?string $actor): Task;

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
	 * @return Task The claimed task.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskConflictException When another claimer won, or the task is terminal.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function claim(string $uuid, ?string $actor): Task;

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
	 * @return Task The created task, with candidates indexed and creation audited.
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
	public function create(array $data, ?string $actor): Task;

	/**
	 * Delegate: the assignee hands the task to a delegate, with a mandate.
	 *
	 * The delegate becomes the assignee; `on_behalf_of` names the original
	 * performer and `mandate` the authority relied on. Every later action by
	 * the delegate carries both identities into the audit — a delegated
	 * completion is NEVER recorded as the original performer acting.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $delegate The acting identity taking over.
	 * @param string $mandate The authority relied on.
	 * @param string|null $actor The delegating identity — must be the assignee.
	 *
	 * @return Task The delegated task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function delegate(string $uuid, string $delegate, string $mandate, ?string $actor): Task;

	/**
	 * Edit a task's own fields: title, description, due date, priority.
	 *
	 * Authorization runs first (the assignee, a pool member or an
	 * administrator; a denial is audited and writes nothing), then the
	 * values are validated, then the change and its audit entry are written
	 * in one transaction. An edit that changes nothing writes nothing.
	 *
	 * @param string $uuid The task uuid.
	 * @param array<string, mixed> $changes The new values by field.
	 * @param string|null $actor The editing identity.
	 *
	 * @return Task The task as it now stands.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskValidationException When a field is unknown or invalid.
	 *
	 * @spec openspec/changes/flow-task-edit/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it
	 */
	public function edit(string $uuid, array $changes, ?string $actor): Task;

	/**
	 * Fetch a task by uuid.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The task.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function get(string $uuid): Task;

	/**
	 * Offer a task to a candidate pool, optionally routing it.
	 *
	 * Rewrites the pool (JSON and index together), runs the routing strategy,
	 * and either assigns its answer or leaves the task pooled — NEVER
	 * implicitly assigned. A strategy that finds nobody, with no fallback,
	 * is a pooled task, full stop.
	 *
	 * @param string $uuid The task uuid.
	 * @param array<string, mixed> $pool candidateUsers / candidateGroups /
	 *                                   candidateRole / routingStrategy /
	 *                                   routingFallback, each optional.
	 * @param string|null $actor The offering identity.
	 *
	 * @return Task The offered task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function offer(string $uuid, array $pool, ?string $actor): Task;

	/**
	 * Reassign a task to a different performer.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $assignee The new performer reference.
	 * @param string|null $actor The reassigning identity — requester or admin.
	 *
	 * @return Task The reassigned task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function reassign(string $uuid, string $assignee, ?string $actor): Task;

	/**
	 * Append an audit entry that records a FACT about the task without
	 * moving it: the party a portal task was matched to, and the role it was
	 * matched from. The state is unchanged; only the trail grows.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $action The audited action name.
	 * @param string|null $actor The acting identity.
	 * @param string $reason What is being recorded.
	 *
	 * @return Task The task, unchanged.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function record(string $uuid, string $action, ?string $actor, string $reason): Task;

	/**
	 * Resolve: the assignee finishes the work with a `resolved` outcome.
	 *
	 * @param string $uuid The task uuid.
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment Completion comment.
	 * @param string|null $actor The resolving identity — must be the assignee.
	 *
	 * @return Task The resolved task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function resolve(string $uuid, ?string $resultText, ?string $comment, ?string $actor): Task;

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
	 * @return Task The pooled task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function unclaim(string $uuid, ?string $actor): Task;
}//end interface
