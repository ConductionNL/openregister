<?php

/**
 * TaskServiceInterface: the public surface of TaskService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskSubjectNotFoundException;
use OCA\OpenRegister\Exception\TaskValidationException;

/**
 * Every public method of TaskService. Callers depend on this interface, not on the class.
 */
interface TaskServiceInterface {

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
	 * Sequence fields are refused for every caller: only the task sequence
	 * sets them, through {@see import()}.
	 *
	 * @param array<string, mixed> $data The task fields, canonical or legacy.
	 * @param string|null $actor The creating identity.
	 *
	 * @return Task The created task, with candidates indexed and creation audited.
	 *
	 * @throws TaskValidationException On any refused value.
	 * @throws TaskAccessDeniedException Without an acting identity.
	 * @throws TaskSubjectNotFoundException When the caller may not read an
	 *                                      object the payload attaches the
	 *                                      task to.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 * @spec openspec/changes/sequence-fields-are-set-by-the-sequence-service-only/specs/flow-approval-consolidation/spec.md#requirement-only-the-task-sequence-sets-a-tasks-sequence-fields
	 */
	public function create(array $data, ?string $actor): Task;

	/**
	 * Create a task on the TRUSTED path: migrations and in-process callers
	 * (the user-task node) that may name a requester and may import a task
	 * that is already closed (a completed approval carried over from a
	 * legacy shape). Not reachable over HTTP; the controller calls create().
	 *
	 * @param array<string, mixed> $data The task fields, canonical or legacy.
	 * @param string|null $actor The creating identity.
	 * @param callable|null $mayReadBlocker Answers whether the creating caller may read a blocker; create() passes
	 *                                      the caller's own rule for an ordinary user, a trusted caller passes none.
	 * @param DateTime|null $createdAt The moment the task was created, for a task carried over from elsewhere; it stamps
	 *                                 the task and its create audit entry. Null stamps both with now.
	 *
	 * @return Task The created task, with candidates indexed and creation audited.
	 *
	 * @throws TaskValidationException On any refused value.
	 * @throws TaskAccessDeniedException Without an acting identity.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function import(array $data, ?string $actor, ?callable $mayReadBlocker = null, ?DateTime $createdAt = null): Task;

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
	 * @throws TaskValidationException When a field is unknown or invalid.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-s-own-fields-can-be-edited-by-the-people-working-it
	 */
	public function edit(string $uuid, array $changes, ?string $actor): Task;

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
	 * @throws TaskConflictException When another claimer won, or the task is terminal.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function claim(string $uuid, ?string $actor): Task;

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
	 * Complete: the assignee finishes the work with an explicit outcome.
	 *
	 * A rejecting or returning outcome REQUIRES a non-empty comment; without
	 * one the verb is refused and the task keeps its pre-call state.
	 *
	 * @param string      $uuid   The task uuid.
	 * @param TaskAnswer  $answer The outcome (`approved`, `done`, `rejected`, ...), free-text result,
	 *                            comment and, when the completion carries them, the submitted answer
	 *                            fields and references to the files ALREADY stored for it (never bytes).
	 * @param string|null $actor  The completing identity — must be the assignee.
	 *
	 * @return Task The completed task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function completeWithAnswer(string $uuid, TaskAnswer $answer, ?string $actor): Task;

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
	 * @throws TaskValidationException When no item carries that id.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
	 */
	public function checkChecklistItem(string $uuid, string $itemId, bool $checked, ?string $actor): Task;

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
	 * Explicit propagation: a branch decision made this task moot.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $reason What made it moot — recorded, because "why did
	 *                       this disappear from my inbox" must stay answerable.
	 * @param string $source The propagation source recorded as actor.
	 *
	 * @return Task The terminated task, or the task untouched when already terminal.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function terminateAsMoot(string $uuid, string $reason, string $source): Task;

	/**
	 * Consume an approving decision: record that the work it authorized ran.
	 *
	 * The home of openconnector's `consumedAt` semantic
	 * (flow-approval-consolidation, HITL retirement inventory): an approved
	 * but unconsumed request must not silently re-authorize a later run. A
	 * TRUSTED in-process verb: the actor is the consuming system
	 * (`sync-run:<uuid>`, `flow-run:<uuid>`), recorded on the audit.
	 *
	 * Refused on a task that is not completed with an approving outcome, and
	 * refused AGAIN on one already consumed — the second refusal is the
	 * whole point. An outcome approves only when it is in the task's declared
	 * `approvingOutcomes`, or in {@see TaskState::APPROVING_OUTCOMES} when the
	 * task declares none; a completion that neither approves nor rejects is
	 * refused as undecided.
	 *
	 * @param string $uuid The decided task's uuid.
	 * @param string $source The consuming identity recorded as actor.
	 * @param string $reason What relied on the approval.
	 *
	 * @return Task The task with its consumption recorded.
	 *
	 * @throws TaskConflictException When not an unconsumed approving decision.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-human-in-the-loop-semantic-is-not-retired-until-it-has-a-named-home
	 * @spec openspec/changes/consume-follows-the-approving-rule/specs/flow-approval-consolidation/spec.md#requirement-a-human-in-the-loop-semantic-is-not-retired-until-it-has-a-named-home
	 */
	public function consume(string $uuid, string $source, string $reason): Task;

