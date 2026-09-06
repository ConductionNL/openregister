<?php

/**
 * Terminates the task of a user-task node whose place a branch decision cleared.
 *
 * The Petri net does not raise an event saying "this place will never be
 * marked". What happens instead is concrete and observable: a routing node
 * fires, {@see FlowTokenRouter::keepOnlyTakenExits()} withdraws the tokens
 * from the exits it did not take, and a node standing on one of those places
 * will not fire from it. If that node had already asked a person for
 * something (its resume slot holds a task uuid), the answer can no longer be
 * used, and a task nobody can use must not sit in an inbox as actionable
 * work: somebody WILL do it (design D-7).
 *
 * So the engine reports the pruned places here after every pruning, and this
 * class does the one thing it knows how to do: find the user-task slots on
 * those places, terminate their tasks with a reason naming the branch, and
 * clear the slot so a later re-entry (a loop) asks afresh instead of reading
 * a terminated task as an answer.
 *
 * The task service is resolved lazily through the container, as
 * {@see FlowRunVersionPin} does, so the engine remains constructible in a
 * unit test without the task layer, and a task-layer failure can never make a
 * hop fail: propagation errors are logged, and the run-terminality propagation
 * ({@see \OCA\OpenRegister\Listener\TaskRunTerminalListener}) is the backstop
 * for whatever this misses.
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
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Service\Task\TaskService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Branch-mootness propagation onto user tasks.
 */
class FlowTaskMootness {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Lazily resolves the task service.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * A routing decision withdrew tokens from these places.
	 *
	 * Reads the run's resume state out of the context (it travels as an
	 * object, so the forget below is visible to the walk that continues) and
	 * terminates the task of every user-task node whose input place, or one
	 * of whose join places, was just cleared.
	 *
	 * @param array<string, mixed> $context The run context, carrying the resume state.
	 * @param array<int, string> $places The place names the pruning cleared.
	 * @param string $byTransition The transition whose exit decision did it.
	 *
	 * @return int How many tasks were terminated.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
	 */
	public function placesPruned(array $context, array $places, string $byTransition): int {
		$state = ($context[FlowResumeState::CONTEXT_KEY] ?? null);
		if ($state instanceof FlowResumeState === false || $places === []) {
			return 0;
		}

		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		$terminated = 0;

		foreach ($state->all() as $nodeId => $slot) {
			$taskUuid = trim((string)($slot[FlowTaskBridge::SLOT_TASK_UUID] ?? ''));
			if ($taskUuid === '' || $this->standsOn(nodeId: $nodeId, places: $places) === false) {
				continue;
			}

			if ($this->terminate(taskUuid: $taskUuid, nodeId: $nodeId, runUuid: $runUuid, byTransition: $byTransition) === true) {
				$state->forget(nodeId: $nodeId);
				$terminated++;
			}
		}

		return $terminated;
	}//end placesPruned()

	/**
	 * Whether a node reads from any of the cleared places.
	 *
	 * A node's shared input place IS its id ({@see FlowGraph::inPlace()}); a
	 * join's per-edge places are the id followed by the join marker.
	 *
	 * @param string $nodeId The node.
	 * @param array<int, string> $places The cleared places.
	 *
	 * @return boolean True when one of them feeds this node.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
	 */
	private function standsOn(string $nodeId, array $places): bool {
		$joinPrefix = $nodeId . FlowGraph::PLACE_JOIN;
		foreach ($places as $place) {
			$place = (string)$place;
			if ($place === $nodeId || str_starts_with($place, $joinPrefix) === true) {
				return true;
			}
		}

		return false;
	}//end standsOn()

	/**
	 * Terminate one task as moot, never letting the hop fail over it.
	 *
	 * Refuses to touch a task that carries no run uuid, or one belonging to a
	 * different run than the one walking: a slot can only ever have been
	 * written by this run, so either mismatch means the slot is stale and the
	 * task is somebody else's.
	 *
	 * @param string $taskUuid The task the slot points at.
	 * @param string $nodeId The node that raised it.
	 * @param string $runUuid The run walking, when known.
	 * @param string $byTransition The transition whose decision made it moot.
	 *
	 * @return boolean True when the task is now terminal (or already was).
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
	 */
	private function terminate(string $taskUuid, string $nodeId, string $runUuid, string $byTransition): bool {
		try {
			$tasks = $this->container->get(TaskService::class);
			$task = $tasks->get(uuid: $taskUuid);

			$taskRun = trim((string)$task->getRunUuid());
			if ($taskRun === '' || ($runUuid !== '' && $taskRun !== $runUuid)) {
				return false;
			}

			$tasks->terminateAsMoot(
				uuid: $taskUuid,
				reason: sprintf(
					"Branch decision at '%s' in run '%s' made node '%s' unreachable; its task can no longer be used.",
					$byTransition,
					$taskRun,
					$nodeId
				),
				source: FlowTaskBridge::ACTOR_PREFIX . $taskRun
			);

			return true;
		} catch (Throwable $failure) {
			$this->logger->error(
				message: '[FlowTaskMootness] Could not terminate task ' . $taskUuid . ' of node ' . $nodeId
					. ' after branch pruning; run-terminality propagation remains the backstop: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $runUuid]
			);

			return false;
		}//end try
	}//end terminate()
}//end class
