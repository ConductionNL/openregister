<?php

/**
 * The steps that write fields onto the items a run carries.
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
use OCA\OpenRegister\Service\Flow\Nodes\ActionInputsNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNodeInterface;

/**
 * The steps that write fields onto the items a run carries.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class FieldFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param SetFieldsNodeInterface    $setFields    The built-in "Edit fields" node.
	 * @param ActionInputsNodeInterface $actionInputs The built-in step that makes an action's inputs readable.
	 */
	public function __construct(
		private SetFieldsNodeInterface $setFields,
		private ActionInputsNodeInterface $actionInputs,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->setFields,
			$this->actionInputs,
		];

	}//end nodes()
}//end class
