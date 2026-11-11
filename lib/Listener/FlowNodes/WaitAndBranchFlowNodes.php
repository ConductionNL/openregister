<?php

/**
 * The steps that wait, branch or end a run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\FlowNodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\FlowNodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The steps that wait, branch or end a run.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class WaitAndBranchFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param WaitFlowNodes   $wait   The steps that pause a run until a moment or an answer.
	 * @param BranchFlowNodes $branch The steps that pick the path a run takes or end it.
	 */
	public function __construct(
		private WaitFlowNodes $wait,
		private BranchFlowNodes $branch,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->wait->nodes(),
			...$this->branch->nodes(),
		];

	}//end nodes()
}//end class
