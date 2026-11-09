<?php

/**
 * OpenRegister mcp server controller services
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

/**
 * The service bundles of the mcp server API.
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */
final class McpServerControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param McpServerDomain   $domain   The domain bundle.
	 * @param McpServerPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md
	 */
	public function __construct(
		public readonly McpServerDomain $domain,
		public readonly McpServerPlatform $platform,
	) {
	}//end __construct()
}//end class
