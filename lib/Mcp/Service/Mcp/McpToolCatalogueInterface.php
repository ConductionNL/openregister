<?php

/**
 * McpToolCatalogueInterface: the MCP tool catalogue, as callers outside the Mcp component read it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Service\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Mcp;

/**
 * The aggregated tool descriptors of every registered MCP tool provider.
 *
 * Implemented by McpToolsService; the agent scope reads it to narrow the
 * catalogue to an agent's grant.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
interface McpToolCatalogueInterface {
	/**
	 * List available MCP tools.
	 *
	 * @return array{tools: array} MCP tools/list response
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function listTools(): array;
}//end interface
