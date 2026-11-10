<?php

/**
 * The task lifecycle: every verb authorized fail-closed, every mutation
 * audited in the same transaction.
 *
 * NOT the CalDAV VTODO leaf. `lib/Service/TaskService.php` (753L) wraps
 * VTODO items in the user's calendar and serves nc-vue's tasks leaf; THIS
 * class, namespaced under `Service\Task\`, is ADR-098's fleet-generic task
 * lifecycle. The two are kept apart by path, not by hope (design — Risks).
 *
 * Structural guarantees, in order of importance:
 * - No verb mutates before {@see TaskAuthorizationService::assertMay()} has
 *   passed, and a denial is itself audited (`authorized: false`).
 * - Every mutation and its audit entry commit in ONE transaction: a
 *   completed task without its audit entry is not a reachable state.
 * - `state` and `is_terminal` are written by one private method, in the
 *   same statement, so the materialised flag cannot drift.
 * - The candidate JSON and the candidate INDEX rows are maintained by one
 *   write path inside the transaction, so the pooled inbox cannot disagree
 *   with the readable record.
 * - The D-1 fence: no branch in this class encodes what a specific app's
 *   task MEANS. Every branch is lifecycle, identity or concurrency.
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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskSubjectNotFoundException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Creates, routes, claims, completes, cancels and terminates tasks.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods) The verbs, one private helper per
 * concern they share (open, authorize, audit, persist, announce), and the two
 * seams the task-form completion uses. Merging helpers to duck a count would
 * hide which rule a verb relies on.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One method per lifecycle
 * verb the spec names (create/offer/claim/unclaim/assign/reassign/delegate/
 * resolve/complete/cancel) plus the two propagation entry points. Merging
 * verbs would trade a countable surface for a mode parameter, which is how
 * authorization rules get forgotten.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service owns the
 * transaction across four mappers plus the authorization, routing and
 * vocabulary collaborators; that IS its job description.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Scales with the verb count;
 * each verb is short and single-purpose.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of twelve
 * verbs' guards. Each verb is short and its branches are the spec's own
 * rules (authorize, then terminality, then the verb's precondition, then
 * the conditional write); folding verbs together to lower the number would
 * hide exactly the per-verb rules the spec enumerates.
 * @SuppressWarnings(PHPMD.StaticAccess) TaskState is a stateless published
 * vocabulary (the one status mapping); calling it statically is the point,
 * an instance would be a second copy of the same table.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskService implements TaskServiceInterface, TaskManagerInterface {

	/**
	 * The fields only the task sequence sets, through {@see import()}.
	 *
	 * @var array<int, string>
	 */
	private const SEQUENCE_FIELDS = ['sequenceUuid', 'sequencePosition', 'legacyStepId'];

	/**
	 * Constructor.
	 *
	 * @param TaskAuthorizationService $authorization The per-verb, fail-closed
	 *                                                decisions.
	 * @param TaskLifecycleServices $services The tables, the commit (transaction,
	 *                                        logger, after-commit announcer),
	 *                                        the intake (builder, form reader,
	 *                                        subject locator, editor) and the
	 *                                        routing strategies.
	 * @param TaskSequenceDecisionGuard|null $sequenceGuard Refuses a
	 *                                                      self-decision on a
	 *                                                      sequence position
	 *                                                      BEFORE the performer
	 *                                                      check, so the reason
	 *                                                      is honest. Nullable
	 *                                                      for hand-built test
	 *                                                      services; absent, no
	 *                                                      sequence policy is
	 *                                                      enforced here.
	 * @param TaskSubjectAccessGuard|null $subjects Refuses a CREATE whose
	 *                                              subject object the caller
	 *                                              may not read. Absence does
	 *                                              NOT mean "skipped": the
	 *                                              guard itself refuses every
	 *                                              named subject when it has no
	 *                                              object service to ask, so a
	 *                                              missing collaborator denies
	 *                                              rather than admits.
	 */
	public function __construct(
		private readonly TaskAuthorizationService $authorization,
		private readonly TaskLifecycleServices $services,
		private readonly ?TaskSequenceDecisionGuard $sequenceGuard = null,
		private readonly ?TaskSubjectAccessGuard $subjects = null,
	) {

	}//end __construct()

	/**
	 * What the task looked like BEFORE the verb in flight, for the
	 * post-commit announcement: previous assignee, previous state, actor.
	 *
	 * Set by {@see openTaskFor()} and {@see create()}, consumed once by
	 * {@see transactional()}. Verbs that load their task another way
	 * (termination by propagation) announce with no previous snapshot,
	 * which is correct: termination changes no assignee.
	 *
	 * @var array{assignee: string|null, state: string|null, actor: string|null}|null
	 */
	private ?array $pending = null;

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
	public function create(array $data, ?string $actor): Task {
		$this->refuseSequenceFields(data: $data);

		// Located first, so the access guard below reads the subject from its
		// own table instead of searching every magic table for it. A refusal
		// reads the same either way: location says where, never whether.
		$data = $this->locate(data: $data);

		// Blocked is derived from blockedBy, for administrators too: nobody
		// writes the state by hand (a-task-may-wait-on-another-task D-6).
		(new TaskBlockerGuard(tasks: $this->services->tables->tasks))->refuseHandWrittenState(data: $data);

		if ($this->authorization->isAdministrator(uid: $actor) === false) {
			// A task is an annotation ON an object, so it inherits that
			// object's read authorization: knowing a uuid is not entitlement
			// to write onto what it names, and this endpoint used to treat it
			// as exactly that. The check runs BEFORE any validation or write,
			// and before the requester is pinned, so a refused caller leaves
			// no trace on the record and learns nothing about the object.
			// Administrators skip it for the reason ObjectsController::show()
			// skips RBAC for them: they read every object, so it could only
			// pass.
			($this->subjects ?? new TaskSubjectAccessGuard())->assertReadable(data: $data);

			// An ordinary caller is the requester of what they create: they
			// may not write somebody else's name into the seat that owns
			// cancel and reassign. And they may not create a task that is
			// born closed (state 'approved' maps to completed with nobody
			// having completed it): those states arrive only through the
			// verbs, or through a trusted migration path.
			$data['requester'] = $actor;
			$state = (string)($data['state'] ?? Task::STATE_AVAILABLE);
			if (TaskState::isTerminal(state: TaskState::normalise(value: $state)['state']) === true) {
				throw new TaskValidationException(
					message: sprintf("A task cannot be created in terminal state '%s'; it reaches that state through a lifecycle verb.", $state)
				);
			}
		}

		// An ordinary caller may only name a blocker they may read; an
		// administrator reads every task, so for them it could only pass.
		$mayReadBlocker = null;
		if ($this->authorization->isAdministrator(uid: $actor) === false) {
			$mayReadBlocker = fn (Task $blocker): bool => $this->authorization->mayRead(task: $blocker, uid: $actor);
		}

		return $this->import(data: $data, actor: $actor, mayReadBlocker: $mayReadBlocker);
	}//end create()

	/**
	 * Refuse a sequence field on the create path, naming it.
	 *
	 * @param array<string, mixed> $data The task fields.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When a sequence field carries a value.
	 *
	 * @spec openspec/changes/sequence-fields-are-set-by-the-sequence-service-only/specs/flow-approval-consolidation/spec.md#requirement-only-the-task-sequence-sets-a-tasks-sequence-fields
	 */
	private function refuseSequenceFields(array $data): void {
		$given = [];
		foreach (self::SEQUENCE_FIELDS as $field) {
			if (($data[$field] ?? null) !== null) {
				$given[] = $field;
			}
		}

		$snapshot = ($data['templateSnapshot'] ?? null);
		if (is_array($snapshot) === true && array_key_exists('approvingOutcomes', $snapshot) === true) {
			$given[] = 'templateSnapshot.approvingOutcomes';
		}

		if ($given !== []) {
			throw new TaskValidationException(
				message: sprintf("Field '%s' is set by the task sequence only and cannot be given when creating a task.", $given[0])
			);
		}
	}//end refuseSequenceFields()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-task-may-carry-the-moment-it-was-created
	 */
	public function import(array $data, ?string $actor, ?callable $mayReadBlocker = null, ?DateTime $createdAt = null): Task {
		$data = $this->locate(data: $data);
		$task = $this->services->intake->builder->fromData(data: $data, actor: $actor);
		$task->setCreated($createdAt);
		$this->refuseUnrenderableForm(task: $task);
		(new TaskBlockerGuard(tasks: $this->services->tables->tasks))->assertDeclarable(task: $task, mayRead: $mayReadBlocker);
		$act = $this->authorizedCreation(task: $task, actor: $actor);

		return $this->transactional(mutation: fn (): Task => $this->audited(task: $this->inserted(task: $task, data: $data), act: $act));
	}//end import()

	/**
	 * Authorize the creation of a built task, and remember that it had no
	 * previous assignee or state for the post-commit announcement.
	 *
	 * @param Task $task The built, not yet persisted task.
	 * @param string|null $actor The creating identity.
	 *
	 * @return TaskAct The authorized creation.
	 *
	 * @throws TaskAccessDeniedException When the creation is refused.
	 */
	private function authorizedCreation(Task $task, ?string $actor): TaskAct {
		$act = new TaskAct(verb: 'create', actor: $actor);
		$this->authorizeOrRecord(task: $task, act: $act);
		$this->pending = [
			'assignee' => null,
			'state' => null,
			'actor' => $actor,
		];

		return $act;
	}//end authorizedCreation()

	/**
	 * Insert a new task with its candidate index and relations.
	 *
	 * Runs inside the creation's transaction.
	 *
	 * @param Task $task The built task.
	 * @param array<string, mixed> $data The boundary data its relations come from.
	 *
	 * @return Task The persisted task.
	 */
	private function inserted(Task $task, array $data): Task {
		$persisted = $this->services->tables->tasks->insert($task);
		$this->rewriteCandidateIndex(task: $persisted);
		$this->insertRelations(task: $persisted, data: $data);

		return $persisted;
	}//end inserted()

	/**
	 * Write the relation rows the payload declares for a just-inserted task.
	 *
	 * @param Task $task The inserted task.
	 * @param array<string, mixed> $data The task payload.
	 *
	 * @return void
	 */
	private function insertRelations(Task $task, array $data): void {
		foreach ($this->services->intake->builder->relationsFor(task: $task, data: $data) as $relation) {
			$this->services->tables->relations->insert(entity: $relation);
		}
	}//end insertRelations()

	/**
	 * Record which register and schema the task's subject lives in, when the
	 * caller did not say.
	 *
	 * Without them every inbox read searches all magic tables for the subject
	 * (measured 2026-10-06: 1.5 to 6 s per `GET /api/flow-tasks` on 1,664
	 * tables). One lookup here saves that on every later read.
	 *
	 * @param array<string, mixed> $data The task fields.
	 *
	 * @return array<string, mixed> The task fields, located where possible.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function locate(array $data): array {
		if ($this->services->intake->locator === null) {
			return $data;
		}

		return $this->services->intake->locator->withLocation(data: $data);
	}//end locate()

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
	public function offer(string $uuid, array $pool, ?string $actor): Task {
		$task = $this->openUnassigned(act: new TaskAct(verb: 'offer', actor: $actor), uuid: $uuid);

		return $this->transactional(mutation: fn (): Task => $this->reroute(task: $this->pooled(task: $task, pool: $pool), actor: $actor));
	}//end offer()

	/**
	 * Open a task for an offer, refusing an assigned one.
	 *
	 * An assigned task is not offerable: offering rewrites the pool and the
	 * routing fallback, which decide who ends up assigned, so on an assigned
	 * task it would be a reassignment wearing a different name and a
	 * different authorization rule. Unclaim or reassign first.
	 *
	 * @param TaskAct $act The offer.
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The opened, unassigned task.
	 *
	 * @throws TaskConflictException When the task has an assignee.
	 */
	private function openUnassigned(TaskAct $act, string $uuid): Task {
		$task = $this->openTaskFor(act: $act, uuid: $uuid);
		if (trim((string)$task->getAssignee()) !== '') {
			throw new TaskConflictException(
				message: sprintf("Verb 'offer' refused: task '%s' is already assigned; unclaim or reassign it instead.", $uuid)
			);
		}

		return $task;
	}//end openUnassigned()

	/**
	 * Route an offered task through its new pool, persist and audit it.
	 *
	 * Runs inside the verb's transaction.
	 *
	 * @param Task $task The task with its new pool applied.
	 * @param string|null $actor The offering identity.
	 *
	 * @return Task The persisted task.
	 */
	private function reroute(Task $task, ?string $actor): Task {
		$act = new TaskAct(verb: 'offer', actor: $actor, state: $this->route(task: $task));
		$this->applyState(task: $task, act: $act);

		$persisted = $this->persistOpen(task: $task);
		$this->rewriteCandidateIndex(task: $persisted);
		$this->appendAudit(task: $persisted, act: $act);

		return $persisted;
	}//end reroute()

	/**
	 * Set the routing fields an offer sent.
	 *
	 * Only the keys the caller sent change; an absent key keeps the stored
	 * value, so offer can adjust one routing field.
	 *
	 * @param Task $task The task being offered.
	 * @param array<string, mixed> $pool The sent routing fields.
	 *
	 * @return Task The same task, with its pool applied.
	 */
	private function pooled(Task $task, array $pool): Task {
		$settable = [
			'candidateUsers' => 'setCandidateUsers',
			'candidateGroups' => 'setCandidateGroups',
			'candidateRole' => 'setCandidateRole',
			'routingStrategy' => 'setRoutingStrategy',
			'routingFallback' => 'setRoutingFallback',
		];
		foreach ($settable as $key => $setter) {
			if (array_key_exists($key, $pool) === true) {
				$task->$setter($pool[$key]);
			}
		}

		return $task;
	}//end pooled()

	/**
	 * Run the routing strategy and assign its answer.
	 *
	 * Routing either names somebody (active) or leaves the task pooled
	 * (enabled). Never a third option.
	 *
	 * @param Task $task The offered task.
	 *
	 * @return string The state the routing leaves the task in.
	 */
	private function route(Task $task): string {
		$chosen = $this->services->resolver->resolveAssignee(task: $task);
		$task->setAssignee($chosen);
		if ($chosen !== null) {
			return Task::STATE_ACTIVE;
		}

		return Task::STATE_ENABLED;
	}//end route()

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
	public function edit(string $uuid, array $changes, ?string $actor): Task {
		$task = $this->openTaskFor(act: new TaskAct(verb: 'edit', actor: $actor), uuid: $uuid);
		$changed = ($this->services->intake->editor ?? new TaskEdit())->apply(task: $task, changes: $changes);

		return $this->saveEdit(task: $task, act: new TaskAct(verb: 'edit', actor: $actor, reason: $this->editReason(changed: $changed)));
	}//end edit()

	/**
	 * Persist and audit an applied edit, or nothing when nothing changed.
	 *
	 * @param Task $task The edited task.
	 * @param TaskAct $act The edit, its reason naming the changed fields
	 *                     (null when there are none).
	 *
	 * @return Task The persisted task, or the task as it was.
	 */
	private function saveEdit(Task $task, TaskAct $act): Task {
		if ($act->reason === null) {
			return $task;
		}

		return $this->transactional(mutation: fn (): Task => $this->persistAudited(task: $task, act: $act));
	}//end saveEdit()

	/**
	 * The audit reason of an edit: the changed fields, or null for none.
	 *
	 * @param array<int, string> $changed The changed field names.
	 *
	 * @return string|null The reason.
	 */
	private function editReason(array $changed): ?string {
		if ($changed === []) {
			return null;
		}

		return sprintf('Changed: %s.', implode(', ', $changed));
	}//end editReason()

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
	public function claim(string $uuid, ?string $actor): Task {
		$act = new TaskAct(verb: 'claim', actor: $actor);
		$task = $this->openTaskFor(act: $act, uuid: $uuid);

		return $this->transactional(
			mutation: function () use ($task, $act): Task {
				$won = $this->services->tables->tasks->claim(taskId: (int)$task->getId(), uid: (string)$act->actor);
				if ($won === false) {
					throw new TaskConflictException(
						message: sprintf("Task '%s' was not claimable: another claim won, or the task is no longer open.", (string)$task->getUuid())
					);
				}

				$fresh = $this->services->tables->tasks->findByUuid(uuid: (string)$task->getUuid());
				$this->appendAudit(task: $fresh, act: $act);

				return $fresh;
			}
		);
	}//end claim()

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
	public function unclaim(string $uuid, ?string $actor): Task {
		$act = new TaskAct(verb: 'unclaim', actor: $actor, state: Task::STATE_ENABLED);
		$task = $this->openTaskFor(act: $act, uuid: $uuid);

		return $this->transactional(
			mutation: function () use ($task, $act): Task {
				$task->setAssignee(null);
				$task->setOnBehalfOf(null);
				$task->setMandate(null);

				return $this->settle(task: $task, act: $act);
			}
		);
	}//end unclaim()

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
	public function assign(string $uuid, string $assignee, ?string $actor): Task {
		$act = new TaskAct(verb: 'assign', actor: $actor, state: Task::STATE_ACTIVE);

		return $this->assignInternal(act: $act, assignment: new TaskAssignment(uuid: $uuid, assignee: $assignee));
	}//end assign()

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
	public function reassign(string $uuid, string $assignee, ?string $actor): Task {
		$act = new TaskAct(verb: 'reassign', actor: $actor, state: Task::STATE_ACTIVE);

		return $this->assignInternal(act: $act, assignment: new TaskAssignment(uuid: $uuid, assignee: $assignee));
	}//end reassign()

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
	public function delegate(string $uuid, string $delegate, string $mandate, ?string $actor): Task {
		$act = new TaskAct(verb: 'delegate', actor: $actor, reason: $mandate, state: Task::STATE_ACTIVE);
		$task = $this->openTaskFor(act: $act, uuid: $uuid);
		$delegation = $this->delegation(delegate: $delegate, mandate: $mandate);

		return $this->transactional(mutation: fn (): Task => $this->settle(task: $this->handedOver(task: $task, delegation: $delegation), act: $act));
	}//end delegate()

	/**
	 * A delegation, refused when its delegate or mandate is blank.
	 *
	 * @param string $delegate The delegate.
	 * @param string $mandate The authority relied on.
	 *
	 * @return TaskDelegation The delegation.
	 *
	 * @throws TaskValidationException On a blank delegate or mandate.
	 */
	private function delegation(string $delegate, string $mandate): TaskDelegation {
		$this->refuseBlank(value: $delegate, message: 'A delegation requires a non-empty delegate.');
		$this->refuseBlank(value: $mandate, message: 'A delegation requires a mandate naming the authority relied on.');

		return new TaskDelegation(delegate: $delegate, mandate: $mandate);
	}//end delegation()

	/**
	 * Hand a task over to a delegate.
	 *
	 * A re-delegation keeps naming the ORIGINAL performer: the chain of
	 * delegates is in the audit, the accountable party is the one who never
	 * changes.
	 *
	 * @param Task $task The task.
	 * @param TaskDelegation $delegation The delegate and mandate.
	 *
	 * @return Task The same task, handed over.
	 */
	private function handedOver(Task $task, TaskDelegation $delegation): Task {
		$task->setOnBehalfOf($task->getOnBehalfOf() ?? $task->getAssignee());
		$task->setAssignee($delegation->delegate);
		$task->setMandate($delegation->mandate);

		return $task;
	}//end handedOver()

	/**
	 * Refuse a value that is empty once trimmed.
	 *
	 * @param string $value The given value.
	 * @param string $message The refusal message.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When the value is blank.
	 */
	private function refuseBlank(string $value, string $message): void {
		if (trim($value) === '') {
			throw new TaskValidationException(message: $message);
		}
	}//end refuseBlank()

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
	public function resolve(string $uuid, ?string $resultText, ?string $comment, ?string $actor): Task {
		$answer = new TaskAnswer(outcome: 'resolved', resultText: $resultText, comment: $comment);

		return $this->finish(completion: new TaskCompletion(verb: 'resolve', uuid: $uuid, answer: $answer, actor: $actor));
	}//end resolve()

	/**
	 * Complete, with the answer as separate values.
	 *
	 * Kept for the portal completion, which an open change rewrites; it builds
	 * the answer and delegates to {@see self::completeWithAnswer()}.
	 *
	 * @param string                                $uuid       The task uuid.
	 * @param string                                $outcome    The outcome (`approved`, `done`, `rejected`, ...).
	 * @param string|null                           $resultText Free-text result.
	 * @param string|null                           $comment    Completion comment.
	 * @param string|null                           $actor      The completing identity — must be the assignee.
	 * @param array<string, mixed>|null             $responses  The submitted answer fields, if any.
	 * @param array<int, array<string, mixed>>|null $evidence   References to the files ALREADY stored; never bytes.
	 *
	 * @return Task The completed task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function complete(
		string $uuid, string $outcome, ?string $resultText, ?string $comment, ?string $actor, ?array $responses = null, ?array $evidence = null
	): Task {
		$payload = new TaskAnswerPayload(responses: $responses, evidence: $evidence);
		$answer  = new TaskAnswer(outcome: $outcome, resultText: $resultText, comment: $comment, payload: $payload);
		return $this->completeWithAnswer(uuid: $uuid, answer: $answer, actor: $actor);
	}//end complete()

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
	public function completeWithAnswer(string $uuid, TaskAnswer $answer, ?string $actor): Task {
		return $this->finish(completion: new TaskCompletion(verb: 'complete', uuid: $uuid, answer: $answer, actor: $actor));
	}//end completeWithAnswer()

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
	public function cancel(string $uuid, ?string $reason, ?string $actor): Task {
		$act = new TaskAct(verb: 'cancel', actor: $actor, reason: $reason, state: Task::STATE_TERMINATED);
		$task = $this->openTaskFor(act: $act, uuid: $uuid);

		return $this->transactional(mutation: fn (): Task => $this->settle(task: $this->withOutcome(task: $task, outcome: 'cancelled'), act: $act));
	}//end cancel()

	/**
	 * Record the outcome a closing verb gives a task.
	 *
	 * @param Task $task The task.
	 * @param string $outcome The outcome.
	 *
	 * @return Task The same task, with the outcome set.
	 */
	private function withOutcome(Task $task, string $outcome): Task {
		$task->setOutcome($outcome);

		return $task;
	}//end withOutcome()

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
	public function checkChecklistItem(string $uuid, string $itemId, bool $checked, ?string $actor): Task {
		$task = $this->openTaskFor(act: new TaskAct(verb: 'checklist', actor: $actor), uuid: $uuid);
		$tick = new ChecklistTick(itemId: $itemId, checked: $checked);
		$act = new TaskAct(verb: 'checklist', actor: $actor, reason: $tick->reason());

		return $this->transactional(mutation: fn (): Task => $this->persistAudited(task: $this->ticked(task: $task, tick: $tick), act: $act));
	}//end checkChecklistItem()

	/**
	 * Set one checklist item's checked flag.
	 *
	 * @param Task $task The task.
	 * @param ChecklistTick $tick The item and its new flag.
	 *
	 * @return Task The same task, with its checklist updated.
	 *
	 * @throws TaskValidationException When the item does not exist.
	 */
	private function ticked(Task $task, ChecklistTick $tick): Task {
		$checklist = ($task->getChecklist() ?? []);
		$checklist[$this->checklistIndex(task: $task, itemId: $tick->itemId)]['checked'] = $tick->checked;
		$task->setChecklist($checklist);

		return $task;
	}//end ticked()

	/**
	 * The index of the first checklist item with the given id.
	 *
	 * @param Task $task The task.
	 * @param string $itemId The item id.
	 *
	 * @return int|string The item's index in the checklist.
	 *
	 * @throws TaskValidationException When no item has that id.
	 */
	private function checklistIndex(Task $task, string $itemId): int|string {
		foreach (($task->getChecklist() ?? []) as $index => $item) {
			if (is_array($item) === true && (string)($item['id'] ?? '') === $itemId) {
				return $index;
			}
		}

		throw new TaskValidationException(
			message: sprintf("Checklist item '%s' does not exist on task '%s'.", $itemId, (string)$task->getUuid())
		);
	}//end checklistIndex()

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
	public function terminateForRun(string $runUuid, string $runStatus): int {
		if (trim($runUuid) === '') {
			return 0;
		}

		$open = $this->services->tables->tasks->findOpenByRunUuid(runUuid: $runUuid);
		$act = new TaskAct(
			verb: 'terminate',
			actor: sprintf('flow-run:%s', $runUuid),
			reason: sprintf("Run '%s' reached terminal status '%s'.", $runUuid, $runStatus),
			state: Task::STATE_TERMINATED
		);
		$terminated = 0;
		$failed = [];
		foreach ($open as $task) {
			// One task's failure must not orphan the rest of the run's
			// tasks: each is its own transaction, and a failure is logged
			// and counted, then the loop continues. A task closed
			// concurrently (conflict) is already where propagation wanted it.
			try {
				$this->transactional(
					mutation: function () use ($task, $act): Task {
						$task->setOutcome('terminated');
						$task->setBlockedReason(null);

						return $this->settle(task: $task, act: $act);
					}
				);
				$terminated++;
			} catch (TaskConflictException) {
				// Closed by somebody else in the meantime: not our failure.
				continue;
			} catch (Throwable $failure) {
				$failed[] = (string)$task->getUuid();
				$this->services->commit->logger->error(
					'[TaskService] Propagation could not terminate a task: ' . $failure->getMessage(),
					['task' => $task->getUuid(), 'run' => $runUuid, 'exception' => $failure]
				);
			}//end try
		}//end foreach

		$this->warnUntouched(runUuid: $runUuid, failed: $failed);

		return $terminated;
	}//end terminateForRun()

	/**
	 * Warn about the tasks a run's propagation could not terminate.
	 *
	 * @param string $runUuid The run.
	 * @param array<int, string> $failed The uuids left open.
	 *
	 * @return void
	 */
	private function warnUntouched(string $runUuid, array $failed): void {
		if ($failed !== []) {
			$this->services->commit->logger->warning(
				sprintf('[TaskService] Propagation for run %s left %d task(s) untouched: %s', $runUuid, count($failed), implode(', ', $failed))
			);
		}
	}//end warnUntouched()

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
	public function terminateAsMoot(string $uuid, string $reason, string $source): Task {
		$act = new TaskAct(verb: 'terminate', actor: $source, reason: $reason, state: Task::STATE_TERMINATED);

		return $this->terminateOpen(task: $this->services->tables->tasks->findByUuid(uuid: $uuid), act: $act);
	}//end terminateAsMoot()

	/**
	 * Terminate a task that is still open; an already-terminal task stays
	 * as it ended (idempotent).
	 *
	 * @param Task $task The task.
	 * @param TaskAct $act The termination.
	 *
	 * @return Task The terminated task, or the task as it ended.
	 */
	private function terminateOpen(Task $task, TaskAct $act): Task {
		if ($task->isInTerminalState() === true) {
			return $task;
		}

		return $this->transactional(mutation: fn (): Task => $this->settle(task: $this->withOutcome(task: $task, outcome: 'terminated'), act: $act));
	}//end terminateOpen()

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
	public function consume(string $uuid, string $source, string $reason): Task {
		$task = $this->unconsumedApproval(uuid: $uuid);
		$this->markConsumed(task: $task, source: $source);

		return $this->updateAudited(task: $task, act: new TaskAct(verb: 'consume', actor: $source, reason: $reason));
	}//end consume()

	/**
	 * Refuse a task that is not an approving decision, or whose approval
	 * was already consumed.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The task, consumable.
	 *
	 * @throws TaskConflictException When it is not consumable.
	 */
	private function unconsumedApproval(string $uuid): Task {
		$task = $this->services->tables->tasks->findByUuid(uuid: $uuid);
		$this->assertApprovingDecision(task: $task);
		$this->refuseConsumed(task: $task, uuid: $uuid);

		return $task;
	}//end unconsumedApproval()

	/**
	 * Refuse an approval that was consumed before: it authorizes exactly once.
	 *
	 * @param Task $task The approving task.
	 * @param string $uuid The uuid the caller named.
	 *
	 * @return void
	 *
	 * @throws TaskConflictException When the approval was already consumed.
	 */
	private function refuseConsumed(Task $task, string $uuid): void {
		$metadata = ($task->getMetadata() ?? []);
		if (trim((string)($metadata['consumedAt'] ?? '')) !== '') {
			throw new TaskConflictException(
				message: sprintf(
					"Verb 'consume' refused: the approval on task '%s' was already consumed at %s by %s, and an approval authorizes exactly once.",
					$uuid,
					(string)$metadata['consumedAt'],
					(string)($metadata['consumedBy'] ?? 'an unrecorded consumer')
				)
			);
		}
	}//end refuseConsumed()

	/**
	 * Stamp the consumption on the task's metadata.
	 *
	 * @param Task $task The approving task.
	 * @param string $source The consuming source.
	 *
	 * @return void
	 */
	private function markConsumed(Task $task, string $source): void {
		$metadata = ($task->getMetadata() ?? []);
		$metadata['consumedAt'] = (new DateTime())->format('c');
		$metadata['consumedBy'] = $source;
		$task->setMetadata($metadata);
	}//end markConsumed()

	/**
	 * Update a task and audit the act in one transaction, with no open check.
	 *
	 * The consumed approval is terminal, so the open-only write would refuse
	 * it.
	 *
	 * @param Task $task The task to write.
	 * @param TaskAct $act The act to audit.
	 *
	 * @return Task The persisted task.
	 */
	private function updateAudited(Task $task, TaskAct $act): Task {
		// A hand-rolled transaction instead of transactional(): the task was
		// terminal long before this call, and re-announcing its terminality
		// here would replay run continuation and sequence progression for a
		// decision that already happened.
		$this->services->commit->db->beginTransaction();
		try {
			$persisted = $this->services->tables->tasks->update($task);
			$this->appendAudit(task: $persisted, act: $act);
			$this->services->commit->db->commit();
		} catch (Throwable $failure) {
			$this->services->commit->db->rollBack();
			throw $failure;
		}

		return $persisted;
	}//end updateAudited()

	/**
	 * Refuse anything but a completed task with an approving outcome.
	 *
	 * @param Task $task The decided task.
	 *
	 * @return void
	 *
	 * @throws TaskConflictException When the task is open, rejected or undecided.
	 *
	 * @spec openspec/changes/consume-follows-the-approving-rule/specs/flow-approval-consolidation/spec.md#requirement-a-human-in-the-loop-semantic-is-not-retired-until-it-has-a-named-home
	 */
	private function assertApprovingDecision(Task $task): void {
		$completed = ((string)$task->getState() === Task::STATE_COMPLETED);
		if ($completed === false || TaskState::isRejectingOutcome(outcome: $task->getOutcome()) === true) {
			throw new TaskConflictException(
				message: sprintf(
					"Verb 'consume' refused: task '%s' is not an approving decision (state '%s', outcome '%s').",
					(string)$task->getUuid(),
					(string)$task->getState(),
					(string)$task->getOutcome()
				)
			);
		}

		$approving = TaskState::approvingSetOrDefault(declared: (($task->getTemplateSnapshot() ?? [])['approvingOutcomes'] ?? null));
		if (TaskState::isApprovingOutcome(outcome: $task->getOutcome(), approving: $approving) === false) {
			throw new TaskConflictException(
				message: sprintf(
					"Verb 'consume' refused: task '%s' is undecided: outcome '%s' neither approves nor rejects.",
					(string)$task->getUuid(),
					(string)$task->getOutcome()
				)
			);
		}
	}//end assertApprovingDecision()

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
	public function enable(string $uuid, TaskTrigger $trigger): Task {
		$act = new TaskAct(verb: 'enable', actor: $trigger->source, reason: $trigger->reason, state: Task::STATE_ENABLED);

		return $this->enableWaiting(uuid: $uuid, act: $act);
	}//end enable()

	/**
	 * Enable a waiting task; an enabled or active one is left as it is.
	 *
	 * @param string $uuid The task uuid.
	 * @param TaskAct $act The enabling.
	 *
	 * @return Task The enabled task.
	 *
	 * @throws TaskConflictException When the task is already terminal.
	 */
	private function enableWaiting(string $uuid, TaskAct $act): Task {
		$task = $this->services->tables->tasks->findByUuid(uuid: $uuid);
		if ($task->isInTerminalState() === true) {
			throw new TaskConflictException(
				message: sprintf("Verb 'enable' refused: task '%s' is already in terminal state '%s'.", $uuid, (string)$task->getState())
			);
		}

		if ($task->getState() === Task::STATE_ENABLED || $task->getState() === Task::STATE_ACTIVE) {
			// Idempotent: enabling the enabled position changes nothing.
			return $task;
		}

		return $this->transactional(mutation: fn (): Task => $this->settle(task: $task, act: $act));
	}//end enableWaiting()

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
	public function applyTimerOutcome(string $uuid, string $outcome, string $source, string $reason): Task {
		[$state, $recorded, $action] = $this->timerOutcomeTarget(outcome: $outcome);
		$act = new TaskAct(verb: $action, actor: $source, reason: $reason, state: $state);

		return $this->closeByTimer(task: $this->services->tables->tasks->findByUuid(uuid: $uuid), closing: new TaskClosing(act: $act, outcome: $recorded));
	}//end applyTimerOutcome()

	/**
	 * Close an open task the way a timer outcome says; an already-terminal
	 * task stays as it ended (idempotent).
	 *
	 * @param Task $task The task.
	 * @param TaskClosing $closing The act and the outcome to record.
	 *
	 * @return Task The closed task, or the task as it ended.
	 */
	private function closeByTimer(Task $task, TaskClosing $closing): Task {
		if ($task->isInTerminalState() === true) {
			return $task;
		}

		return $this->transactional(mutation: fn (): Task => $this->settle(task: $this->timedOut(task: $task, closing: $closing), act: $closing->act));
	}//end closeByTimer()

	/**
	 * Write a timer outcome onto a task: the outcome, no blocker, and the
	 * completion stamp when the outcome completes it.
	 *
	 * @param Task $task The task.
	 * @param TaskClosing $closing The act and the outcome to record.
	 *
	 * @return Task The same task.
	 */
	private function timedOut(Task $task, TaskClosing $closing): Task {
		$task->setOutcome($closing->outcome);
		$task->setBlockedReason(null);
		if ($closing->act->state === Task::STATE_COMPLETED) {
			$task->setCompletedAt(new DateTime());
			$task->setCompletedBy($closing->act->actor);
		}

		return $task;
	}//end timedOut()

	/**
	 * The (state, outcome, action) an enforcing outcome maps to.
	 *
	 * @param string $outcome The declared outcome.
	 *
	 * @return array{0: string, 1: string, 2: string} Target state, recorded outcome, audited action.
	 *
	 * @throws TaskValidationException On an outcome outside the vocabulary.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
	 */
	private function timerOutcomeTarget(string $outcome): array {
		$reserved = [
			'skip' => [Task::STATE_COMPLETED, 'skipped', 'skip'],
			'error' => [Task::STATE_TERMINATED, 'failed', 'error'],
			'dead_letter' => [Task::STATE_DISABLED, 'dead_letter', 'dead_letter'],
		];
		if (array_key_exists($outcome, $reserved) === true) {
			return $reserved[$outcome];
		}

		if (str_starts_with($outcome, 'transition:') === true) {
			return $this->transitionTarget(outcome: $outcome, reserved: $reserved);
		}

		throw new TaskValidationException(
			message: sprintf("Timer outcome '%s' is refused: use skip, error, dead_letter or transition:<action>.", $outcome)
		);
	}//end timerOutcomeTarget()

	/**
	 * The target of a `transition:<action>` timer outcome.
	 *
	 * @param string $outcome The outcome, starting with `transition:`.
	 * @param array<string, array{0: string, 1: string, 2: string}> $reserved The reserved outcomes.
	 *
	 * @return array{0: string, 1: string, 2: string} [state, recorded outcome, audit action].
	 *
	 * @throws TaskValidationException When the action is empty, too long or reserved.
	 */
	private function transitionTarget(string $outcome, array $reserved): array {
		$action = trim(substr($outcome, strlen('transition:')));
		if ($action === '' || strlen($action) > 32 || array_key_exists($action, $reserved) === true) {
			throw new TaskValidationException(
				message: sprintf("Timer outcome '%s' names no usable action: it must be 1..32 characters and not a reserved outcome.", $outcome)
			);
		}

		return [Task::STATE_COMPLETED, $action, $action];
	}//end transitionTarget()

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
	public function get(string $uuid): Task {
		return $this->services->tables->tasks->findByUuid(uuid: $uuid);
	}//end get()

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
	public function findByKey(string $key): array {
		$key = trim($key);
		if ($key === '') {
			return [];
		}

		return $this->services->tables->tasks->findByKey(key: $key);
	}//end findByKey()

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
	 * @param TaskAct $act  The verb about to be attempted and the acting identity.
	 * @param string  $uuid The task uuid.
	 *
	 * @return Task The open, authorized task.
	 *
	 * @throws TaskAccessDeniedException When authorization denies (audited).
	 * @throws TaskConflictException When the task is already terminal.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function openFor(TaskAct $act, string $uuid): Task {
		return $this->openTaskFor(act: $act, uuid: $uuid);
	}//end openFor()

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
	public function record(string $uuid, string $action, ?string $actor, string $reason): Task {
		$task = $this->services->tables->tasks->findByUuid(uuid: $uuid);
		$act = new TaskAct(verb: $action, actor: $actor, reason: $reason);

		return $this->transactional(mutation: fn (): Task => $this->audited(task: $task, act: $act));
	}//end record()

	/**
	 * Append an audit entry without changing the task.
	 *
	 * @param Task $task The task.
	 * @param TaskAct $act The recorded action.
	 *
	 * @return Task The same task.
	 */
	private function audited(Task $task, TaskAct $act): Task {
		$this->appendAudit(task: $task, act: $act);

		return $task;
	}//end audited()

	/**
	 * The audit trail of a task, oldest first.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return array<int, TaskAudit> The entries.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function auditTrail(string $uuid): array {
		$task = $this->services->tables->tasks->findByUuid(uuid: $uuid);

		return $this->services->tables->audits->findForTask(taskId: (int)$task->getId());
	}//end auditTrail()

	/**
	 * Shared body of assign and reassign.
	 *
	 * @param TaskAct $act The verb (assign or reassign), actor and target state.
	 * @param TaskAssignment $assignment The task uuid and the new assignee.
	 *
	 * @return Task The assigned task.
	 *
	 * @throws TaskValidationException When the assignee is blank.
	 */
	private function assignInternal(TaskAct $act, TaskAssignment $assignment): Task {
		$task = $this->openTaskFor(act: $act, uuid: $assignment->uuid);
		$this->refuseBlank(value: $assignment->assignee, message: 'An assignment requires a non-empty assignee.');

		$assigned = fn (): Task => $this->assignedTo(task: $task, assignee: $assignment->assignee);

		return $this->transactional(mutation: fn (): Task => $this->settle(task: $assigned(), act: $act));
	}//end assignInternal()

	/**
	 * Make an identity the task's own performer, ending any delegation.
	 *
	 * @param Task $task The task.
	 * @param string $assignee The new assignee.
	 *
	 * @return Task The same task.
	 */
	private function assignedTo(Task $task, string $assignee): Task {
		$task->setAssignee($assignee);
		$task->setOnBehalfOf(null);
		$task->setMandate(null);

		return $task;
	}//end assignedTo()

	/**
	 * Shared body of resolve and complete.
	 *
	 * @param TaskCompletion $completion The verb, task, outcome, payload and actor.
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskValidationException When a rejecting outcome has no comment.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 * @spec openspec/specs/task-expiry-and-outcomes/spec.md#requirement-a-rejecting-completion-honours-the-tasks-declared-reject-behaviour
	 */
	private function finish(TaskCompletion $completion): Task {
		// Separation of duties FIRST (flow-approval-consolidation D-8): a
		// requester who claimed their own approval would PASS the assignee
		// check, and refusing after it would report the wrong reason.
		if ($this->sequenceGuard !== null) {
			$this->sequenceGuard->assertDecidable(task: $this->services->tables->tasks->findByUuid(uuid: $completion->uuid), actor: $completion->actor);
		}

		$task = $this->openTaskFor(act: new TaskAct(verb: $completion->verb, actor: $completion->actor), uuid: $completion->uuid);
		$this->refuseUncommentedRejection(completion: $completion);

		// Declared reject behaviour (task-expiry-and-outcomes D-5): only
		// `dead_letter` reroutes the record, through the SAME mapping the
		// timer path resolves; `skip` and `error` are the resuming
		// consumer's contract and change nothing about the completion.
		$recordedOutcome = $completion->outcome;
		$act = new TaskAct(verb: $completion->verb, actor: $completion->actor, reason: $completion->comment, state: Task::STATE_COMPLETED);
		if (TaskState::isRejectingOutcome(outcome: $completion->outcome) === true && $task->getOnReject() === 'dead_letter') {
			[$targetState, $recordedOutcome] = $this->timerOutcomeTarget(outcome: 'dead_letter');
			$act = new TaskAct(
				verb: $completion->verb,
				actor: $completion->actor,
				reason: sprintf(
					"Rejected as '%s'; declared onReject 'dead_letter' routed it to the dead letter state. %s",
					$completion->outcome,
					(string)$completion->comment
				),
				state: $targetState
			);
		}

		return $this->transactional(
			mutation: function () use ($task, $completion, $recordedOutcome, $act): Task {
				$task->setOutcome($recordedOutcome);
				$this->writeCompletion(task: $task, completion: $completion);

				return $this->settle(task: $task, act: $act);
			}
		);

	}//end finish()

	/**
	 * Comment-mandatory-on-reject, BEFORE any mutation: refused means the
	 * task keeps its pre-call state.
	 *
	 * @param TaskCompletion $completion The completion.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When a rejecting outcome has no comment.
	 */
	private function refuseUncommentedRejection(TaskCompletion $completion): void {
		if (TaskState::isRejectingOutcome(outcome: $completion->outcome) === true && trim((string)$completion->comment) === '') {
			throw new TaskValidationException(
				message: sprintf("Outcome '%s' rejects or returns the work, so a non-empty comment is mandatory.", $completion->outcome)
			);
		}
	}//end refuseUncommentedRejection()

	/**
	 * Write a completion's result fields, payload and completer on the task.
	 *
	 * @param Task $task The task being completed.
	 * @param TaskCompletion $completion The completion.
	 *
	 * @return void
	 */
	private function writeCompletion(Task $task, TaskCompletion $completion): void {
		$task->setResultText($completion->resultText);
		$task->setComment($completion->comment);
		if ($completion->responses !== null) {
			$task->setResponses($completion->responses);
		}

		if ($completion->evidence !== null) {
			$task->setEvidence($completion->evidence);
		}

		$task->setCompletedAt(new DateTime());
		$task->setCompletedBy($completion->actor);
	}//end writeCompletion()

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
	public function authorizedOpenTask(TaskAct $act, string $uuid): Task {
		return $this->openTaskFor(act: $act, uuid: $uuid);
	}//end authorizedOpenTask()

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
	public function recordRefusedCompletion(Task $task, TaskAct $attempt): void {
		if ($task->getId() === null) {
			return;
		}

		try {
			$entry = $this->auditEntry(task: $task, act: new TaskAct(verb: 'complete-refused', actor: $attempt->actor, reason: $attempt->reason));
			$entry->setAuthorized(true);
			$this->services->tables->audits->insert($entry);
		} catch (Throwable $auditFailure) {
			$this->services->commit->logger->warning(
				'[TaskService] Could not record a refused completion: ' . $auditFailure->getMessage(),
				['task' => $task->getUuid()]
			);
		}
	}//end recordRefusedCompletion()

	/**
	 * Refuse a run-less task whose own form declaration could not be rendered.
	 *
	 * The record is the declaration for a task with no run, so creation is
	 * its save time: a field the subject schema lacks, or marks read-only or
	 * not visible, is refused now, naming it, rather than left for the
	 * performer to meet.
	 *
	 * @param Task $task The task about to be inserted.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When the declaration is refused.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	private function refuseUnrenderableForm(Task $task): void {
		$record = (($task->getMetadata() ?? [])['form'] ?? null);
		if ($this->services->intake->forms === null || is_array($record) === false || $record === []) {
			return;
		}

		try {
			$this->services->intake->forms->validate(form: $this->services->intake->forms->fromRecord(record: $record));
		} catch (UnexpectedValueException $refused) {
			throw new TaskValidationException(message: $refused->getMessage(), previous: $refused);
		}
	}//end refuseUnrenderableForm()

	/**
	 * Resolve a verb's task: it must exist, the caller must be authorized,
	 * and it must be non-terminal — in that order, before any mutation.
	 *
	 * @param TaskAct $act The verb being attempted and the acting identity.
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The open, authorized task.
	 *
	 * @throws TaskConflictException When the task is already terminal — with
	 *                               the current state in the message.
	 * @throws TaskAccessDeniedException When authorization denies (audited).
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function openTaskFor(TaskAct $act, string $uuid): Task {
		$task = $this->services->tables->tasks->findByUuid(uuid: $uuid);

		// Authorization FIRST. The terminality conflict names the current
		// state, and a caller with no relationship to the task must not be
		// able to read that state out of a 409.
		$this->authorizeOrRecord(task: $task, act: $act);

		if ($task->isInTerminalState() === true) {
			throw new TaskConflictException(
				message: sprintf("Verb '%s' refused: task '%s' is already in terminal state '%s'.", $act->verb, $uuid, (string)$task->getState())
			);
		}

		// Snapshot BEFORE any mutation: the closures mutate this very object,
		// so this is the last moment the previous holder is observable.
		$this->pending = [
			'assignee' => $task->getAssignee(),
			'state' => $task->getState(),
			'actor' => $act->actor,
		];

		return $task;
	}//end openTaskFor()

	/**
	 * Authorize, and AUDIT the denial before rethrowing it.
	 *
	 * A denial mutates nothing, so its audit entry is appended outside any
	 * verb transaction; failing to record it is logged but does not convert
	 * the denial into anything else.
	 *
	 * @param Task $task The task acted on.
	 * @param TaskAct $act The verb being attempted and the acting identity.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException The original denial, always rethrown.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	private function authorizeOrRecord(Task $task, TaskAct $act): void {
		try {
			$this->authorization->assertMay(act: $act, task: $task);
		} catch (TaskAccessDeniedException $denial) {
			$this->recordDenial(task: $task, act: new TaskAct(verb: $act->verb, actor: $act->actor, reason: $denial->getMessage()));

			throw $denial;
		}
	}//end authorizeOrRecord()

	/**
	 * Append an unauthorized audit entry for a denied verb, never throwing.
	 *
	 * A task that was never saved has nothing to attach the entry to.
	 *
	 * @param Task $task The task acted on.
	 * @param TaskAct $act The denied verb, the actor and the denial message.
	 *
	 * @return void
	 */
	private function recordDenial(Task $task, TaskAct $act): void {
		try {
			if ($task->getId() !== null) {
				$entry = $this->auditEntry(task: $task, act: $act);
				$entry->setAuthorized(false);
				$this->services->tables->audits->insert($entry);
			}
		} catch (Throwable $auditFailure) {
			$this->services->commit->logger->warning(
				'[TaskService] Could not record an authorization denial: ' . $auditFailure->getMessage(),
				['task' => $task->getUuid(), 'verb' => $act->verb]
			);
		}
	}//end recordDenial()

	/**
	 * Persist a state-changing mutation ONLY if the row is still open.
	 *
	 * Every verb's in-memory terminality check can be passed by two callers
	 * at once (complete/complete, complete/cancel). The mapper's conditional
	 * update lets exactly one through; the other gets a conflict here, so a
	 * second outcome never overwrites a first.
	 *
	 * @param Task $task The mutated task.
	 *
	 * @return Task The task as persisted.
	 *
	 * @throws TaskConflictException When the row was closed by someone else.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function persistOpen(Task $task): Task {
		if ($this->services->tables->tasks->updateIfOpen(task: $task) === false) {
			throw new TaskConflictException(
				message: sprintf("Task '%s' was closed concurrently; this change was not applied.", (string)$task->getUuid())
			);
		}

		return $task;
	}//end persistOpen()

	/**
	 * THE one place `state` and `is_terminal` change — in the same statement.
	 *
	 * (`TaskMapper::claim()` is the deliberate second writer of `state`: its
	 * conditional UPDATE moves enabled→active, both non-terminal, so
	 * `is_terminal` is untouched and cannot drift there either.)
	 *
	 * @param Task $task The task to move.
	 * @param TaskAct $act The target CMMN state, and the NAMED transition
	 *                     action as its verb, recorded because ADR-031
	 *                     notification triggers address the action.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	private function applyState(Task $task, TaskAct $act): void {
		$state = (string)$act->state;
		$task->setState($state);
		$task->setIsTerminal(TaskState::isTerminal(state: $state));
		$task->setLastAction($act->verb);
	}//end applyState()

	/**
	 * Move the task, write it if still open, and audit the act.
	 *
	 * @param Task $task The task to move.
	 * @param TaskAct $act The act, with the state it leaves the task in.
	 *
	 * @return Task The persisted task.
	 *
	 * @throws TaskConflictException When the task was closed concurrently.
	 */
	private function settle(Task $task, TaskAct $act): Task {
		$this->applyState(task: $task, act: $act);

		return $this->persistAudited(task: $task, act: $act);
	}//end settle()

	/**
	 * Write the task if still open, and audit the act.
	 *
	 * @param Task $task The changed task.
	 * @param TaskAct $act The act to audit.
	 *
	 * @return Task The persisted task.
	 *
	 * @throws TaskConflictException When the task was closed concurrently.
	 */
	private function persistAudited(Task $task, TaskAct $act): Task {
		$persisted = $this->persistOpen(task: $task);
		$this->appendAudit(task: $persisted, act: $act);

		return $persisted;
	}//end persistAudited()

	/**
	 * Append a success audit entry, INSIDE the caller's transaction.
	 *
	 * Carries actor, performer type, on-behalf-of and mandate so "a human
	 * did this", "a model did this" and "a delegate did this for someone"
	 * stay distinguishable after the fact.
	 *
	 * @param Task $task The mutated task.
	 * @param TaskAct $act The named transition action, the actor and the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	private function appendAudit(Task $task, TaskAct $act): void {
		$entry = $this->auditEntry(task: $task, act: $act);
		$entry->setAuthorized(true);
		if ($act->verb === 'create') {
			// The create entry happened when the task did, which an imported task may date in the past.
			$entry->setCreated($task->getCreated());
		}

		$this->services->tables->audits->insert($entry);
	}//end appendAudit()

	/**
	 * An audit entry for an act on a task, its authorization still unset.
	 *
	 * @param Task $task The task acted on.
	 * @param TaskAct $act The action, the actor and the reason.
	 *
	 * @return TaskAudit The entry.
	 */
	private function auditEntry(Task $task, TaskAct $act): TaskAudit {
		$entry = new TaskAudit();
		$entry->setTaskId((int)$task->getId());
		$entry->setAction($act->verb);
		$entry->setStateAfter($task->getState());
		$entry->setActor($act->actor);
		$entry->setPerformerType($task->getPerformerType());
		$entry->setOnBehalfOf($task->getOnBehalfOf());
		$entry->setMandate($task->getMandate());
		$entry->setReason($act->reason);

		return $entry;
	}//end auditEntry()

	/**
	 * Run a mutation and its audit in ONE transaction, then announce terminality.
	 *
	 * The rollback is what makes "a completed task without its audit entry"
	 * unreachable: an audit-write failure unwinds the completion with it.
	 * A mutation that leaves the task terminal is announced as
	 * {@see TaskTerminalEvent} once the transaction has closed.
	 *
	 * @param callable(): Task $mutation The mutation to run.
	 *
	 * @return Task The mutation's result.
	 *
	 * @throws Throwable Whatever the mutation threw, after rollback.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	private function transactional(callable $mutation): Task {
		$this->services->commit->db->beginTransaction();
		try {
			$result = $mutation();
			$this->services->commit->db->commit();
		} catch (Throwable $failure) {
			$this->pending = null;
			$this->services->commit->db->rollBack();
			throw $failure;
		}

		// The projections first: the notification and the calendar entry of
		// THIS task reflect its committed state before the flow side walks on
		// (that walk can complete further tasks re-entrantly).
		$this->announce(task: $result);

		// Terminality is announced HERE, after the commit and from the one
		// place every mutation passes, so no verb can forget it (the same
		// choke-point argument FlowRunMapper::update() makes for runs). After
		// the commit, never inside it: the flow-side listener may continue the
		// task's run in-request, and that walk must find a completion that
		// already exists on its own. A listener failure is logged and
		// swallowed: the caller has a committed task in hand and must be told
		// so, whatever the run did with it afterwards.
		if ($this->services->commit->dispatcher !== null && $result->isInTerminalState() === true) {
			try {
				$this->services->commit->dispatcher->dispatchTyped(new TaskTerminalEvent(task: $result));
			} catch (Throwable $listenerFailure) {
				$this->services->commit->logger->warning(
					'[TaskService] A terminal-task listener failed; the task itself is unaffected: ' . $listenerFailure->getMessage(),
					['task' => $result->getUuid(), 'exception' => $listenerFailure]
				);
			}
		}

		return $result;
	}//end transactional()

	/**
	 * Announce a COMMITTED transition to the projections.
	 *
	 * Runs after the commit and outside the transaction, so nothing a
	 * listener does can unwind the verb (flow-task-inbox-projections,
	 * design D-8). A listener that throws is logged naming the task; the
	 * verb has already succeeded and its caller is told so.
	 *
	 * @param Task $task The committed task.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
	 */
	private function announce(Task $task): void {
		$pending = $this->pending;
		$this->pending = null;

		if ($this->services->commit->dispatcher === null) {
			return;
		}

		try {
			$this->services->commit->dispatcher->dispatchTyped(
				new TaskTransitionedEvent(
					task: $task,
					previousAssignee: ($pending['assignee'] ?? $task->getAssignee()),
					previousState: ($pending['state'] ?? null),
					actor: ($pending['actor'] ?? null)
				)
			);
		} catch (Throwable $failure) {
			$this->services->commit->logger->warning(
				'[TaskService] A projection failed after the transition committed; the task is unchanged by it: ' . $failure->getMessage(),
				['task' => $task->getUuid(), 'action' => $task->getLastAction()]
			);
		}
	}//end announce()

	/**
	 * Rewrite the candidate INDEX rows from the task's JSON record.
	 *
	 * The other half of the one-write-path rule: called only inside a
	 * transaction that also wrote the JSON columns.
	 *
	 * @param Task $task The persisted task.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function rewriteCandidateIndex(Task $task): void {
		$rows = [];
		foreach (($task->getCandidateUsers() ?? []) as $uid) {
			$rows[] = [
				'kind' => 'user',
				'ref' => (string)$uid,
			];
		}

		foreach (($task->getCandidateGroups() ?? []) as $groupId) {
			// A typed `{type, id}` entry is never a bare group name.
			if (is_array($groupId) === true) {
				continue;
			}

			$rows[] = [
				'kind' => 'group',
				'ref' => (string)$groupId,
			];
		}

		$role = trim((string)$task->getCandidateRole());
		if ($role !== '') {
			$rows[] = [
				'kind' => 'role',
				'ref' => $role,
			];
		}

		$this->services->tables->candidates->replaceForTask(taskId: (int)$task->getId(), candidates: $rows);
	}//end rewriteCandidateIndex()
}//end class
