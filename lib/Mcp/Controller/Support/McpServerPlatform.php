<?php

/**
 * OpenRegister mcp server platform
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

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the mcp server API.
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */
final class McpServerPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface $logger      Logger
	 * @param IUserSession    $userSession Read per request, never at construction (#4284)
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
