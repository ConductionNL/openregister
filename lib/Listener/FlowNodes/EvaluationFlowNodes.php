<?php

/**
 * The steps that repeat until done or evaluate a decision table, deciding and moving on in one firing.
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
use OCA\OpenRegister\Service\Flow\Nodes\IterateNodeInterface;

/**
 * The steps that repeat until done or evaluate a decision table, deciding and moving on in one firing.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class EvaluationFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param IterateNodeInterface       $iterate       The built-in "Repeat until done" node.
	 * @param DecisionTableNodeInterface $decisionTable The built-in "Evaluate a decision table" node.
	 */
	public function __construct(
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
			$this->iterate,
			$this->decisionTable,
		];

	}//end nodes()
}//end class
