<?php

/**
 * The ordered task sequence: provision, advance, reject, terminate.
 *
 * The task equivalent of the twenty lines at the retired
 * `ApprovalService.php:193-204`, made explicit, authorized and audited
 * (flow-approval-consolidation design D-1). A sequence is ordinal on
 * purpose: no branching, no parallelism, no timers of its own. An approval
 * that needs a graph uses `openregister.user-task` nodes in a real flow.
 *
 * Every task mutation goes through {@see TaskService}'s lifecycle verbs;
 * this service never writes a task row directly. Progression is driven by
 * {@see \OCA\OpenRegister\Listener\TaskSequenceProgressListener} observing
 * committed task terminality, so the next position is enabled in the SAME
 * request as the completing decision.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Provisions and progresses ordered task sequences.
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the codebase's uuid
 * idiom and TaskState is the published, stateless state vocabulary.
 */
class TaskSequenceService {

	/**
	 * The system actor prefix a sequence writes into task audits.
	 *
	 * @var string
	 */
	public const ACTOR_PREFIX = 'task-sequence:';

	/**
	 * Constructor.
	 *
	 * @param TaskSequenceMapper $sequences The sequence rows.
	 * @param TaskMapper $taskReader Reads a sequence's positions. Read-only
	 *                               here; every write goes through the verbs.
	 * @param TaskService $tasks The authorized task lifecycle.
	 * @param IEventDispatcher $dispatcher Announces sequence completion.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly TaskSequenceMapper $sequences,
		private readonly TaskMapper $taskReader,
		private readonly TaskService $tasks,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Provision a sequence from a compiled template.
	 *
	 * Creates every position and enables ONLY the first. The template
	 * snapshot and the resolved tier are FROZEN onto the sequence row, so a
	 * schema edit or an amount edit cannot re-shape or re-route an approval
	 * that is already running (design D-3).
	 *
	 * @param array<string, mixed> $template The compiled template
	 *                                       ({@see \OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller::compile()}).
	 * @param string $anchorObjectUuid The object the approval is about.
	 * @param string|null $requesterId Who attempted the gated transition.
	 * @param array<int, array<string, mixed>>|null $tierPositions The resolved
	 *                                                             amount tier, or
	 *                                                             null for every
	 *                                                             declared position.
	 * @param int|null $registerId The anchor's register.
	 * @param string|null $runUuid Optional flow-run provenance.
	 * @param string|null $nodeId Optional node provenance.
	 *
	 * @return TaskSequence The provisioned, running sequence.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function provision(
		array $template,
		string $anchorObjectUuid,
		?string $requesterId,
		?array $tierPositions = null,
		?int $registerId = null,
		?string $runUuid = null,
		?string $nodeId = null,
	): TaskSequence {
		$positions = ($tierPositions ?? (array)($template['positions'] ?? []));

		$sequence = new TaskSequence();
		$sequence->setUuid(Uuid::v4()->toRfc4122());
		$sequence->setTemplateId((string)($template['templateId'] ?? ''));
		$sequence->setTemplateVersion((int)($template['templateVersion'] ?? 1));
		$sequence->setTemplateSnapshot($template);
		$sequence->setAnchorObjectUuid($anchorObjectUuid);
		$sequence->setRegisterId($registerId);
		$schemaId = ($template['schemaId'] ?? null);
		if ($schemaId !== null) {
			$schemaId = (int)$schemaId;
		}

		$sequence->setSchemaId($schemaId);
		$sequence->setChainKey((string)($template['name'] ?? ''));
		$sequence->setRequesterId($requesterId);
		if ($tierPositions !== null) {
			$sequence->setResolvedTier(['positions' => $tierPositions]);
		}

		$sequence->setPositionCursor((int)(($positions[0]['order'] ?? 1)));
		$sequence->setStatus(TaskSequence::STATUS_RUNNING);
		$sequence->setRunUuid($runUuid);
		$sequence->setNodeId($nodeId);
		$sequence->setOpenedAt(new DateTime());
		$sequence = $this->sequences->insert($sequence);

		$creator = ($requesterId ?? self::ACTOR_PREFIX . (string)$sequence->getUuid());
		$count = count($positions);
		$index = 0;
		foreach ($positions as $position) {
			$index++;
			$order = (int)($position['order'] ?? $index);
			$role = trim((string)($position['role'] ?? ''));
			$state = Task::STATE_AVAILABLE;
			if ($index === 1) {
				$state = Task::STATE_ENABLED;
			}

			$this->tasks->import(
				data: [
					'title' => sprintf('Approve %s (step %d of %d)', (string)$sequence->getChainKey(), $order, $count),
					'description' => sprintf(
						'Approval step %d of %d for %s on object %s.',
						$order,
						$count,
						(string)$sequence->getChainKey(),
						$anchorObjectUuid
					),
					'state' => $state,
					'performerType' => Task::PERFORMER_GROUP,
					'candidateGroups' => [$role],
					'routingStrategy' => 'single-role',
					'requester' => $requesterId,
					'objectUuid' => $anchorObjectUuid,
					'registerId' => $registerId,
					'schemaId' => $sequence->getSchemaId(),
					'templateId' => $sequence->getTemplateId(),
					'templateVersion' => $sequence->getTemplateVersion(),
					'templateSnapshot' => $position,
					'sequenceUuid' => $sequence->getUuid(),
					'sequencePosition' => $order,
					'runUuid' => $runUuid,
					'nodeId' => $nodeId,
				],
				actor: $creator
			);
		}//end foreach

		return $sequence;
	}//end provision()

	/**
	 * Progress a sequence after one of its tasks reached a terminal state.
	 *
	 * Called in the SAME request as the completing decision, after the
	 * task's own transaction committed. Three paths:
	 *
	 * - an approving completion enables the next position, or completes the
	 *   sequence and dispatches {@see TaskSequenceCompletedEvent}
	 * - a rejecting completion closes the sequence as rejected (KEPT, never
	 *   deleted; design D-5) and terminates every remaining task with a
	 *   reason naming the rejecting position
	 * - any other terminal end (cancelled, terminated, disabled) closes the
	 *   sequence as terminated and terminates the remainder
	 *
	 * Idempotent: a terminal sequence absorbs every further report, which is
	 * exactly what the remainder-termination fan-out produces.
	 *
	 * @param Task $task The sequence task as persisted in a terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-a-rejection-terminates-the-sequence-and-every-task-it-still-owns
	 */
	public function onTaskTerminal(Task $task): void {
		$sequenceUuid = trim((string)$task->getSequenceUuid());
		if ($sequenceUuid === '') {
			return;
		}

		try {
			$sequence = $this->sequences->findByUuid(uuid: $sequenceUuid);
		} catch (Throwable $missing) {
			$this->logger->warning(
				'[TaskSequenceService] Task ' . (string)$task->getUuid() . ' names sequence '
				. $sequenceUuid . ', which does not resolve: ' . $missing->getMessage()
			);

			return;
		}

		if ($sequence->isTerminal() === true) {
			return;
		}

		$outcome = (string)$task->getOutcome();
		$isDecision = ((string)$task->getState() === Task::STATE_COMPLETED);

		if ($isDecision === true && TaskState::isRejectingOutcome(outcome: $outcome) === false) {
			$this->advance(sequence: $sequence, completed: $task);

			return;
		}

		if ($isDecision === true) {
			$this->close(
				sequence: $sequence,
				status: TaskSequence::STATUS_REJECTED,
				outcome: $this->statusOnReject(sequence: $sequence, position: (int)$task->getSequencePosition()),
				reason: sprintf(
					'Rejected at position %d by %s.',
					(int)$task->getSequencePosition(),
					(string)($task->getCompletedBy() ?? 'an unnamed decider')
				)
			);

			return;
		}

		$this->close(
			sequence: $sequence,
			status: TaskSequence::STATUS_TERMINATED,
			outcome: 'terminated',
			reason: sprintf(
				"Position %d ended as '%s' without a decision.",
				(int)$task->getSequencePosition(),
				(string)$task->getState()
			)
		);
	}//end onTaskTerminal()

