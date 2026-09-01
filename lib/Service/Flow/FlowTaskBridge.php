<?php

/**
 * The bridge between the graph and the task service, in both directions.
 *
 * FORWARD: the user-task node hands over what it wants asked and this class
 * turns it into ONE task through {@see TaskService::create()}, stamped with the
 * run and node that raised it. It validates nothing about the task itself; that
 * is the task builder's boundary and re-doing it here would be a second copy of
 * the vocabulary.
 *
 * BACKWARD: when a task the graph raised reaches a terminal state, this class
 * wakes the run (D-2: `signal()` with an EMPTY payload, because the answer is
 * in the task row, not in transit) and spends the node's `advance` budget by
 * calling the SAME advance path the worker and a synchronous run use
 * ({@see FlowRunAdvancer::advance()}). There is no second walk implementation:
 * the budget travels as a per-walk ceiling on the run context and the engine's
 * own loop counter honours it (D-5).
 *
 * The advancer is resolved through the container rather than injected, for the
 * reason {@see FlowRunVersionPin} gives: the node depends on this class, the
 * registry constructs the node, and the run service holds the registry. An
 * injected advancer would close that loop at construction time.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskState;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the node's task, reads it back, and continues the run it parked.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TaskState is a stateless published
 * vocabulary and FlowAdvanceBudget a value object with named constructors;
 * calling either statically is the design, an instance would be a second copy.
 */
class FlowTaskBridge {

	/**
	 * Resume-slot key holding the created task's uuid.
	 *
	 * @var string
	 */
	public const SLOT_TASK_UUID = 'taskUuid';

	/**
	 * Resume-slot key holding when the task was created. Written ONCE.
	 *
	 * @var string
	 */
	public const SLOT_ASKED_AT = 'askedAt';

	/**
	 * Resume-slot key holding the node's stored `advance` budget.
	 *
	 * @var string
	 */
	public const SLOT_ADVANCE = 'advance';

	/**
	 * Prefix of the actor a propagation records itself as.
	 *
	 * @var string
	 */
	public const ACTOR_PREFIX = 'flow-run:';

	/**
	 * Constructor.
	 *
	 * @param TaskService $tasks The task lifecycle.
	 * @param FlowRunMapper $runs Reads the run for its version pin, and to wake it.
	 * @param ContainerInterface $container Lazily resolves the run service and advancer.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly TaskService $tasks,
		private readonly FlowRunMapper $runs,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create the one task a user-task node asks for.
	 *
	 * Provenance is stamped HERE, not left to the node's config: `runUuid` and
	 * `nodeId` are what cancellation propagation and the completion listener
	 * find the task by, and `definitionVersion` is the run's pin, so a
	 * `nodeId` recorded on the task keeps pointing into the graph the run is
	 * actually walking.
	 *
	 * When the node names a routing strategy and no direct assignee, the task
	 * is OFFERED after creation so the strategy resolves now rather than at
	 * the first claim; a task with a direct assignee is created active.
	 *
	 * @param array<string, mixed> $data The task fields the node assembled.
	 * @param string $runUuid The run raising the task.
	 * @param string $nodeId The node raising it.
	 * @param string|null $actor The run's acting identity; the task's requester.
	 *
	 * @return Task The persisted task.
	 *
	 * @throws \OCA\OpenRegister\Exception\TaskValidationException When the task builder refuses a value.
	 * @throws \OCA\OpenRegister\Exception\TaskAccessDeniedException Without an acting identity.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function createTask(array $data, string $runUuid, string $nodeId, ?string $actor): Task {
		$data['runUuid'] = $runUuid;
		$data['nodeId'] = $nodeId;
		$data['requester'] = ($data['requester'] ?? $actor);
		$data['definitionVersion'] = $this->definitionVersionOf(runUuid: $runUuid);

		$task = $this->tasks->create(data: $data, actor: $actor);

		$strategy = trim((string)($data['routingStrategy'] ?? ''));
		if ($strategy !== '' && trim((string)($data['assignee'] ?? '')) === '') {
			$task = $this->tasks->offer(
				uuid: (string)$task->getUuid(),
				pool: [
					'routingStrategy' => $strategy,
					'routingFallback' => ($data['routingFallback'] ?? null),
				],
				actor: $actor
			);
		}

		return $task;
	}//end createTask()

	/**
	 * The task a node's resume slot points at, or null when it is gone.
	 *
	 * @param string $uuid The task uuid held in the slot.
	 *
	 * @return Task|null The task, or null when no row carries that uuid.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	public function taskOrNull(string $uuid): ?Task {
		try {
			return $this->tasks->get(uuid: $uuid);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end taskOrNull()

	/**
	 * What a terminal task tells the steps downstream.
	 *
	 * A fixed bag rather than the task row: the row is fifty columns of which
	 * a Switch needs six, and a delegated approval must be routable as
	 * "approved by the deputy under mandate X", which is a different fact from
	 * "approved by the manager". `decided` is what separates a person's
	 * decision from a task that merely ENDED (terminated, expired, waived):
	 * both are terminal, only one is an answer, and collapsing them would let
	 * an expired approval read as an approval (D-6).
	 *
	 * @param Task $task The terminal task.
	 *
	 * @return array<string, mixed> The outcome bag.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
	 */
	public static function outcomeBagFor(Task $task): array {
		$state = (string)$task->getState();
		$decided = ($state === Task::STATE_COMPLETED);
		$outcome = $task->getOutcome();
		if ($decided === false && ($outcome === null || trim($outcome) === '')) {
			$outcome = $state;
		}

		return [
			'taskUuid' => $task->getUuid(),
			'state' => $state,
			'decided' => $decided,
			'outcome' => $outcome,
			'rejected' => ($decided === true && TaskState::isRejectingOutcome(outcome: $outcome) === true),
			'comment' => $task->getComment(),
			'result' => $task->getResultText(),
			'completedBy' => $task->getCompletedBy(),
			'completedAt' => $task->getCompletedAt()?->format('c'),
			'performerType' => $task->getPerformerType(),
			'assignee' => $task->getAssignee(),
			'onBehalfOf' => $task->getOnBehalfOf(),
			'mandate' => $task->getMandate(),
		];
	}//end outcomeBagFor()

