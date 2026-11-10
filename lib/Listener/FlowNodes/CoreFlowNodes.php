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
	 * @param ItemHandlingFlowNodes $itemHandling The steps that shape the items of a run and decide whether it waits, branches or ends.
	 * @param DestinationFlowNodes  $destination  The steps that send items on, to a path, a flow or an object.
	 */
	public function __construct(
		private ItemHandlingFlowNodes $itemHandling,
		private DestinationFlowNodes $destination,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->itemHandling->nodes(),
			...$this->destination->nodes(),
		];

	}//end nodes()
}//end class
