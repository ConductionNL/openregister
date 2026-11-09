<?php

/**
 * The steps that reshape run data, the rule step among them.
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
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\MapNodeInterface;

/**
 * The steps that reshape run data, the rule step among them.
 *
 * The rule step (flow-decision-tables) is registered with the data
 * reshapers rather than the waiters, deliberately: it decides and moves on in
 * one firing. A decision that needs a person is the user task, and the split
 * is the fleet's directive - rule evaluation is the engine's, human decisions
 * are decidiq's.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class ReshapingFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param FlowStateNodeInterface $flowState The built-in "Flow state" node.
	 * @param MapNodeInterface $map The built-in "Map" node.
	 * @param IterateNodeInterface $iterate The built-in "Repeat until done" node.
	 * @param DecisionTableNodeInterface $decisionTable The built-in "Evaluate a decision table" node.
	 */
	public function __construct(
		private FlowStateNodeInterface $flowState,
		private MapNodeInterface $map,
		private IterateNodeInterface $iterate,
		private DecisionTableNodeInterface $decisionTable,
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
			$this->iterate,
			$this->decisionTable,
		];

	}//end nodes()
}//end class
