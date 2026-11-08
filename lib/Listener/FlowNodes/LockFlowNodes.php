<?php

/**
 * The mutual-exclusion steps.
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
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNodeInterface;

/**
 * The mutual-exclusion steps.
 *
 * Both are ordinary STEPS: a lock step is work the run performs, and the
 * release that matters is the engine's terminal hook rather than the unlock
 * node, so neither is an end node.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class LockFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param LockObjectNode $lockObject The built-in "Lock an object" node.
	 * @param UnlockObjectNodeInterface $unlockObject The built-in "Unlock an object" node.
	 */
	public function __construct(
		private LockObjectNode $lockObject,
		private UnlockObjectNodeInterface $unlockObject,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->lockObject,
			$this->unlockObject,
		];

	}//end nodes()
}//end class
