<?php

/**
 * The built-ins that shape, branch, route and persist what a run carries.
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
 * The built-ins that shape, branch, route and persist what a run carries.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class CoreFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param DataShapingFlowNodes $dataShaping The steps that build and narrow items.
	 * @param WaitAndBranchFlowNodes $waitAndBranch The steps that wait, branch or end a run.
	 * @param RoutingFlowNodes $routing The steps that join, repeat, delegate and route.
	 * @param ObjectFlowNodes $objects The steps that work on objects.
	 */
	public function __construct(
		private DataShapingFlowNodes $dataShaping,
		private WaitAndBranchFlowNodes $waitAndBranch,
		private RoutingFlowNodes $routing,
		private ObjectFlowNodes $objects,
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
			...$this->routing->nodes(),
			...$this->objects->nodes(),
		];

	}//end nodes()
}//end class