	/**
	 * A task the graph raised has ended: wake its run and spend the budget.
	 *
	 * Three steps, each of which may legitimately do nothing:
	 *
	 * 1. `signal()` with an empty payload parks the run as due. Null means the
	 *    run is not suspended: it is mid-walk (and will read the task itself)
	 *    or already terminal. Nothing more to do.
	 * 2. The node's stored budget decides whether to go on. Zero returns here,
	 *    which is exactly the behaviour `signal()` had before this existed.
	 * 3. `N` writes a per-walk ceiling onto the run context; `"all"` writes
	 *    none. Both then call the ONE advance path with `rethrow: true`, and
	 *    a throw is logged and swallowed: the task is committed and the run is
	 *    due, so the worker's next pass is the unoptimised fallback (D-5).
	 *
	 * @param Task $task The task as persisted in its terminal state.
	 *
	 * @return FlowRun|null The run after this call, or null when it was not suspended.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function continueRun(Task $task): ?FlowRun {
		$runUuid = trim((string)$task->getRunUuid());
		if ($runUuid === '') {
			return null;
		}

		try {
			$run = $this->runs->findByUuid(uuid: $runUuid);
		} catch (DoesNotExistException) {
			$this->logger->warning(
				message: '[FlowTaskBridge] Task ' . $task->getUuid() . ' names run ' . $runUuid . ', which no longer exists.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}

		$runService = $this->container->get(FlowRunService::class);
		$woken = $runService->signal(run: $run, payload: []);
		if ($woken === null) {
			return null;
		}

		$budget = $this->budgetFor(run: $woken, nodeId: (string)$task->getNodeId());
		if ($budget->advancesInRequest() === false) {
			return $woken;
		}

		if ($budget->isUnlimited() === false) {
			// The node's own re-entry is the first firing of the resumed walk
			// and is the completion LANDING, not the run being pushed. `N`
			// transitions past the node is therefore N + 1 firings.
			$context = ($woken->getContext() ?? []);
			$context[FlowEngine::CONTEXT_ADVANCE_BUDGET] = ((int)$budget->transitions() + 1);
			$woken->setContext($context);
			$woken = $this->runs->update($woken);
		}

		try {
			return $this->container->get(FlowRunAdvancer::class)->advance(run: $woken, rethrow: true);
		} catch (Throwable $failure) {
			// The budget is an optimisation. Its failure mode is the default:
			// the completion is committed, the run is due, the worker will
			// advance it. Nothing is lost except the latency saving.
			$this->logger->warning(
				message: '[FlowTaskBridge] In-request continuation of run ' . $runUuid
					. ' failed; the task stays completed and the worker takes over: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'task' => $task->getUuid()]
			);

			return $woken;
		}
	}//end continueRun()

	/**
	 * The budget the node stored when it created the task.
	 *
	 * Read from the node's OWN resume slot, which is the record of what that
	 * node was saved with at the moment it asked. A slot with no budget, or an
	 * unreadable one, is the default: parking for the worker is the safe
	 * direction, never running further than the author asked.
	 *
	 * @param FlowRun $run The suspended run.
	 * @param string $nodeId The node whose task ended.
	 *
	 * @return FlowAdvanceBudget The budget.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	private function budgetFor(FlowRun $run, string $nodeId): FlowAdvanceBudget {
		$slots = FlowResumeState::fromArray(($run->getContext() ?? [])[FlowResumeState::CONTEXT_KEY] ?? null);
		$slot = $slots->read(nodeId: $nodeId);
		if (array_key_exists(self::SLOT_ADVANCE, $slot) === false) {
			return FlowAdvanceBudget::none();
		}

		try {
			return FlowAdvanceBudget::fromValue(value: $slot[self::SLOT_ADVANCE]);
		} catch (Throwable $refused) {
			$this->logger->warning(
				message: '[FlowTaskBridge] Node ' . $nodeId . ' of run ' . $run->getUuid()
					. ' stored an unreadable advance budget; parking for the worker: ' . $refused->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return FlowAdvanceBudget::none();
		}
	}//end budgetFor()

	/**
	 * The definition version a run is pinned to, or null.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return integer|null The pinned version; null for a test run of a draft
	 *                      or a run that cannot be read.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function definitionVersionOf(string $runUuid): ?int {
		try {
			return $this->runs->findByUuid(uuid: $runUuid)->getFlowVersion();
		} catch (Throwable) {
			return null;
		}
	}//end definitionVersionOf()
}//end class
