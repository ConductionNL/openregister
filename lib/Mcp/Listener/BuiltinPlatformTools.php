<?php

/**
 * The built-in tools an agent manages applications and agents with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Mcp\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Mcp\Tool\AgentTool;
use OCA\OpenRegister\Mcp\Tool\ApplicationTool;

/**
 * A dependency bundle of {@see ToolRegistrationListener}.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class BuiltinPlatformTools {
	/**
	 * Bundle the services.
	 *
	 * @param ApplicationTool $applicationTool The application tool.
	 * @param AgentTool       $agentTool       The agent tool.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(
		public readonly ApplicationTool $applicationTool,
		public readonly AgentTool $agentTool,
	) {
	}//end __construct()
}//end class
