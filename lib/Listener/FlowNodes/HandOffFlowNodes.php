<?php

/**
 * The steps that hand items to another flow or route them down a path.
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
use OCA\OpenRegister\Service\Flow\Nodes\RouterNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNodeInterface;

/**
 * The steps that hand items to another flow or route them down a path.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class HandOffFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param SubFlowNodeInterface $subFlow The built-in "Run a flow" node.
	 * @param RouterNodeInterface  $router  The built-in "Route items" node.
	 */
	public function __construct(
		private SubFlowNodeInterface $subFlow,
		private RouterNodeInterface $router,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->subFlow,
			$this->router,
		];

	}//end nodes()
}//end class
