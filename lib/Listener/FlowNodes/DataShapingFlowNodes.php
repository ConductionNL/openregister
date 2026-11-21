<?php

/**
 * The steps that build and narrow the items a run carries.
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
 * The steps that build and narrow the items a run carries.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class DataShapingFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param FieldFlowNodes    $fields   The steps that write fields onto the items a run carries.
	 * @param ItemListFlowNodes $itemList The steps that split and narrow the list of items a run carries.
	 */
	public function __construct(
		private FieldFlowNodes $fields,
		private ItemListFlowNodes $itemList,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->fields->nodes(),
			...$this->itemList->nodes(),
		];

	}//end nodes()
}//end class
