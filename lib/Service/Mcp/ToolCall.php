<?php

/**
 * MCP Tool Call
 *
 * A tools/call request: the tool as the client named it and its arguments.
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
 * A tools/call request: the tool as the client named it (id or short name)
 * and the arguments it passes.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
 */
final class ToolCall {
	/**
	 * Describe the call.
	 *
	 * @param string               $name      The tool as the client named it (id or short name)
	 * @param array<string, mixed> $arguments The call's arguments
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $arguments,
	) {
	}//end __construct()
}//end class
