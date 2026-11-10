<?php

/**
 * MCP Session Caller
 *
 * An MCP session and the signed-in user speaking through it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

/**
 * An MCP session and the signed-in user speaking through it: what the agent
 * scope reads the session's bound agent for.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
 */
final class McpSessionCaller {
	/**
	 * Describe the caller.
	 *
	 * @param string $sessionId The validated MCP session
	 * @param string $userId    The signed-in user
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
	 */
	public function __construct(
		public readonly string $sessionId,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
