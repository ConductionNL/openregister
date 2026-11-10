<?php

/**
 * The steps that write objects and move them through their lifecycle.
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
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNodeInterface;

/**
 * The steps that write objects and move them through their lifecycle.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class ObjectChangeFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param ObjectWriteNodeInterface $objectWrite The built-in "Write an object" node.
	 * @param TransitionNodeInterface  $transition  The built-in "Lifecycle transition" node.
	 */
	public function __construct(
		private ObjectWriteNodeInterface $objectWrite,
		private TransitionNodeInterface $transition,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->objectWrite,
			$this->transition,
		];

	}//end nodes()
}//end class
