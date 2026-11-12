<?php

/**
 * The steps that send items on, to a path, a flow or an object.
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
 * The steps that send items on, to a path, a flow or an object.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class DestinationFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param RoutingFlowNodes $routing The steps that join, repeat, delegate and route.
	 * @param ObjectFlowNodes  $objects The steps that work on objects.
	 */
	public function __construct(
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
			...$this->routing->nodes(),
			...$this->objects->nodes(),
		];

	}//end nodes()
}//end class
