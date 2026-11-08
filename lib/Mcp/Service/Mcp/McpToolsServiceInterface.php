<?php

/**
 * McpToolsServiceInterface: the MCP tool catalogue the agent scope reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Mcp;

/**
 * The methods of McpToolsService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface McpToolsServiceInterface {

	/**
	 * List available MCP tools
	 *
	 * Aggregates tool descriptors from all registered providers. Descriptors
	 * whose id does not start with `{provider->getAppId()}.` are dropped and
	 * a warning is logged per D5 of the design.
	 *
	 * @return array{tools: array} MCP tools/list response
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
	 */
	public function listTools(): array;
}//end interface
