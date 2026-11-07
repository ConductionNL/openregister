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
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNodeInterface;

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
	 * @param WaitNodeInterface $wait The built-in "Wait" node.
	 * @param AwaitSignalNodeInterface $awaitSignal The built-in "Wait for an answer" node.
	 * @param SwitchNode $switch The built-in "Switch" node.
	 * @param EndNode $end The built-in "End" node.
	 */
	public function __construct(
		private WaitNodeInterface $wait,
		private AwaitSignalNodeInterface $awaitSignal,
		private SwitchNode $switch,
		private EndNode $end,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->wait,
			$this->awaitSignal,
			$this->switch,
			$this->end,
		];

	}//end nodes()
}//end class