	/**
	 * Enable a position: the sequence's own promotion verb.
	 *
	 * The task-sequence equivalent of the twenty lines at the retired
	 * `ApprovalService.php:193-204` — a waiting position becomes the one
	 * position a person can act on. A TRUSTED in-process verb like
	 * {@see terminateAsMoot()}: the actor is a system source
	 * (`task-sequence:<uuid>`), recorded on the audit, and the caller is
	 * {@see TaskSequenceService}, which owns WHEN a position may be enabled.
	 * Not reachable over HTTP.
	 *
	 * Refused on a terminal task (the reason names its terminal state) and a
	 * no-op on a task that is already enabled or active.
	 *
	 * @param string      $uuid    The task uuid.
	 * @param TaskTrigger $trigger The enabling identity recorded as actor, and why, recorded on the audit.
	 *
	 * @return Task The enabled task.
	 *
	 * @throws TaskConflictException When the task is terminal, or closed concurrently.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function enable(string $uuid, TaskTrigger $trigger): Task;

	/**
	 * A business timer's ENFORCING outcome, applied as a named task action.
	 *
	 * The four outcomes leave the subject in four DISTINCT observable states
	 * (flow-business-timers design D-3): `skip` completes the task with
	 * outcome `skipped` so the process continues past the step; `error`
	 * terminates it with outcome `failed`; `dead_letter` disables it with
	 * outcome `dead_letter`, parked for an operator; `transition:<action>`
	 * completes it with `<action>` as outcome and audited action. One code
	 * path, one audit trail, and the audit names the timer as actor.
	 *
	 * Idempotent on an already-terminal task, and a lost race against a
	 * concurrent completion surfaces as {@see TaskConflictException}, which
	 * the sweep treats as "nothing to do".
	 *
	 * @param string $uuid The task uuid.
	 * @param string $outcome `skip`, `error`, `dead_letter` or `transition:<action>`.
	 * @param string $source The timer identity recorded as actor (`flow-timer:<uuid>`).
	 * @param string $reason Why, recorded on the audit.
	 *
	 * @return Task The task as left by the outcome, or untouched when already terminal.
	 *
	 * @throws TaskValidationException On an outcome outside the vocabulary.
	 * @throws TaskConflictException When the row was closed concurrently.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
	 */
	public function applyTimerOutcome(string $uuid, string $outcome, string $source, string $reason): Task;

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
	 * Every task carrying this external key, terminal ones included.
	 *
	 * A task added once per thing is looked up by its key before it is added
	 * again; a completed or cancelled one still counts as added.
	 *
	 * @param string $key The external key.
	 *
	 * @return array<int, Task> The tasks; empty for a blank key.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function findByKey(string $key): array;

	/**
	 * Resolve, authorize and audit a verb's task WITHOUT running the verb.
	 *
	 * For a caller that must do work between the authorization and the
	 * verb: the portal completion stores uploads on the case object before it
	 * records the completion, and a stranger must be refused, and the refusal
	 * audited, BEFORE any byte lands on a case that is not theirs. Same three
	 * checks in the same order as every verb (exists, authorized, open), so a
	 * denial here reads in the audit exactly as a denial from the verb would.
	 *
	 * @param string $verb The verb about to be attempted.
	 * @param string $uuid The task uuid.
	 * @param string|null $actor The acting identity.
	 *
	 * @return Task The open, authorized task.
	 *
	 * @throws TaskAccessDeniedException When authorization denies (audited).
	 * @throws TaskConflictException When the task is already terminal.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function openFor(string $verb, string $uuid, ?string $actor): Task;

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
	 * The audit trail of a task, oldest first.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return array<int, TaskAudit> The entries.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function auditTrail(string $uuid): array;

	/**
	 * A verb's task, resolved for a caller that writes something else FIRST.
	 *
	 * The task-form completion writes the subject object before it completes
	 * the task, and the task verb's authorization must be settled before a
	 * byte reaches the subject. This is {@see openTaskFor()} made reachable
	 * for that one caller: exists, authorized (a denial audited), open.
	 *
	 * @param TaskAct $act The verb about to be attempted and the acting identity.
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The open, authorized task.
	 *
	 * @throws TaskConflictException When the task is already terminal.
	 * @throws TaskAccessDeniedException When authorization denies (audited).
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	public function authorizedOpenTask(TaskAct $act, string $uuid): Task;

	/**
	 * Record that a completion was attempted and refused, without completing anything.
	 *
	 * Its own action name, so a refused attempt and a completion are
	 * distinguishable in the trail; `authorized` stays true because the caller
	 * WAS allowed to try and it was the payload that was refused. Outside any
	 * transaction, because nothing else changed. "The performer tried three
	 * times" is the signal that a form is wrong, which is why it is kept.
	 *
	 * @param Task $task The task the attempt was made on, unchanged.
	 * @param TaskAct $attempt The attempting identity and why the attempt was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-validation-failure-names-its-fields-and-completes-nothing
	 */
	public function recordRefusedCompletion(Task $task, TaskAct $attempt): void;
}//end interface
