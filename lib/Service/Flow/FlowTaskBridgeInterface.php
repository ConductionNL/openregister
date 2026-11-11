<?php

/**
 * FlowTaskBridgeInterface: the flow/task bridge as the user-task terminal listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\Task;

/**
 * The flow/task bridge, typed for callers outside the Service component.
 *
 * Its only outside caller, the user-task terminal listener, calls continueRun(),
 * so that is the whole contract. Binding it in ContractBindings lets that caller
 * resolve the bridge without naming the class.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
 */
interface FlowTaskBridgeInterface {
	/**
	 * Wake the run a terminal task was parked on and, per the node's budget, advance it.
	 *
	 * @param Task $task The task as persisted in its terminal state.
	 *
	 * @return FlowRun|null The run after this call, or null when it was not suspended.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function continueRun(Task $task): ?FlowRun;
}//end interface
