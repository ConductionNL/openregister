<?php

/**
 * A leaf app whose attributed MCP tools are being discovered, with its own container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One installed app during attributed-tool discovery: its id, its OWN DI
 * container (where it registers its scannable services, #390) and the log
 * the discovery reports to.
 *
 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
 */
final class McpLeafApp {

	/**
	 * Describe the app.
	 *
	 * @param string             $appId     The app id.
	 * @param ContainerInterface $container The app's own DI container.
	 * @param LoggerInterface    $logger    PSR logger.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
	 */
	public function __construct(
		public readonly string $appId,
		public readonly ContainerInterface $container,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
