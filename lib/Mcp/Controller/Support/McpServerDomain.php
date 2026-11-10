<?php

/**
 * OpenRegister mcp server domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Controller\Support;

use OCA\OpenRegister\Service\Mcp\McpAgentScope;
use OCA\OpenRegister\Mcp\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Mcp\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;

/**
 * The domain services of the mcp server API.
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */
final class McpServerDomain {
	/**
	 * Bundle the services.
	 *
	 * @param McpProtocolService  $protocolService  MCP protocol service
	 * @param McpToolsService     $toolsService     MCP tools service
	 * @param McpResourcesService $resourcesService MCP resources service
	 * @param McpAgentScope       $agentScope       Holds a session that declared an agent to its grant
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md
	 */
	public function __construct(
		public readonly McpProtocolService $protocolService,
		public readonly McpToolsService $toolsService,
		public readonly McpResourcesService $resourcesService,
		public readonly McpAgentScope $agentScope,
	) {
	}//end __construct()
}//end class
