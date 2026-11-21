<?php

/**
 * The steps that join, repeat, delegate and route items.
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

/**
 * The steps that join, repeat, delegate and route items.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class RoutingFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param ItemLoopFlowNodes $itemLoop The steps that join branches and loop over items within a run.
	 * @param HandOffFlowNodes  $handOff  The steps that hand items to another flow or route them down a path.
	 */
	public function __construct(
		private ItemLoopFlowNodes $itemLoop,
		private HandOffFlowNodes $handOff,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->itemLoop->nodes(),
			...$this->handOff->nodes(),
		];

	}//end nodes()
}//end class
