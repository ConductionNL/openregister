<?php

/**
 * One run of a repeat step: the invocation being repeated and the dispatcher
 * every pass runs its source and body through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;

/**
 * The state every pass of one repeat step shares.
 *
 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
 */
final class IterationRun {
	/**
	 * Describe the run.
	 *
	 * @param NodeInvocation     $step       The items that entered the loop, the step configuration and the run metadata.
	 * @param FlowStepDispatcher $dispatcher Runs the source and the body steps.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function __construct(
		public readonly NodeInvocation $step,
		public readonly FlowStepDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * The source step that produces each batch.
	 *
	 * @return array The source step.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function source(): array {
		return (array)$this->step->config['source'];
	}//end source()

	/**
	 * The chain of steps every batch runs through.
	 *
	 * @return array The body steps.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function body(): array {
		return (array)$this->step->config['body'];
	}//end body()
}//end class
