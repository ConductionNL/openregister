<?php

/**
 * OpenRegister McpController services
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\McpCatalogueInterface;
use OCA\OpenRegister\Service\Authorization\GrantableRightsIndexInterface;

/**
 * The collaborators of McpController, wired by DI autowiring.
 *
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-1-discovery-catalog
 */
final class McpControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param McpCatalogueInterface         $mcpDiscoveryService  MCP discovery service
	 * @param GrantableRightsIndexInterface $grantableRightsIndex The cached menu of rights that may be offered
	 */
	public function __construct(
		public readonly McpCatalogueInterface $mcpDiscoveryService,
		public readonly GrantableRightsIndexInterface $grantableRightsIndex,
	) {
	}//end __construct()
}//end class
