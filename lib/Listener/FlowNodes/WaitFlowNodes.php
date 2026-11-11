<?php

/**
 * The steps that pause a run until a moment or an answer.
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
use OCA\OpenRegister\Service\Flow\Nodes\WaitNodeInterface;

/**
 * The steps that pause a run until a moment or an answer.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class WaitFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param WaitNodeInterface        $wait        The built-in "Wait" node.
	 * @param AwaitSignalNodeInterface $awaitSignal The built-in "Wait for an answer" node.
	 */
	public function __construct(
		private WaitNodeInterface $wait,
		private AwaitSignalNodeInterface $awaitSignal,
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
		];

	}//end nodes()
}//end class
