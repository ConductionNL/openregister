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
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskSequenceUndecidedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Provisions and progresses ordered task sequences.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the codebase's uuid
 * idiom and TaskState is the published, stateless state vocabulary.
 */
class TaskSequenceService implements TaskSequenceServiceInterface {

	/**
	 * The system actor prefix a sequence writes into task audits.
	 *
	 * @var string
	 */
	public const ACTOR_PREFIX = 'task-sequence:';

	/**
	 * Constructor.
	 *
	 * @param TaskSequenceServices $services The sequence rows, the read-only
	 *                                       position reader, the authorized
	 *                                       task lifecycle and the dispatcher.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly TaskSequenceServices $services,
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
	 * @param ResolvedChain  $chain  The compiled template
	 *                               ({@see \OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller::compile()})
	 *                               and the resolved amount tier, or null for every declared position.
	 * @param SequenceAnchor $anchor The object the approval is about, who asked, its register, and optional flow provenance.
	 *
	 * @return TaskSequence The provisioned, running sequence.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function provision(ResolvedChain $chain, SequenceAnchor $anchor): TaskSequence {
		$sequence = self::newSequence(chain: $chain);
		self::anchor(sequence: $sequence, anchor: $anchor);
		self::open(sequence: $sequence, provenance: $anchor->provenance);

		return $this->insertWithPositions(sequence: $sequence, chain: $chain);
	}//end provision()

	/**
	 * The approval chain a sequence walks: a compiled template and its resolved tier.
	 *
	 * Built here, so callers outside this component never name the chain class.
	 *
	 * @param array<string, mixed>                  $template      The compiled template.
	 * @param array<int, array<string, mixed>>|null $tierPositions The resolved amount tier, or null for every declared position.
	 *
	 * @return ResolvedChain The chain, to hand to {@see provision()}.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function chain(array $template, ?array $tierPositions = null): ResolvedChain {
		return new ResolvedChain(template: $template, tierPositions: $tierPositions);
	}//end chain()

	/**
	 * What a sequence is about: the object, the requester and the register.
	 *
	 * Built here, so callers outside this component never name the anchor class.
	 *
	 * @param string      $objectUuid  The object the approval is about.
	 * @param string|null $requesterId Who attempted the gated transition.
	 * @param int|null    $registerId  The anchor's register.
	 *
	 * @return SequenceAnchor The anchor, to hand to {@see provision()}.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function anchorOf(string $objectUuid, ?string $requesterId, ?int $registerId = null): SequenceAnchor {
		return new SequenceAnchor(objectUuid: $objectUuid, requesterId: $requesterId, registerId: $registerId);
	}//end anchorOf()

	/**
	 * A new sequence row carrying the frozen template and, when given, the resolved tier.
	 *
	 * @param ResolvedChain $chain The compiled template, its positions and the resolved tier, or null.
	 *
	 * @return TaskSequence The unsaved row.
	 */
	private static function newSequence(ResolvedChain $chain): TaskSequence {
		$template = $chain->template;
		$sequence = new TaskSequence();
		$sequence->setUuid(Uuid::v4()->toRfc4122());
		$sequence->setTemplateId((string)($template['templateId'] ?? ''));
		$sequence->setTemplateVersion((int)($template['templateVersion'] ?? 1));
		$sequence->setTemplateSnapshot($template);
		$sequence->setSchemaId(self::schemaIdOf(template: $template));
		$sequence->setChainKey((string)($template['name'] ?? ''));
		if ($chain->tierPositions !== null) {
			$sequence->setResolvedTier(['positions' => $chain->tierPositions]);
		}

		$sequence->setPositionCursor((int)(($chain->positions()[0]['order'] ?? 1)));

		return $sequence;
	}//end newSequence()

	/**
	 * The schema id a template declares, as an int, or null.
	 *
	 * @param array<string, mixed> $template The compiled template.
	 *
	 * @return int|null The schema id.
	 */
	private static function schemaIdOf(array $template): ?int {
		$schemaId = ($template['schemaId'] ?? null);
		if ($schemaId === null) {
			return null;
		}

		return (int)$schemaId;
	}//end schemaIdOf()

	/**
	 * Record what the approval is about and who asked for it.
	 *
	 * @param TaskSequence   $sequence The unsaved row.
	 * @param SequenceAnchor $anchor   The object the approval is about, who asked, and its register.
	 *
	 * @return void
	 */
	private static function anchor(TaskSequence $sequence, SequenceAnchor $anchor): void {
		$sequence->setAnchorObjectUuid($anchor->objectUuid);
		$sequence->setRegisterId($anchor->registerId);
		$sequence->setRequesterId($anchor->requesterId);
	}//end anchor()

