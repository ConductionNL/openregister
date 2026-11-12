<?php

/**
 * OpenRegister AgentRunDispatch
 *
 * How a requested agent run is dispatched: the approval gate, the mode and the owning flow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * How a requested agent run is dispatched.
 *
 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
 */
final class AgentRunDispatch {
	/**
	 * Constructor.
	 *
	 * @param bool   $requiresApproval Whether the run must pass a human-approval gate before executing.
	 * @param string $mode             Dispatch mode, `"async"` only in v1.
	 * @param string $flowName         The owning flow's name (diagnostics/audit).
	 */
	public function __construct(
		public readonly bool $requiresApproval,
		public readonly string $mode,
		public readonly string $flowName,
	) {
	}//end __construct()
}//end class
