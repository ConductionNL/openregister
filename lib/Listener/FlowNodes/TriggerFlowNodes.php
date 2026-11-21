<?php

/**
 * The entry points of a flow.
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
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNodeInterface;

/**
 * The entry points of a flow.
 *
 * Registered like any other node so the palette can offer them and the
 * preflight can check their config — a trigger is where a run BEGINS, not work
 * it performs, and each `execute()` is a pass-through.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class TriggerFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param TriggerObjectNodeInterface $triggerObject The "When an object changes" entry point.
	 * @param TriggerScheduleNodeInterface $triggerSchedule The "On a schedule" entry point.
	 * @param TriggerManualNodeInterface $triggerManual The "When someone runs it" entry point.
	 */
	public function __construct(
		private TriggerObjectNodeInterface $triggerObject,
		private TriggerScheduleNodeInterface $triggerSchedule,
		private TriggerManualNodeInterface $triggerManual,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->triggerObject,
			$this->triggerSchedule,
			$this->triggerManual,
		];

	}//end nodes()
}//end class
