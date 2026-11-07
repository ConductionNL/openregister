<?php

/**
 * One firing of FlowEngine's single-stream walk, and the run results a stop
 * or a suspension ends it in.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A hop of the single-stream walk.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */
final class FlowWalkHop extends FlowHop {

	/**
	 * Constructor.
	 *
	 * @param FlowWalkPass $pass The pass the hop belongs to.
	 * @param FlowHopPlan  $plan The transition, its step and its items.
	 */
	public function __construct(
		public readonly FlowWalkPass $pass,
		FlowHopPlan $plan,
	) {
		parent::__construct(owner: $pass, plan: $plan);
	}//end __construct()

	/**
	 * Log a stop and build the run result it ends in. An error stop fails the
	 * run with the stop's message; any other stop ends it as stopped.
	 *
	 * @param FlowStop $stop The stop, from a Stop step or an oversight check.
	 *
	 * @return array The terminal run result.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function stopped(FlowStop $stop): array {
		$this->pass->log[] = $this->stoppedEntry(stop: $stop);

		return $this->pass->result(status: $stop->runStatus()) + ['error' => $stop->runError()];
	}//end stopped()

	/**
	 * Log a suspension and build the run result it ends in. The marking is not
	 * advanced and the result carries this hop's input items, so the run
	 * resumes on this transition.
	 *
	 * @param FlowSuspension $suspension The suspension.
	 *
	 * @return array The suspended run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function suspended(FlowSuspension $suspension): array {
		$this->pass->log[] = $this->suspendedEntry(suspension: $suspension);

		return [
			'status' => FlowEngine::STATUS_SUSPENDED,
			'log' => $this->pass->log,
			'context' => $this->pass->context,
			'items' => $this->itemsIn,
			'resumeAt' => $suspension->getResumeAt(),
		];
	}//end suspended()

}//end class
