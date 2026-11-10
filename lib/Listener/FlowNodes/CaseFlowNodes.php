<?php

/**
 * The steps where the flow engine touches the case layer.
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

use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNodeInterface;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNodeInterface;
use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The steps where the flow engine touches the case layer.
 *
 * One-engine-bpmn-and-cmmn: a process opens a case plan on its object, or
 * moves one plan item.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class CaseFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param CaseOpenNodeInterface $caseOpen The built-in "Open a case" node.
	 * @param CaseAdvanceNodeInterface $caseAdvance The built-in "Advance a case" node.
	 */
	public function __construct(
		private CaseOpenNodeInterface $caseOpen,
		private CaseAdvanceNodeInterface $caseAdvance,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->caseOpen,
			$this->caseAdvance,
		];

	}//end nodes()
}//end class
