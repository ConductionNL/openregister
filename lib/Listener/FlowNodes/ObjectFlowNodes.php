<?php

/**
 * The steps that write, transition, link and read objects.
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
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNodeInterface;

/**
 * The steps that write, transition, link and read objects.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class ObjectFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param ObjectWriteNodeInterface $objectWrite The built-in "Write an object" node.
	 * @param TransitionNodeInterface $transition The built-in "Lifecycle transition" node.
	 * @param LinkObjectNodeInterface $linkObject The built-in "Create or link an object" node.
	 * @param ObjectReadNodeInterface $objectRead The built-in "Read objects" node.
	 */
	public function __construct(
		private ObjectWriteNodeInterface $objectWrite,
		private TransitionNodeInterface $transition,
		private LinkObjectNodeInterface $linkObject,
		private ObjectReadNodeInterface $objectRead,
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
			$this->linkObject,
			$this->objectRead,
		];

	}//end nodes()
}//end class
