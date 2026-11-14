<?php

/**
 * The steps that shape the items of a run and decide whether it waits, branches or ends.
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
 * @spec openspec/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\FlowNodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The steps that shape the items of a run and decide whether it waits, branches or ends.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class ItemHandlingFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param DataShapingFlowNodes   $dataShaping   The steps that build and narrow items.
	 * @param WaitAndBranchFlowNodes $waitAndBranch The steps that wait, branch or end a run.
	 */
	public function __construct(
		private DataShapingFlowNodes $dataShaping,
		private WaitAndBranchFlowNodes $waitAndBranch,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->dataShaping->nodes(),
			...$this->waitAndBranch->nodes(),
		];

	}//end nodes()
}//end class
