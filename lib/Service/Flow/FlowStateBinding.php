<?php

/**
 * Binds a flow's carried state to the run that reads and writes it.
 *
 * Flow state is the token's long-lived sibling: the token belongs to THIS run
 * and dies with it, this belongs to the FLOW and outlives every run of it. That
 * difference is why it lives in its own table and its own class — a run
 * executor that also owned this would be handling two lifetimes at once, and
 * the bug that produces is a per-run COPY of flow-level state, where a resumed
 * run restores a stale snapshot over whatever later runs had written.
 *
 * Loaded from its own table rather than from the run's context, because a
 * scheduled flow's next tick is a different run and would otherwise start blank
 * — the gap OR#2216 exists to close.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStateMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads a flow's state into a run's context, and writes back what changed.
 */
class FlowStateBinding {

	/**
	 * Constructor.
	 *
	 * @param FlowStateMapper $stateMapper Persists state that outlives a run.
	 * @param LoggerInterface $logger Records a state write that could not be saved.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-must-be-able-to-resume-from-where-it-stopped
	 */
	public function __construct(
		private readonly FlowStateMapper $stateMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Put the flow's carried state into the node context.
	 *
	 * Handed over as an object for the same reason as the token: nodes take
	 * $context by value, so only a handle gives them write access.
	 *
	 * @param FlowRun $run The run being executed.
	 * @param array $context The node context, modified in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function attach(FlowRun $run, array &$context): void {
		if (trim((string)$run->getFlowId()) === '') {
			return;
		}

		$stored = $this->stateMapper->findByFlow(flowId: (string)$run->getFlowId());
		$context[FlowStateHandle::CONTEXT_KEY] = new FlowStateHandle(values: ($stored?->getState() ?? []));

	}//end attach()

	/**
	 * Write a flow's state back to its own table, when a node changed it.
	 *
	 * Only when a node actually changed something: a flow that merely READS its
	 * state should not touch the row on every tick, and a five-minute schedule
	 * makes that difference thousands of writes a week.
	 *
	 * @param FlowRun $run The run that just finished a pass.
	 * @param array $context The context the engine returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function persist(FlowRun $run, array $context): void {
		$handle = ($context[FlowStateHandle::CONTEXT_KEY] ?? null);
		if (($handle instanceof FlowStateHandle) === false || $handle->isDirty() === false) {
			return;
		}

		$flowId = trim((string)$run->getFlowId());
		if ($flowId === '') {
			return;
		}

		try {
			$this->stateMapper->put(flowId: $flowId, state: $handle->all());
		} catch (Throwable $e) {
			// Deliberately non-fatal, and deliberately LOUD. A run that did its
			// work should not be recorded as failed because its bookkeeping
			// could not be saved — but a silently dropped state write would let
			// a flow repeat work forever while looking healthy, so this must be
			// visible rather than swallowed.
			$this->logger->error(
				message: '[FlowStateBinding] Could not persist flow state',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'flow' => $flowId,
					'run' => $run->getUuid(),
					'error' => $e->getMessage(),
				]
			);
		}//end try

	}//end persist()
}//end class