	/**
	 * Record the flow provenance and open the sequence now.
	 *
	 * @param TaskSequence            $sequence   The unsaved row.
	 * @param SequenceProvenance|null $provenance Optional flow-run and node provenance.
	 *
	 * @return void
	 */
	private static function open(TaskSequence $sequence, ?SequenceProvenance $provenance): void {
		$sequence->setRunUuid($provenance?->runUuid);
		$sequence->setNodeId($provenance?->nodeId);
		$sequence->openNow();
	}//end open()

	/**
	 * Insert the sequence, then import one task per position, the first enabled.
	 *
	 * @param TaskSequence  $sequence The unsaved row.
	 * @param ResolvedChain $chain    The compiled template and the positions to provision.
	 *
	 * @return TaskSequence The inserted sequence.
	 */
	private function insertWithPositions(TaskSequence $sequence, ResolvedChain $chain): TaskSequence {
		$positions = $chain->positions();
		$sequence = $this->services->sequences->insert($sequence);
		$creator = ($sequence->getRequesterId() ?? self::ACTOR_PREFIX . (string)$sequence->getUuid());
		$index = 0;
		foreach ($positions as $position) {
			$index++;
			$snapshot = $this->positionSnapshot(position: $position, template: $chain->template);
			$this->services->tasks->import(
				data: self::positionTaskData(sequence: $sequence, position: new ChainPosition(snapshot: $snapshot, index: $index, count: count($positions))),
				actor: $creator
			);
		}

		return $sequence;
	}//end insertWithPositions()

	/**
	 * The task one position is provisioned as.
	 *
	 * @param TaskSequence  $sequence The inserted sequence.
	 * @param ChainPosition $position The position as frozen onto its task, its 1-based place and the chain's length.
	 *
	 * @return array<string, mixed> The task data.
	 */
	private static function positionTaskData(TaskSequence $sequence, ChainPosition $position): array {
		return self::stepText(sequence: $sequence, position: $position)
			+ self::routing(position: $position)
			+ self::anchorFields(sequence: $sequence)
			+ [
				'templateSnapshot' => $position->snapshot,
				'sequenceUuid' => $sequence->getUuid(),
				'sequencePosition' => $position->order(),
				'runUuid' => $sequence->getRunUuid(),
				'nodeId' => $sequence->getNodeId(),
			];
	}//end positionTaskData()

	/**
	 * A position task's title and description.
	 *
	 * @param TaskSequence  $sequence The sequence.
	 * @param ChainPosition $position The position: its ordinal and how many positions the sequence has.
	 *
	 * @return array{title: string, description: string} The text.
	 */
	private static function stepText(TaskSequence $sequence, ChainPosition $position): array {
		$chainKey = (string)$sequence->getChainKey();
		$order = $position->order();
		$count = $position->count;

		return [
			'title' => sprintf('Approve %s (step %d of %d)', $chainKey, $order, $count),
			'description' => sprintf(
				'Approval step %d of %d for %s on object %s.',
				$order,
				$count,
				$chainKey,
				(string)$sequence->getAnchorObjectUuid()
			),
		];
	}//end stepText()

	/**
	 * Who a position task is offered to, and whether it starts enabled.
	 *
	 * @param ChainPosition $position The position and its 1-based place in the list.
	 *
	 * @return array<string, mixed> The state and routing fields.
	 */
	private static function routing(ChainPosition $position): array {
		$state = Task::STATE_AVAILABLE;
		if ($position->index === 1) {
			$state = Task::STATE_ENABLED;
		}

		return [
			'state' => $state,
			'performerType' => Task::PERFORMER_GROUP,
			'candidateGroups' => [trim((string)($position->snapshot['role'] ?? ''))],
			'routingStrategy' => 'single-role',
		];
	}//end routing()

