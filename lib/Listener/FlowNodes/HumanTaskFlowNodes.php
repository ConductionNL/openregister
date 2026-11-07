<?php

/**
 * The steps that wait for a person.
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
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNodeInterface;

/**
 * The steps that wait for a person.
 *
 * The human step (flow-user-task-node) is registered beside await-signal
 * deliberately: the two are a pair, and their palette descriptions state which
 * is for a system that calls back and which is for a performer who has to be
 * found, told, and allowed to say no.
 *
 * The third waiter (flow-portal-task): a party OUTSIDE the instance, matched
 * from the case and reached through the portal seam. The three palette
 * descriptions are written as a set: signal for a system that calls back, user
 * task for a performer in the organisation, portal task for a party outside it.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class HumanTaskFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param UserTaskNodeInterface $userTask The built-in "Ask a person" node.
	 * @param PortalTaskNodeInterface $portalTask The built-in "Ask a party outside the organisation" node.
	 */
	public function __construct(
		private UserTaskNodeInterface $userTask,
		private PortalTaskNodeInterface $portalTask,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->userTask,
			$this->portalTask,
		];

	}//end nodes()
}//end class