	/**
	 * Enable the next position, or complete the sequence.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $completed The position that just completed approvingly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	private function advance(TaskSequence $sequence, Task $completed): void {
		$source = self::ACTOR_PREFIX . (string)$sequence->getUuid();
		$next = null;
		foreach ($this->taskReader->findBySequence(sequenceUuid: (string)$sequence->getUuid()) as $candidate) {
			$later = ((int)$candidate->getSequencePosition() > (int)$completed->getSequencePosition());
			if ($later === true && $candidate->isInTerminalState() === false) {
				$next = $candidate;
				break;
			}
		}

		if ($next !== null) {
			$this->tasks->enable(
				uuid: (string)$next->getUuid(),
				source: $source,
				reason: sprintf('Position %d approved; position %d is now enabled.', (int)$completed->getSequencePosition(), (int)$next->getSequencePosition())
			);
			$sequence->setPositionCursor((int)$next->getSequencePosition());
			$this->sequences->update($sequence);

			return;
		}

		$statusOnApprove = $this->statusOnApprove(sequence: $sequence, position: (int)$completed->getSequencePosition());
		$sequence->setStatus(TaskSequence::STATUS_COMPLETED);
		$sequence->setOutcome($statusOnApprove);
		$sequence->setClosedAt(new DateTime());
		$persisted = $this->sequences->update($sequence);

		$this->dispatcher->dispatchTyped(
			new TaskSequenceCompletedEvent(
				sequence: $persisted,
				finalTask: $completed,
				decider: $completed->getCompletedBy(),
				statusOnApprove: $statusOnApprove
			)
		);
	}//end advance()

	/**
	 * Close a sequence and terminate every task it still owns.
	 *
	 * The sequence row is closed FIRST, so the terminality events the
	 * remainder-termination raises find a terminal sequence and no-op. The
	 * closed sequence, its tasks, its decisions and its audit remain
	 * readable indefinitely: nothing here deletes (design D-5).
	 *
	 * @param TaskSequence $sequence The sequence to close.
	 * @param string $status The terminal status.
	 * @param string $outcome The recorded outcome.
	 * @param string $reason Why, written onto every terminated task's audit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-a-rejection-terminates-the-sequence-and-every-task-it-still-owns
	 */
	private function close(TaskSequence $sequence, string $status, string $outcome, string $reason): void {
		$sequence->setStatus($status);
		$sequence->setOutcome($outcome);
		$sequence->setClosedAt(new DateTime());
		$this->sequences->update($sequence);

		$source = self::ACTOR_PREFIX . (string)$sequence->getUuid();
		foreach ($this->taskReader->findBySequence(sequenceUuid: (string)$sequence->getUuid()) as $sibling) {
			if ($sibling->isInTerminalState() === true) {
				continue;
			}

			try {
				$this->tasks->terminateAsMoot(uuid: (string)$sibling->getUuid(), reason: $reason, source: $source);
			} catch (Throwable $failure) {
				// Terminating the remainder must not fail the decision that
				// closed the sequence; the missed task is terminal-by-sweep.
				$this->logger->error(
					'[TaskSequenceService] Could not terminate task ' . (string)$sibling->getUuid()
					. ' of closed sequence ' . (string)$sequence->getUuid() . ': ' . $failure->getMessage(),
					['exception' => $failure]
				);
			}
		}//end foreach
	}//end close()