	/**
	 * The requester, anchor and template identity every position task carries.
	 *
	 * @param TaskSequence $sequence The sequence.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private static function anchorFields(TaskSequence $sequence): array {
		return [
			'requester' => $sequence->getRequesterId(),
			'objectUuid' => $sequence->getAnchorObjectUuid(),
			'registerId' => $sequence->getRegisterId(),
			'schemaId' => $sequence->getSchemaId(),
			'templateId' => $sequence->getTemplateId(),
			'templateVersion' => $sequence->getTemplateVersion(),
		];
	}//end anchorFields()

	/**
	 * A position as frozen onto its task, carrying the chain's declared
	 * approving set so the task alone tells what approves it.
	 *
	 * @param array<string, mixed> $position The declared position.
	 * @param array<string, mixed> $template The compiled template.
	 *
	 * @return array<string, mixed> The position, with `approvingOutcomes` when declared.
	 *
	 * @spec openspec/changes/consume-follows-the-approving-rule/specs/flow-approval-consolidation/spec.md#requirement-a-human-in-the-loop-semantic-is-not-retired-until-it-has-a-named-home
	 */
	private function positionSnapshot(array $position, array $template): array {
		$approving = TaskState::approvingSet(declared: ($template['approvingOutcomes'] ?? null));
		if ($approving !== []) {
			$position['approvingOutcomes'] = $approving;
		}

		return $position;
	}//end positionSnapshot()

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
	 * - a completion that neither approves nor rejects closes the sequence as
	 *   undecided, terminates the remainder and dispatches
	 *   {@see TaskSequenceUndecidedEvent}
	 * - any other terminal end (cancelled, terminated, disabled) closes the
	 *   sequence as terminated and terminates the remainder
	 *
	 * An outcome approves only when it is in the frozen template's
	 * `approvingOutcomes`, or in {@see TaskState::APPROVING_OUTCOMES} when the
	 * template declares none.
	 *
	 * Idempotent: a terminal sequence absorbs every further report, which is
	 * exactly what the remainder-termination fan-out produces.
	 *
	 * @param Task $task The sequence task as persisted in a terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-rejection-terminates-the-sequence-and-every-task-it-still-owns
	 * @spec openspec/changes/a-sequence-advances-only-on-an-approving-outcome/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
	 */
	public function onTaskTerminal(Task $task): void {
		$sequence = $this->runningSequenceOf(task: $task);
		if ($sequence === null) {
			return;
		}

		if ((string)$task->getState() !== Task::STATE_COMPLETED) {
			$this->closeTerminated(sequence: $sequence, task: $task);

			return;
		}

		$this->decide(sequence: $sequence, task: $task);
	}//end onTaskTerminal()

	/**
	 * The still-running sequence a task belongs to, or null.
	 *
	 * @param Task $task The task.
	 *
	 * @return TaskSequence|null The sequence; null outside any sequence,
	 *                           for a dangling reference, or once terminal.
	 */
	private function runningSequenceOf(Task $task): ?TaskSequence {
		$sequenceUuid = trim((string)$task->getSequenceUuid());
		if ($sequenceUuid === '') {
			return null;
		}

		$sequence = $this->findSequence(task: $task, sequenceUuid: $sequenceUuid);
		if ($sequence === null || $sequence->isTerminal() === true) {
			return null;
		}

		return $sequence;
	}//end runningSequenceOf()

	/**
	 * The sequence a task names, or null (logged) when it does not resolve.
	 *
	 * @param Task $task The task naming it.
	 * @param string $sequenceUuid The sequence uuid.
	 *
	 * @return TaskSequence|null The sequence.
	 */
	private function findSequence(Task $task, string $sequenceUuid): ?TaskSequence {
		try {
			return $this->services->sequences->findByUuid(uuid: $sequenceUuid);
		} catch (Throwable $missing) {
			$this->logger->warning(
				'[TaskSequenceService] Task ' . (string)$task->getUuid() . ' names sequence '
				. $sequenceUuid . ', which does not resolve: ' . $missing->getMessage()
			);

			return null;
		}
	}//end findSequence()

	/**
	 * Act on a completed position: reject, advance, or close as undecided.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $task The completed position.
	 *
	 * @return void
	 */
	private function decide(TaskSequence $sequence, Task $task): void {
		if (TaskState::isRejectingOutcome(outcome: $task->getOutcome()) === true) {
			$this->closeRejected(sequence: $sequence, task: $task);

			return;
		}

		if (TaskState::isApprovingOutcome(outcome: $task->getOutcome(), approving: $this->approvingOutcomes(sequence: $sequence)) === true) {
			$this->advance(sequence: $sequence, completed: $task);

			return;
		}

		$this->closeUndecided(sequence: $sequence, task: $task);
	}//end decide()

	/**
	 * Close a sequence whose position ended without a decision.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $task The position that ended cancelled, terminated or disabled.
	 *
	 * @return void
	 */
	private function closeTerminated(TaskSequence $sequence, Task $task): void {
		$this->close(closure: new SequenceClosure(
			sequence: $sequence,
			status: TaskSequence::STATUS_TERMINATED,
			outcome: 'terminated',
			reason: sprintf(
				"Position %d ended as '%s' without a decision.",
				(int)$task->getSequencePosition(),
				(string)$task->getState()
			)
		));
	}//end closeTerminated()

