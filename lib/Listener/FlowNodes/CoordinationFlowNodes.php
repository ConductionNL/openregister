<?php

/**
 * The steps that wait for a person or hold a lock on an object.
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
 * The steps that wait for a person or hold a lock on an object.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class CoordinationFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param HumanTaskFlowNodes $humanTasks The steps that wait for a person.
	 * @param LockFlowNodes      $locks      The mutual-exclusion steps.
	 */
	public function __construct(
		private HumanTaskFlowNodes $humanTasks,
		private LockFlowNodes $locks,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			...$this->humanTasks->nodes(),
			...$this->locks->nodes(),
		];

	}//end nodes()
}//end class