	/**
	 * The `statusOnApprove` the frozen declaration resolves for a position.
	 *
	 * @param TaskSequence $sequence The sequence.
	 * @param int $position The final position's ordinal.
	 *
	 * @return string The resolved status; `approved` when undeclared.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-010
	 */
	private function statusOnApprove(TaskSequence $sequence, int $position): string {
		return $this->declaredStatus(sequence: $sequence, position: $position, key: 'statusOnApprove', default: 'approved');
	}//end statusOnApprove()

	/**
	 * The `statusOnReject` the frozen declaration resolves for a position.
	 *
	 * @param TaskSequence $sequence The sequence.
	 * @param int $position The rejecting position's ordinal.
	 *
	 * @return string The resolved status; `rejected` when undeclared.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-007
	 */
	private function statusOnReject(TaskSequence $sequence, int $position): string {
		return $this->declaredStatus(sequence: $sequence, position: $position, key: 'statusOnReject', default: 'rejected');
	}//end statusOnReject()

	/**
	 * Read a per-position declared status off the FROZEN snapshot.
	 *
	 * @param TaskSequence $sequence The sequence whose snapshot to read.
	 * @param int $position The position's ordinal.
	 * @param string $key `statusOnApprove` or `statusOnReject`.
	 * @param string $default The vocabulary default.
	 *
	 * @return string The declared value, or the default.
	 */
	private function declaredStatus(TaskSequence $sequence, int $position, string $key, string $default): string {
		$snapshot = ($sequence->getTemplateSnapshot() ?? []);
		$declared = ($sequence->getResolvedTier()['positions'] ?? ($snapshot['positions'] ?? []));
		foreach ((array)$declared as $entry) {
			if (is_array($entry) === true && (int)($entry['order'] ?? 0) === $position) {
				$value = trim((string)($entry[$key] ?? ''));
				if ($value !== '') {
					return $value;
				}
			}
		}

		return $default;
	}//end declaredStatus()
}//end class