	/**
	 * Close a sequence whose position rejected.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $task The rejecting position.
	 *
	 * @return void
	 */
	private function closeRejected(TaskSequence $sequence, Task $task): void {
		$this->close(closure: new SequenceClosure(
			sequence: $sequence,
			status: TaskSequence::STATUS_REJECTED,
			outcome: $this->statusOnReject(sequence: $sequence, position: (int)$task->getSequencePosition()),
			reason: sprintf(
				'Rejected at position %d by %s.',
				(int)$task->getSequencePosition(),
				(string)($task->getCompletedBy() ?? 'an unnamed decider')
			)
		));
	}//end closeRejected()

	/**
	 * Enable the next position, or complete the sequence.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $completed The position that just completed approvingly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	private function advance(TaskSequence $sequence, Task $completed): void {
		$next = $this->nextPosition(sequence: $sequence, completed: $completed);
		if ($next !== null) {
			$this->enableNext(sequence: $sequence, advance: new PositionAdvance(completed: $completed, next: $next));

			return;
		}

		$this->completeSequence(sequence: $sequence, completed: $completed);
	}//end advance()

	/**
	 * The first later position that is not terminal yet, or null.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $completed The position that just completed.
	 *
	 * @return Task|null The next position.
	 */
	private function nextPosition(TaskSequence $sequence, Task $completed): ?Task {
		foreach ($this->services->taskReader->findBySequence(sequenceUuid: (string)$sequence->getUuid()) as $candidate) {
			$later = ((int)$candidate->getSequencePosition() > (int)$completed->getSequencePosition());
			if ($later === true && $candidate->isInTerminalState() === false) {
				return $candidate;
			}
		}

		return null;
	}//end nextPosition()

	/**
	 * Enable the next position and move the cursor onto it.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param PositionAdvance $advance The completed position and the next one.
	 *
	 * @return void
	 */
	private function enableNext(TaskSequence $sequence, PositionAdvance $advance): void {
		$source = self::ACTOR_PREFIX . (string)$sequence->getUuid();
		$this->services->tasks->enable(uuid: (string)$advance->next->getUuid(), trigger: new TaskTrigger(source: $source, reason: $advance->reason()));
		$sequence->setPositionCursor((int)$advance->next->getSequencePosition());
		$this->services->sequences->update($sequence);
	}//end enableNext()

	/**
	 * Complete the sequence after its last position approved, and announce it.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $completed The final position.
	 *
	 * @return void
	 */
	private function completeSequence(TaskSequence $sequence, Task $completed): void {
		$statusOnApprove = $this->statusOnApprove(sequence: $sequence, position: (int)$completed->getSequencePosition());
		$sequence->closeAs(status: TaskSequence::STATUS_COMPLETED, outcome: $statusOnApprove);
		$persisted = $this->services->sequences->update($sequence);

		$this->services->dispatcher->dispatchTyped(
			new TaskSequenceCompletedEvent(
				sequence: $persisted,
				finalTask: $completed,
				decider: $completed->getCompletedBy(),
				statusOnApprove: $statusOnApprove
			)
		);
	}//end completeSequence()

	/**
	 * Close a sequence whose position completed without a decision.
	 *
	 * @param TaskSequence $sequence The running sequence.
	 * @param Task $task The position that completed with an outcome that
	 *                   neither approves nor rejects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-sequence-advances-only-on-an-approving-outcome/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
	 */
	private function closeUndecided(TaskSequence $sequence, Task $task): void {
		$this->close(closure: new SequenceClosure(
			sequence: $sequence,
			status: TaskSequence::STATUS_UNDECIDED,
			outcome: TaskSequence::STATUS_UNDECIDED,
			reason: sprintf(
				"Position %d completed with outcome '%s', which neither approves nor rejects.",
				(int)$task->getSequencePosition(),
				(string)$task->getOutcome()
			)
		));

		$this->services->dispatcher->dispatchTyped(new TaskSequenceUndecidedEvent(sequence: $sequence, task: $task));
	}//end closeUndecided()

