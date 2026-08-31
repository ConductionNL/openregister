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
use OCA\OpenRegister\Db\TaskRelation;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
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
	) {

	}//end __construct()

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
		$task = $this->buildTask(data: $data, actor: $actor);
		$this->authorizeOrRecord(verb: 'create', task: $task, actor: $actor);

		return $this->transactional(
			function () use ($task, $data, $actor): Task {
				$persisted = $this->tasks->insert($task);
				$this->rewriteCandidateIndex(task: $persisted);
				$this->insertRelations(task: $persisted, data: $data);
				$this->appendAudit(
					task: $persisted,
					action: 'create',
					actor: $actor,
					reason: null
				);

				return $persisted;
			}
		);
	}//end create()

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

		return $this->transactional(
			function () use ($task, $pool, $actor): Task {
				if (array_key_exists('candidateUsers', $pool) === true) {
					$task->setCandidateUsers($pool['candidateUsers']);
				}

				if (array_key_exists('candidateGroups', $pool) === true) {
					$task->setCandidateGroups($pool['candidateGroups']);
				}

				if (array_key_exists('candidateRole', $pool) === true) {
					$task->setCandidateRole($pool['candidateRole']);
				}

				if (array_key_exists('routingStrategy', $pool) === true) {
					$task->setRoutingStrategy($pool['routingStrategy']);
				}

				if (array_key_exists('routingFallback', $pool) === true) {
					$task->setRoutingFallback($pool['routingFallback']);
				}

				$chosen = $this->resolver->resolveAssignee(task: $task);
				if ($chosen !== null) {
					$task->setAssignee($chosen);
					$this->applyState(task: $task, state: Task::STATE_ACTIVE, action: 'offer');
				} else {
					$task->setAssignee(null);
					$this->applyState(task: $task, state: Task::STATE_ENABLED, action: 'offer');
				}

				$persisted = $this->tasks->update($task);
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
			function () use ($task, $actor): Task {
				$won = $this->tasks->claim(taskId: (int)$task->getId(), uid: (string)$actor);
				if ($won === false) {
					throw new TaskConflictException(
						sprintf("Task '%s' was not claimable: another claim won, or the task is no longer open.", (string)$task->getUuid())
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
			function () use ($task, $actor): Task {
				$task->setAssignee(null);
				$task->setOnBehalfOf(null);
				$task->setMandate(null);
				$this->applyState(task: $task, state: Task::STATE_ENABLED, action: 'unclaim');
				$persisted = $this->tasks->update($task);
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

		if (trim($mandate) === '') {
			throw new TaskValidationException('A delegation requires a mandate naming the authority relied on.');
		}

		return $this->transactional(
			function () use ($task, $delegate, $mandate, $actor): Task {
				$task->setOnBehalfOf($task->getAssignee());
				$task->setAssignee($delegate);
				$task->setMandate($mandate);
				$this->applyState(task: $task, state: Task::STATE_ACTIVE, action: 'delegate');
				$persisted = $this->tasks->update($task);
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
	 *
	 * @return Task The completed task.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function complete(string $uuid, string $outcome, ?string $resultText, ?string $comment, ?string $actor): Task {
		return $this->completeInternal(
			verb: 'complete',
			uuid: $uuid,
			outcome: $outcome,
			resultText: $resultText,
			comment: $comment,
			actor: $actor
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
			function () use ($task, $reason, $actor): Task {
				$task->setOutcome('cancelled');
				$this->applyState(task: $task, state: Task::STATE_TERMINATED, action: 'cancel');
				$persisted = $this->tasks->update($task);
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
			function () use ($task, $itemId, $checked, $actor): Task {
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
						sprintf("Checklist item '%s' does not exist on task '%s'.", $itemId, (string)$task->getUuid())
					);
				}

				$task->setChecklist($checklist);
				$persisted = $this->tasks->update($task);
				$this->appendAudit(
					task: $persisted,
					action: 'checklist',
					actor: $actor,
					reason: sprintf("Item '%s' set to %s.", $itemId, $checked === true ? 'checked' : 'unchecked')
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
		foreach ($open as $task) {
			$this->transactional(
				function () use ($task, $reason, $runUuid): Task {
					$task->setOutcome('terminated');
					$task->setBlockedReason(null);
					$this->applyState(task: $task, state: Task::STATE_TERMINATED, action: 'terminate');
					$persisted = $this->tasks->update($task);
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
			function () use ($task, $reason, $source): Task {
				$task->setOutcome('terminated');
				$this->applyState(task: $task, state: Task::STATE_TERMINATED, action: 'terminate');
				$persisted = $this->tasks->update($task);
				$this->appendAudit(task: $persisted, action: 'terminate', actor: $source, reason: $reason);

				return $persisted;
			}
		);
	}//end terminateAsMoot()

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
			throw new TaskValidationException('An assignment requires a non-empty assignee.');
		}

		return $this->transactional(
			function () use ($task, $assignee, $actor, $action): Task {
				$task->setAssignee($assignee);
				$task->setOnBehalfOf(null);
				$task->setMandate(null);
				$this->applyState(task: $task, state: Task::STATE_ACTIVE, action: $action);
				$persisted = $this->tasks->update($task);
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
	): Task {
		$task = $this->openTaskFor(verb: $verb, uuid: $uuid, actor: $actor);

		// Comment-mandatory-on-reject, BEFORE any mutation: refused means the
		// task keeps its pre-call state.
		if (TaskState::isRejectingOutcome(outcome: $outcome) === true && trim((string)$comment) === '') {
			throw new TaskValidationException(
				sprintf("Outcome '%s' rejects or returns the work, so a non-empty comment is mandatory.", $outcome)
			);
		}

		return $this->transactional(
			function () use ($task, $outcome, $resultText, $comment, $actor, $verb): Task {
				$task->setOutcome($outcome);
				$task->setResultText($resultText);
				$task->setComment($comment);
				$task->setCompletedAt(new DateTime());
				$task->setCompletedBy($actor);
				$this->applyState(task: $task, state: Task::STATE_COMPLETED, action: $verb);
				$persisted = $this->tasks->update($task);
				$this->appendAudit(task: $persisted, action: $verb, actor: $actor, reason: $comment);

				return $persisted;
			}
		);
	}//end completeInternal()

	/**
	 * Resolve a verb's task: it must exist, be non-terminal, and the caller
	 * must be authorized — in that order, before any mutation.
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

		if ($task->isInTerminalState() === true) {
			throw new TaskConflictException(
				sprintf("Verb '%s' refused: task '%s' is already in terminal state '%s'.", $verb, $uuid, (string)$task->getState())
			);
		}

		$this->authorizeOrRecord(verb: $verb, task: $task, actor: $actor);

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
	 * Run a mutation and its audit in ONE transaction.
	 *
	 * The rollback is what makes "a completed task without its audit entry"
	 * unreachable: an audit-write failure unwinds the completion with it.
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

			return $result;
		} catch (Throwable $failure) {
			$this->db->rollBack();
			throw $failure;
		}
	}//end transactional()

	/**
	 * Build and validate a new task from boundary data.
	 *
	 * @param array<string, mixed> $data The incoming fields.
	 * @param string|null $actor The creating identity.
	 *
	 * @return Task The validated, unsaved task.
	 *
	 * @throws TaskValidationException On any refused value.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	private function buildTask(array $data, ?string $actor): Task {
		$task = new Task();

		// Lifecycle: any vocabulary in, one vocabulary stored. state and
		// is_terminal land together; the collapsed distinction lands on
		// outcome unless the caller supplied an explicit one.
		$normalised = TaskState::normalise(value: (string)($data['state'] ?? Task::STATE_AVAILABLE));
		$this->applyState(task: $task, state: $normalised['state'], action: 'create');
		$task->setOutcome((string)($data['outcome'] ?? '') !== '' ? (string)$data['outcome'] : $normalised['outcome']);

		// Priority: one scale, off-scale refused.
		$task->setPriority(TaskPriority::normalise(value: ($data['priority'] ?? 'normal')));

		// Performer type: validated against the extensible vocabulary.
		$performerType = (string)($data['performerType'] ?? Task::PERFORMER_USER);
		if (in_array($performerType, Task::PERFORMER_TYPES, true) === false) {
			throw new TaskValidationException(
				sprintf("Performer type '%s' is not in the known vocabulary (%s).", $performerType, implode('|', Task::PERFORMER_TYPES))
			);
		}

		$task->setPerformerType($performerType);

		// Deadlines: due_at advises, expires_at enforces, and an expiry
		// before the due date is a configuration error, not a schedule.
		$dueAt = $this->parseDate(value: ($data['dueAt'] ?? null), field: 'dueAt');
		$expiresAt = $this->parseDate(value: ($data['expiresAt'] ?? null), field: 'expiresAt');
		if ($dueAt !== null && $expiresAt !== null && $expiresAt < $dueAt) {
			throw new TaskValidationException(
				sprintf(
					"expiresAt '%s' lies before dueAt '%s': a task that dies before it is due is a configuration error.",
					$expiresAt->format('c'),
					$dueAt->format('c')
				)
			);
		}

		$task->setDueAt($dueAt);
		$task->setExpiresAt($expiresAt);
		$task->setStartAt($this->parseDate(value: ($data['startAt'] ?? null), field: 'startAt'));
		$task->setSuspendedUntil($this->parseDate(value: ($data['suspendedUntil'] ?? null), field: 'suspendedUntil'));

		// Checklist: typed array, never a string containing JSON.
		$task->setChecklist($this->validChecklist(value: ($data['checklist'] ?? null)));

		// Plain carried fields.
		$task->setUuid((string)($data['uuid'] ?? Uuid::v4()->toRfc4122()));
		$task->setTaskKey($this->stringOrNull($data['key'] ?? null));
		$task->setTitle($this->stringOrNull($data['title'] ?? null));
		$task->setDescription($this->stringOrNull($data['description'] ?? null));
		$task->setMetadata($this->arrayOrNull($data['metadata'] ?? null));
		$task->setRunUuid($this->stringOrNull($data['runUuid'] ?? null));
		$task->setNodeId($this->stringOrNull($data['nodeId'] ?? null));
		$task->setDefinitionVersion(isset($data['definitionVersion']) === true ? (int)$data['definitionVersion'] : null);
		$task->setAppId($this->stringOrNull($data['appId'] ?? null));
		$task->setWorkflowStepId($this->stringOrNull($data['workflowStepId'] ?? null));
		$task->setOrganisation($this->stringOrNull($data['organisation'] ?? null));
		$task->setAssignee($this->stringOrNull($data['assignee'] ?? null));
		$task->setCandidateUsers($this->arrayOrNull($data['candidateUsers'] ?? null));
		$task->setCandidateGroups($this->arrayOrNull($data['candidateGroups'] ?? null));
		$task->setCandidateRole($this->stringOrNull($data['candidateRole'] ?? null));
		$task->setRoutingStrategy($this->stringOrNull($data['routingStrategy'] ?? null));
		$task->setRoutingFallback($this->stringOrNull($data['routingFallback'] ?? null));
		$task->setOnBehalfOf($this->stringOrNull($data['onBehalfOf'] ?? null));
		$task->setMandate($this->stringOrNull($data['mandate'] ?? null));
		$task->setRequester($this->stringOrNull($data['requester'] ?? null));
		$task->setWatchers($this->arrayOrNull($data['watchers'] ?? null));
		$task->setSlaValue(isset($data['slaValue']) === true ? (int)$data['slaValue'] : null);
		$task->setSlaUnit($this->stringOrNull($data['slaUnit'] ?? null));
		$task->setCompliancePeriodDays(isset($data['compliancePeriodDays']) === true ? (int)$data['compliancePeriodDays'] : null);
		$task->setRecurrence($this->stringOrNull($data['recurrence'] ?? null));
		$task->setObjectUuid($this->stringOrNull($data['objectUuid'] ?? null));
		$task->setRegisterId(isset($data['registerId']) === true ? (int)$data['registerId'] : null);
		$task->setSchemaId(isset($data['schemaId']) === true ? (int)$data['schemaId'] : null);
		$task->setParentTaskId(isset($data['parentTaskId']) === true ? (int)$data['parentTaskId'] : null);
		$task->setEpicTaskId(isset($data['epicTaskId']) === true ? (int)$data['epicTaskId'] : null);
		$task->setPercentComplete(isset($data['percentComplete']) === true ? (int)$data['percentComplete'] : null);
		$task->setResponses($this->arrayOrNull($data['responses'] ?? null));
		$task->setEvidence($this->arrayOrNull($data['evidence'] ?? null));
		$task->setCreatedBy($actor);

		// Template FREEZE at creation: id, version and the snapshot land
		// together, and later evaluation reads only the snapshot.
		$task->setTemplateId($this->stringOrNull($data['templateId'] ?? null));
		$task->setTemplateVersion(isset($data['templateVersion']) === true ? (int)$data['templateVersion'] : null);
		$task->setTemplateSnapshot($this->arrayOrNull($data['templateSnapshot'] ?? null));

		return $task;
	}//end buildTask()

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

	/**
	 * Insert the typed relations named at creation.
	 *
	 * @param Task $task The persisted task.
	 * @param array<string, mixed> $data The creation payload; `relations` is
	 *                                   a list of {role, objectUuid,
	 *                                   registerId?, schemaId?}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	private function insertRelations(Task $task, array $data): void {
		$relations = ($data['relations'] ?? null);
		if (is_array($relations) === false) {
			return;
		}

		foreach ($relations as $relation) {
			if (is_array($relation) === false) {
				continue;
			}

			$role = trim((string)($relation['role'] ?? ''));
			$objectUuid = trim((string)($relation['objectUuid'] ?? ''));
			if ($role === '' || $objectUuid === '') {
				throw new TaskValidationException('A task relation requires both a role and an objectUuid.');
			}

			$row = new TaskRelation();
			$row->setTaskId((int)$task->getId());
			$row->setRole($role);
			$row->setObjectUuid($objectUuid);
			$row->setRegisterId(isset($relation['registerId']) === true ? (int)$relation['registerId'] : null);
			$row->setSchemaId(isset($relation['schemaId']) === true ? (int)$relation['schemaId'] : null);
			$this->relations->insert($row);
		}
	}//end insertRelations()

	/**
	 * Parse a date field: DateTime passes, ISO strings parse, junk refuses.
	 *
	 * @param mixed $value The incoming value.
	 * @param string $field The field name, for the refusal message.
	 *
	 * @return DateTime|null The parsed date, or null for absent.
	 *
	 * @throws TaskValidationException When present but unparsable.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-due_at-advises-expires_at-enforces
	 */
	private function parseDate(mixed $value, string $field): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		if ($value instanceof DateTime === true) {
			return $value;
		}

		if (is_string($value) === true) {
			try {
				return new DateTime($value);
			} catch (Throwable) {
				// Falls through to the refusal below.
			}
		}

		throw new TaskValidationException(
			sprintf("Field '%s' does not parse as a date.", $field)
		);
	}//end parseDate()

	/**
	 * The checklist must be a typed array of {id, label} items.
	 *
	 * A STRING is refused by name: procest stores JSON-in-a-string today,
	 * which is exactly the unqueryable shape this entity removes.
	 *
	 * @param mixed $value The incoming checklist.
	 *
	 * @return array<int, array<string, mixed>>|null The validated checklist.
	 *
	 * @throws TaskValidationException When it is a string or malformed.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
	 */
	private function validChecklist(mixed $value): ?array {
		if ($value === null) {
			return null;
		}

		if (is_string($value) === true) {
			throw new TaskValidationException(
				'The checklist must be a typed array of {id, label, description, checked} items, not a string containing JSON.'
			);
		}

		if (is_array($value) === false) {
			throw new TaskValidationException('The checklist must be a typed array of {id, label, description, checked} items.');
		}

		$items = [];
		foreach ($value as $item) {
			if (is_array($item) === false || trim((string)($item['id'] ?? '')) === '' || trim((string)($item['label'] ?? '')) === '') {
				throw new TaskValidationException('Every checklist item requires an id and a label.');
			}

			$items[] = [
				'id' => (string)$item['id'],
				'label' => (string)$item['label'],
				'description' => ($item['description'] ?? null),
				'checked' => (bool)($item['checked'] ?? false),
			];
		}

		return $items;
	}//end validChecklist()

	/**
	 * A trimmed string, or null for absent/empty.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return string|null The string, or null.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = trim((string)$value);
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()

	/**
	 * An array, or null for absent.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return array<int|string, mixed>|null The array, or null.
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end arrayOrNull()
}//end class
