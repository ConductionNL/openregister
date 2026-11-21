<?php

/**
 * The steps that keep run state and map data from one shape to another.
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
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\MapNodeInterface;

/**
 * The steps that keep run state and map data from one shape to another.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class DataMappingFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param FlowStateNodeInterface $flowState The built-in "Flow state" node.
	 * @param MapNodeInterface       $map       The built-in "Map" node.
	 */
	public function __construct(
		private FlowStateNodeInterface $flowState,
		private MapNodeInterface $map,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->flowState,
			$this->map,
		];

	}//end nodes()
}//end class