	/**
	 * The approving set frozen onto a sequence.
	 *
	 * @param TaskSequence $sequence The sequence whose snapshot to read.
	 *
	 * @return array<int, string> The declared set, or the default set when none is usable.
	 *
	 * @spec openspec/changes/a-sequence-advances-only-on-an-approving-outcome/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	private function approvingOutcomes(TaskSequence $sequence): array {
		return TaskState::approvingSetOrDefault(declared: (($sequence->getTemplateSnapshot() ?? [])['approvingOutcomes'] ?? null));
	}//end approvingOutcomes()

	/**
	 * Close a sequence and terminate every task it still owns.
	 *
	 * The sequence row is closed FIRST, so the terminality events the
	 * remainder-termination raises find a terminal sequence and no-op. The
	 * closed sequence, its tasks, its decisions and its audit remain
	 * readable indefinitely: nothing here deletes (design D-5).
	 *
	 * @param SequenceClosure $closure The sequence to close, its terminal status, the recorded outcome,
	 *                                 and why (written onto every terminated task's audit).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-rejection-terminates-the-sequence-and-every-task-it-still-owns
	 */
	private function close(SequenceClosure $closure): void {
		$sequence = $closure->sequence;
		$sequence->closeAs(status: $closure->status, outcome: $closure->outcome);
		$this->services->sequences->update($sequence);

		foreach ($this->services->taskReader->findBySequence(sequenceUuid: (string)$sequence->getUuid()) as $sibling) {
			if ($sibling->isInTerminalState() === false) {
				$this->terminateMoot(closure: $closure, sibling: $sibling);
			}
		}
	}//end close()

	/**
	 * Terminate one remaining task of a closed sequence.
	 *
	 * Terminating the remainder must not fail the decision that closed the
	 * sequence; a missed task is logged and left to the terminal sweep.
	 *
	 * @param SequenceClosure $closure The closed sequence, and why (written onto the task's audit).
	 * @param Task            $sibling The task it still owns.
	 *
	 * @return void
	 */
	private function terminateMoot(SequenceClosure $closure, Task $sibling): void {
		$sequence = $closure->sequence;
		try {
			$this->services->tasks->terminateAsMoot(
				uuid: (string)$sibling->getUuid(),
				reason: $closure->reason,
				source: self::ACTOR_PREFIX . (string)$sequence->getUuid()
			);
		} catch (Throwable $failure) {
			$this->logger->error(
				'[TaskSequenceService] Could not terminate task ' . (string)$sibling->getUuid()
				. ' of closed sequence ' . (string)$sequence->getUuid() . ': ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}
	}//end terminateMoot()

	/**
	 * The `statusOnApprove` the frozen declaration resolves for a position.
	 *
	 * @param TaskSequence $sequence The sequence.
	 * @param int $position The final position's ordinal.
	 *
	 * @return string The resolved status; `approved` when undeclared.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-010
	 */
	private function statusOnApprove(TaskSequence $sequence, int $position): string {
		return (self::firstDeclared(entries: self::declaredAt(sequence: $sequence, position: $position), key: 'statusOnApprove') ?? 'approved');
	}//end statusOnApprove()

	/**
	 * The `statusOnReject` the frozen declaration resolves for a position.
	 *
	 * @param TaskSequence $sequence The sequence.
	 * @param int $position The rejecting position's ordinal.
	 *
	 * @return string The resolved status; `rejected` when undeclared.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	private function statusOnReject(TaskSequence $sequence, int $position): string {
		return (self::firstDeclared(entries: self::declaredAt(sequence: $sequence, position: $position), key: 'statusOnReject') ?? 'rejected');
	}//end statusOnReject()

	/**
	 * The declared entries of one position: the resolved tier's, else the template snapshot's.
	 *
	 * @param TaskSequence $sequence The sequence whose snapshot to read.
	 * @param int          $position The position's ordinal.
	 *
	 * @return array<int, array<string, mixed>> The entries declaring that ordinal, in declared order.
	 */
	private static function declaredAt(TaskSequence $sequence, int $position): array {
		$snapshot = ($sequence->getTemplateSnapshot() ?? []);
		$declared = ($sequence->getResolvedTier()['positions'] ?? ($snapshot['positions'] ?? []));

		return array_values(
			array_filter(
				(array)$declared,
				static fn (mixed $entry): bool => is_array($entry) === true && (int)($entry['order'] ?? 0) === $position
			)
		);
	}//end declaredAt()

	/**
	 * The first non-empty value an entry declares under a key.
	 *
	 * @param array<int, array<string, mixed>> $entries The position's declared entries.
	 * @param string                           $key     `statusOnApprove` or `statusOnReject`.
	 *
	 * @return string|null The value, or null when none declares one (the vocabulary default then applies).
	 */
	private static function firstDeclared(array $entries, string $key): ?string {
		foreach ($entries as $entry) {
			$value = trim((string)($entry[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}//end firstDeclared()
}//end class
