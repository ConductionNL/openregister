<?php

/**
 * The built-ins that reshape data, message, ask people and lock objects.
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
 * The built-ins that reshape data, message, ask people and lock objects.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class ActionFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param OutputFlowNodes       $output       The steps that reshape data and send messages.
	 * @param CoordinationFlowNodes $coordination The steps that wait for a person or hold a lock on an object.
	 */
	public function __construct(
		private OutputFlowNodes $output,
		private CoordinationFlowNodes $coordination,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->output->nodes(),
			...$this->coordination->nodes(),
		];

	}//end nodes()
}//end class
