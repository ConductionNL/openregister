<?php

/**
 * The built-ins at the edges of a flow: the case layer and the entry points.
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
 * The built-ins at the edges of a flow: the case layer and the entry points.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class BoundaryFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param CaseFlowNodes $cases The steps that touch the case layer.
	 * @param TriggerFlowNodes $triggers The entry points.
	 */
	public function __construct(
		private CaseFlowNodes $cases,
		private TriggerFlowNodes $triggers,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->cases->nodes(),
			...$this->triggers->nodes(),
		];

	}//end nodes()
}//end class
