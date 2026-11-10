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
	 * @param ObjectChangeFlowNodes $objectChange The steps that write objects and move them through their lifecycle.
	 * @param ObjectLookupFlowNodes $objectLookup The steps that find, create-or-link and read objects.
	 */
	public function __construct(
		private ObjectChangeFlowNodes $objectChange,
		private ObjectLookupFlowNodes $objectLookup,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->objectChange->nodes(),
			...$this->objectLookup->nodes(),
		];

	}//end nodes()
}//end class
