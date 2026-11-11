<?php

/**
 * OpenRegister AgentRunInstruction
 *
 * What a requested agent run is asked to do: the agent, skill, prompt and result field.
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
 * What a requested agent run is asked to do.
 *
 * @spec openspec/changes/archive/2026-10-05-flow-agent-action/tasks.md#task-1-1
 */
final class AgentRunInstruction {
	/**
	 * Constructor.
	 *
	 * @param string      $agent       The configured agent reference (UUID in v1).
	 * @param string|null $skill       Optional configured skill reference (slug).
	 * @param string      $prompt      The fully-rendered prompt.
	 * @param string      $resultField The object field the run's output is written to.
	 */
	public function __construct(
		public readonly string $agent,
		public readonly ?string $skill,
		public readonly string $prompt,
		public readonly string $resultField,
	) {
	}//end __construct()
}//end class
