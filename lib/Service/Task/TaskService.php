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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, routes, claims, completes, cancels and terminates tasks.
 *
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
 * @SuppressWarnings(PHPMD.TooManyMethods) One private helper per concern the
 * verbs share (open, authorize, audit, persist, announce); merging them would
 * hide which rule a verb relies on.
 * @SuppressWarnings(PHPMD.StaticAccess) TaskState is a stateless published
 * vocabulary (the one status mapping); calling it statically is the point,
 * an instance would be a second copy of the same table.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The tenth constructor
 * argument is the nullable event dispatcher that announces the transition
 * to the projections and terminality to the flow side; it is last so the
 * hand-built test services keep their order, and folding it into another
 * collaborator would hide that a lifecycle verb has an after-commit side
 * effect.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskService {

	/**
	 * Constructor.
	 *
	 * @param TaskMapper $tasks The task table.
	 * @param TaskCandidateMapper $candidates The candidate index rows.
	 * @param TaskRelationMapper $relations The typed relation rows.
	 * @param TaskAuditMapper $audits The append-only audit.
	 * @param TaskAuthorizationService $authorization The per-verb, fail-closed decisions.
	 * @param TaskPerformerResolver $resolver The routing strategies.
	 * @param IDBConnection $db Holds the one transaction per verb.
	 * @param LoggerInterface $logger Failure reporting.
	 * @param TaskBuilder $builder Validates and builds a new task from
	 *                             boundary data (the vocabularies live there).
	 * @param IEventDispatcher|null $dispatcher Announces the committed
	 *                                          transition to the projections
	 *                                          ({@see TaskTransitionedEvent})
	 *                                          and, when the task came out
	 *                                          terminal, its terminality to the
	 *                                          flow side
	 *                                          ({@see TaskTerminalEvent}) — both
	 *                                          AFTER the commit. Last and
	 *                                          nullable so the suites that build
	 *                                          this service by hand keep their
	 *                                          argument order; absent, nothing
	 *                                          is projected, terminality goes
	 *                                          unannounced, and the lifecycle is
	 *                                          unchanged.
	 */
	public function __construct(
		private readonly TaskMapper $tasks,
		private readonly TaskCandidateMapper $candidates,
		private readonly TaskRelationMapper $relations,
		private readonly TaskAuditMapper $audits,
		private readonly TaskAuthorizationService $authorization,
		private readonly TaskPerformerResolver $resolver,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly TaskBuilder $builder,
		private readonly ?IEventDispatcher $dispatcher = null,
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
	 *
	 * @param array<string, mixed> $data The task fields, canonical or legacy.
	 * @param string|null $actor The creating identity.
	 *
	 * @return Task The created task, with candidates indexed and creation audited.
	 *
	 * @throws TaskValidationException On any refused value.
	 * @throws TaskAccessDeniedException Without an acting identity.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function create(array $data, ?string $actor): Task {
		if ($this->authorization->isAdministrator(uid: $actor) === false) {
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

		return $this->import(data: $data, actor: $actor);
	}//end create()

	/**
	 * Create a task on the TRUSTED path: migrations and in-process callers
	 * (the user-task node) that may name a requester and may import a task
	 * that is already closed (a completed approval carried over from a
	 * legacy shape). Not reachable over HTTP; the controller calls create().
	 *
	 * @param array<string, mixed> $data The task fields, canonical or legacy.
	 * @param string|null $actor The creating identity.
	 *
	 * @return Task The created task, with candidates indexed and creation audited.
	 *
	 * @throws TaskValidationException On any refused value.
	 * @throws TaskAccessDeniedException Without an acting identity.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public function import(array $data, ?string $actor): Task {
		$task = $this->builder->fromData(data: $data, actor: $actor);
		$this->authorizeOrRecord(verb: 'create', task: $task, actor: $actor);
		$this->pending = [
			'assignee' => null,
			'state' => null,
			'actor' => $actor,
		];

		return $this->transactional(
			mutation: function () use ($task, $data, $actor): Task {
				$persisted = $this->tasks->insert($task);
				$this->rewriteCandidateIndex(task: $persisted);
				foreach ($this->builder->relationsFor(task: $persisted, data: $data) as $relation) {
					$this->relations->insert(entity: $relation);
				}
				$this->appendAudit(
					task: $persisted,
					action: 'create',
					actor: $actor,
					reason: null
				);

				return $persisted;
			}
		);
	}//end import()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function offer(string $uuid, array $pool, ?string $actor): Task {
		$task = $this->openTaskFor(verb: 'offer', uuid: $uuid, actor: $actor);

		// An assigned task is not offerable: offering rewrites the pool and
		// the routing fallback, which decide who ends up assigned, so on an
		// assigned task it would be a reassignment wearing a different name
		// and a different authorization rule. Unclaim or reassign first.
		if (trim((string)$task->getAssignee()) !== '') {
			throw new TaskConflictException(
				message: sprintf("Verb 'offer' refused: task '%s' is already assigned; unclaim or reassign it instead.", $uuid)
			);
		}

		return $this->transactional(
			mutation: function () use ($task, $pool, $actor): Task {
				// Only the keys the caller sent change; an absent key keeps
				// the stored value, so offer can adjust one routing field.
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

				// Routing either names somebody (active) or leaves the task
				// pooled (enabled). Never a third option.
				$chosen = $this->resolver->resolveAssignee(task: $task);
				$task->setAssignee($chosen);
				$state = Task::STATE_ENABLED;
				if ($chosen !== null) {
					$state = Task::STATE_ACTIVE;
				}

				$this->applyState(task: $task, state: $state, action: 'offer');

				$persisted = $this->persistOpen(task: $task);
				$this->rewriteCandidateIndex(task: $persisted);
				$this->appendAudit(task: $persisted, action: 'offer', actor: $actor, reason: null);

				return $persisted;
			}
		);
	}//end offer()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function claim(string $uuid, ?string $actor): Task {
		$task = $this->openTaskFor(verb: 'claim', uuid: $uuid, actor: $actor);

		return $this->transactional(
			mutation: function () use ($task, $actor): Task {
				$won = $this->tasks->claim(taskId: (int)$task->getId(), uid: (string)$actor);
				if ($won === false) {
					throw new TaskConflictException(
						message: sprintf("Task '%s' was not claimable: another claim won, or the task is no longer open.", (string)$task->getUuid())
					);
				}

				$fresh = $this->tasks->findByUuid(uuid: (string)$task->getUuid());
				$this->appendAudit(task: $fresh, action: 'claim', actor: $actor, reason: null);

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function unclaim(string $uuid, ?string $actor): Task {
		$task = $this->openTaskFor(verb: 'unclaim', uuid: $uuid, actor: $actor);

		return $this->transactional(
			mutation: function () use ($task, $actor): Task {
				$task->setAssignee(null);
				$task->setOnBehalfOf(null);
				$task->setMandate(null);
				$this->applyState(task: $task, state: Task::STATE_ENABLED, action: 'unclaim');
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(task: $persisted, action: 'unclaim', actor: $actor, reason: null);

				return $persisted;
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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function assign(string $uuid, string $assignee, ?string $actor): Task {
		return $this->assignInternal(uuid: $uuid, assignee: $assignee, actor: $actor, action: 'assign');
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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function reassign(string $uuid, string $assignee, ?string $actor): Task {
		return $this->assignInternal(uuid: $uuid, assignee: $assignee, actor: $actor, action: 'reassign');
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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function delegate(string $uuid, string $delegate, string $mandate, ?string $actor): Task {
		$task = $this->openTaskFor(verb: 'delegate', uuid: $uuid, actor: $actor);

		if (trim($delegate) === '') {
			throw new TaskValidationException(message: 'A delegation requires a non-empty delegate.');
		}

		if (trim($mandate) === '') {
			throw new TaskValidationException(message: 'A delegation requires a mandate naming the authority relied on.');
		}

		return $this->transactional(
			mutation: function () use ($task, $delegate, $mandate, $actor): Task {
				// A re-delegation keeps naming the ORIGINAL performer: the
				// chain of delegates is in the audit, the accountable party
				// is the one who never changes.
				$task->setOnBehalfOf($task->getOnBehalfOf() ?? $task->getAssignee());
				$task->setAssignee($delegate);
				$task->setMandate($mandate);
				$this->applyState(task: $task, state: Task::STATE_ACTIVE, action: 'delegate');
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(task: $persisted, action: 'delegate', actor: $actor, reason: $mandate);

				return $persisted;
			}
		);
	}//end delegate()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function resolve(string $uuid, ?string $resultText, ?string $comment, ?string $actor): Task {
		return $this->completeInternal(
			verb: 'resolve',
			uuid: $uuid,
			outcome: 'resolved',
			resultText: $resultText,
			comment: $comment,
			actor: $actor
		);
	}//end resolve()

	/**
	 * Complete: the assignee finishes the work with an explicit outcome.
	 *
	 * A rejecting or returning outcome REQUIRES a non-empty comment; without
	 * one the verb is refused and the task keeps its pre-call state.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $outcome The outcome (`approved`, `done`, `rejected`, ...).
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment Completion comment.
	 * @param string|null $actor The completing identity — must be the assignee.
	 * @param array<string, mixed>|null $responses The submitted answer fields,
	 *                                             when the completion carries
	 *                                             any (a portal task's form).
	 * @param array<int, array<string, mixed>>|null $evidence References to the
	 *                                                       files ALREADY stored
	 *                                                       for this completion;
	 *                                                       never bytes.
	 *
	 * @return Task The completed task.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function complete(
		string $uuid,
		string $outcome,
		?string $resultText,
		?string $comment,
		?string $actor,
		?array $responses = null,
		?array $evidence = null,
	): Task {
		return $this->completeInternal(
			verb: 'complete',
			uuid: $uuid,
			outcome: $outcome,
			resultText: $resultText,
			comment: $comment,
			actor: $actor,
			responses: $responses,
			evidence: $evidence
		);
	}//end complete()

	/**
	 * Cancel: the requester (or an administrator) terminates the task.
	 *
	 * @param string $uuid The task uuid.
	 * @param string|null $reason Why, recorded on task and audit.
	 * @param string|null $actor The cancelling identity.
	 *
	 * @return Task The terminated task.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function cancel(string $uuid, ?string $reason, ?string $actor): Task {
		$task = $this->openTaskFor(verb: 'cancel', uuid: $uuid, actor: $actor);

		return $this->transactional(
			mutation: function () use ($task, $reason, $actor): Task {
				$task->setOutcome('cancelled');
				$this->applyState(task: $task, state: Task::STATE_TERMINATED, action: 'cancel');
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(task: $persisted, action: 'cancel', actor: $actor, reason: $reason);

				return $persisted;
			}
		);

	}//end cancel()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
	 */
	public function checkChecklistItem(string $uuid, string $itemId, bool $checked, ?string $actor): Task {
		$task = $this->openTaskFor(verb: 'checklist', uuid: $uuid, actor: $actor);

		return $this->transactional(
			mutation: function () use ($task, $itemId, $checked, $actor): Task {
				$checklist = ($task->getChecklist() ?? []);
				$found = false;
				foreach ($checklist as $index => $item) {
					if (is_array($item) === true && (string)($item['id'] ?? '') === $itemId) {
						$checklist[$index]['checked'] = $checked;
						$found = true;
						break;
					}
				}

				if ($found === false) {
					throw new TaskValidationException(
						message: sprintf("Checklist item '%s' does not exist on task '%s'.", $itemId, (string)$task->getUuid())
					);
				}

				$task->setChecklist($checklist);
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(
					task: $persisted,
					action: 'checklist',
					actor: $actor,
					reason: sprintf("Item '%s' set to '%s'.", $itemId, var_export($checked, true))
				);

				return $persisted;
			}
		);
	}//end checkChecklistItem()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function terminateForRun(string $runUuid, string $runStatus): int {
		if (trim($runUuid) === '') {
			return 0;
		}

		$open = $this->tasks->findOpenByRunUuid(runUuid: $runUuid);
		$reason = sprintf("Run '%s' reached terminal status '%s'.", $runUuid, $runStatus);
		$terminated = 0;
		$failed = [];
		foreach ($open as $task) {
			// One task's failure must not orphan the rest of the run's
			// tasks: each is its own transaction, and a failure is logged
			// and counted, then the loop continues. A task closed
			// concurrently (conflict) is already where propagation wanted it.
			try {
				$this->transactional(
					mutation: function () use ($task, $reason, $runUuid): Task {
						$task->setOutcome('terminated');
						$task->setBlockedReason(null);
						$this->applyState(task: $task, state: Task::STATE_TERMINATED, action: 'terminate');
						$persisted = $this->persistOpen(task: $task);
						$this->appendAudit(
							task: $persisted,
							action: 'terminate',
							actor: sprintf('flow-run:%s', $runUuid),
							reason: $reason
						);

						return $persisted;
					}
				);
				$terminated++;
			} catch (TaskConflictException) {
				// Closed by somebody else in the meantime: not our failure.
				continue;
			} catch (Throwable $failure) {
				$failed[] = (string)$task->getUuid();
				$this->logger->error(
					'[TaskService] Propagation could not terminate a task: ' . $failure->getMessage(),
					['task' => $task->getUuid(), 'run' => $runUuid, 'exception' => $failure]
				);
			}//end try
		}//end foreach

		if ($failed !== []) {
			$this->logger->warning(
				sprintf('[TaskService] Propagation for run %s left %d task(s) untouched: %s', $runUuid, count($failed), implode(', ', $failed))
			);
		}

		return $terminated;
	}//end terminateForRun()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function terminateAsMoot(string $uuid, string $reason, string $source): Task {
		$task = $this->tasks->findByUuid(uuid: $uuid);
		if ($task->isInTerminalState() === true) {
			// Idempotent: already-terminal stays as it ended.
			return $task;
		}

		return $this->transactional(
			mutation: function () use ($task, $reason, $source): Task {
				$task->setOutcome('terminated');
				$this->applyState(task: $task, state: Task::STATE_TERMINATED, action: 'terminate');
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(task: $persisted, action: 'terminate', actor: $source, reason: $reason);

				return $persisted;
			}
		);

	}//end terminateAsMoot()

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

		$task = $this->tasks->findByUuid(uuid: $uuid);
		if ($task->isInTerminalState() === true) {
			// Idempotent: already-terminal stays as it ended.
			return $task;
		}

		return $this->transactional(
			mutation: function () use ($task, $state, $recorded, $action, $source, $reason): Task {
				$task->setOutcome($recorded);
				$task->setBlockedReason(null);
				if ($state === Task::STATE_COMPLETED) {
					$task->setCompletedAt(new DateTime());
					$task->setCompletedBy($source);
				}

				$this->applyState(task: $task, state: $state, action: $action);
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(task: $persisted, action: $action, actor: $source, reason: $reason);

				return $persisted;
			}
		);
	}//end applyTimerOutcome()

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
			$action = trim(substr($outcome, strlen('transition:')));
			if ($action === '' || strlen($action) > 32 || array_key_exists($action, $reserved) === true) {
				throw new TaskValidationException(
					message: sprintf("Timer outcome '%s' names no usable action: it must be 1..32 characters and not a reserved outcome.", $outcome)
				);
			}

			return [Task::STATE_COMPLETED, $action, $action];
		}

		throw new TaskValidationException(
			message: sprintf("Timer outcome '%s' is refused: use skip, error, dead_letter or transition:<action>.", $outcome)
		);
	}//end timerOutcomeTarget()

	/**
	 * Fetch a task by uuid.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return Task The task.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such task exists.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function get(string $uuid): Task {
		return $this->tasks->findByUuid(uuid: $uuid);
	}//end get()

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
	public function openFor(string $verb, string $uuid, ?string $actor): Task {
		return $this->openTaskFor(verb: $verb, uuid: $uuid, actor: $actor);
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
		$task = $this->tasks->findByUuid(uuid: $uuid);

		return $this->transactional(
			mutation: function () use ($task, $action, $actor, $reason): Task {
				$this->appendAudit(task: $task, action: $action, actor: $actor, reason: $reason);

				return $task;
			}
		);
	}//end record()

	/**
	 * The audit trail of a task, oldest first.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return array<int, TaskAudit> The entries.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	public function auditTrail(string $uuid): array {
		$task = $this->tasks->findByUuid(uuid: $uuid);

		return $this->audits->findForTask(taskId: (int)$task->getId());
	}//end auditTrail()

	/**
	 * Shared body of assign and reassign.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $assignee The performer reference.
	 * @param string|null $actor The acting identity.
	 * @param string $action 'assign' or 'reassign' — the audited name.
	 *
	 * @return Task The task with its new assignee.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assignInternal(string $uuid, string $assignee, ?string $actor, string $action): Task {
		$task = $this->openTaskFor(verb: $action, uuid: $uuid, actor: $actor);

		if (trim($assignee) === '') {
			throw new TaskValidationException(message: 'An assignment requires a non-empty assignee.');
		}

		return $this->transactional(
			mutation: function () use ($task, $assignee, $actor, $action): Task {
				$task->setAssignee($assignee);
				$task->setOnBehalfOf(null);
				$task->setMandate(null);
				$this->applyState(task: $task, state: Task::STATE_ACTIVE, action: $action);
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(task: $persisted, action: $action, actor: $actor, reason: null);

				return $persisted;
			}
		);
	}//end assignInternal()

	/**
	 * Shared body of resolve and complete.
	 *
	 * @param string $verb The authorized verb name.
	 * @param string $uuid The task uuid.
	 * @param string $outcome The completion outcome.
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment Completion comment.
	 * @param string|null $actor The acting identity.
	 * @param array<string, mixed>|null $responses Submitted answer fields, when any.
	 * @param array<int, array<string, mixed>>|null $evidence Stored file references, when any.
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskValidationException When a rejecting outcome has no comment.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function completeInternal(
		string $verb,
		string $uuid,
		string $outcome,
		?string $resultText,
		?string $comment,
		?string $actor,
		?array $responses = null,
		?array $evidence = null,
	): Task {
		$task = $this->openTaskFor(verb: $verb, uuid: $uuid, actor: $actor);

		// Comment-mandatory-on-reject, BEFORE any mutation: refused means the
		// task keeps its pre-call state.
		if (TaskState::isRejectingOutcome(outcome: $outcome) === true && trim((string)$comment) === '') {
			throw new TaskValidationException(
				message: sprintf("Outcome '%s' rejects or returns the work, so a non-empty comment is mandatory.", $outcome)
			);
		}

		return $this->transactional(
			mutation: function () use ($task, $outcome, $resultText, $comment, $actor, $verb, $responses, $evidence): Task {
				$task->setOutcome($outcome);
				$task->setResultText($resultText);
				$task->setComment($comment);
				if ($responses !== null) {
					$task->setResponses($responses);
				}

				if ($evidence !== null) {
					$task->setEvidence($evidence);
				}

				$task->setCompletedAt(new DateTime());
				$task->setCompletedBy($actor);
				$this->applyState(task: $task, state: Task::STATE_COMPLETED, action: $verb);
				$persisted = $this->persistOpen(task: $task);
				$this->appendAudit(task: $persisted, action: $verb, actor: $actor, reason: $comment);

				return $persisted;
			}
		);

	}//end completeInternal()

	/**
	 * Resolve a verb's task: it must exist, the caller must be authorized,
	 * and it must be non-terminal — in that order, before any mutation.
	 *
	 * @param string $verb The verb being attempted.
	 * @param string $uuid The task uuid.
	 * @param string|null $actor The acting identity.
	 *
	 * @return Task The open, authorized task.
	 *
	 * @throws TaskConflictException When the task is already terminal — with
	 *         the current state in the message.
	 * @throws TaskAccessDeniedException When authorization denies (audited).
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function openTaskFor(string $verb, string $uuid, ?string $actor): Task {
		$task = $this->tasks->findByUuid(uuid: $uuid);

		// Authorization FIRST. The terminality conflict names the current
		// state, and a caller with no relationship to the task must not be
		// able to read that state out of a 409.
		$this->authorizeOrRecord(verb: $verb, task: $task, actor: $actor);

		if ($task->isInTerminalState() === true) {
			throw new TaskConflictException(
				message: sprintf("Verb '%s' refused: task '%s' is already in terminal state '%s'.", $verb, $uuid, (string)$task->getState())
			);
		}

		// Snapshot BEFORE any mutation: the closures mutate this very object,
		// so this is the last moment the previous holder is observable.
		$this->pending = [
			'assignee' => $task->getAssignee(),
			'state' => $task->getState(),
			'actor' => $actor,
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
	 * @param string $verb The verb being attempted.
	 * @param Task $task The task acted on.
	 * @param string|null $actor The acting identity.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException The original denial, always rethrown.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	private function authorizeOrRecord(string $verb, Task $task, ?string $actor): void {
		try {
			$this->authorization->assertMay(verb: $verb, task: $task, uid: $actor);
		} catch (TaskAccessDeniedException $denial) {
			try {
				if ($task->getId() !== null) {
					$entry = new TaskAudit();
					$entry->setTaskId((int)$task->getId());
					$entry->setAction($verb);
					$entry->setStateAfter($task->getState());
					$entry->setActor($actor);
					$entry->setPerformerType($task->getPerformerType());
					$entry->setOnBehalfOf($task->getOnBehalfOf());
					$entry->setMandate($task->getMandate());
					$entry->setReason($denial->getMessage());
					$entry->setAuthorized(false);
					$this->audits->insert($entry);
				}
			} catch (Throwable $auditFailure) {
				$this->logger->warning(
					'[TaskService] Could not record an authorization denial: ' . $auditFailure->getMessage(),
					['task' => $task->getUuid(), 'verb' => $verb]
				);
			}

			throw $denial;
		}//end try
	}//end authorizeOrRecord()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function persistOpen(Task $task): Task {
		if ($this->tasks->updateIfOpen(task: $task) === false) {
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
	 * @param string $state The target CMMN state.
	 * @param string $action The NAMED transition action, recorded because
	 *                       ADR-031 notification triggers address the action.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	private function applyState(Task $task, string $state, string $action): void {
		$task->setState($state);
		$task->setIsTerminal(TaskState::isTerminal(state: $state));
		$task->setLastAction($action);
	}//end applyState()

	/**
	 * Append a success audit entry, INSIDE the caller's transaction.
	 *
	 * Carries actor, performer type, on-behalf-of and mandate so "a human
	 * did this", "a model did this" and "a delegate did this for someone"
	 * stay distinguishable after the fact.
	 *
	 * @param Task $task The mutated task.
	 * @param string $action The named transition action.
	 * @param string|null $actor The acting identity.
	 * @param string|null $reason The reason or comment, when any.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	private function appendAudit(Task $task, string $action, ?string $actor, ?string $reason): void {
		$entry = new TaskAudit();
		$entry->setTaskId((int)$task->getId());
		$entry->setAction($action);
		$entry->setStateAfter($task->getState());
		$entry->setActor($actor);
		$entry->setPerformerType($task->getPerformerType());
		$entry->setOnBehalfOf($task->getOnBehalfOf());
		$entry->setMandate($task->getMandate());
		$entry->setReason($reason);
		$entry->setAuthorized(true);
		$this->audits->insert($entry);
	}//end appendAudit()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	private function transactional(callable $mutation): Task {
		$this->db->beginTransaction();
		try {
			$result = $mutation();
			$this->db->commit();
		} catch (Throwable $failure) {
			$this->pending = null;
			$this->db->rollBack();
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
		if ($this->dispatcher !== null && $result->isInTerminalState() === true) {
			try {
				$this->dispatcher->dispatchTyped(new TaskTerminalEvent(task: $result));
			} catch (Throwable $listenerFailure) {
				$this->logger->warning(
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

		if ($this->dispatcher === null) {
			return;
		}

		try {
			$this->dispatcher->dispatchTyped(
				new TaskTransitionedEvent(
					task: $task,
					previousAssignee: ($pending['assignee'] ?? $task->getAssignee()),
					previousState: ($pending['state'] ?? null),
					actor: ($pending['actor'] ?? null)
				)
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
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

		$this->candidates->replaceForTask(taskId: (int)$task->getId(), candidates: $rows);
	}//end rewriteCandidateIndex()
}//end class
