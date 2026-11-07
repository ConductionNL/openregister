<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Mcp\Controller\McpServerController;

/**
 * Builds McpServerController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class McpServerControllerFactory {
	/**
	 * The constructor arguments of McpServerController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param McpProtocolService $protocolService Constructor argument.
	 * @param McpToolsService $toolsService Constructor argument.
	 * @param McpResourcesService $resourcesService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param McpAgentScope $agentScope Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Mcp\Service\Mcp\McpProtocolService $protocolService,
		\OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService $toolsService,
		\OCA\OpenRegister\Mcp\Service\Mcp\McpResourcesService $resourcesService,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\Mcp\McpAgentScope $agentScope,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Mcp\Controller\Support\McpServerControllerServices(
				domain: new \OCA\OpenRegister\Mcp\Controller\Support\McpServerDomain(
					protocolService: $protocolService,
					toolsService: $toolsService,
					resourcesService: $resourcesService,
					agentScope: $agentScope,
				),
				platform: new \OCA\OpenRegister\Mcp\Controller\Support\McpServerPlatform(
					logger: $logger,
					userSession: $userSession,
				),
			),
		];
	}

	/**
	 * Build McpServerController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return McpServerController
	 */
	public static function create(mixed ...$args): McpServerController {
		return new McpServerController(...self::args(...$args));
	}
}
