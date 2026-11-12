<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\AgentsController;

/**
 * Builds AgentsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class AgentsControllerFactory {
	/**
	 * The constructor arguments of AgentsController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param AgentMapper $agentMapper Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param ToolRegistry $toolRegistry Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ?string $userId Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Mcp\Db\AgentMapper $agentMapper,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Mcp\Service\ToolRegistry $toolRegistry,
		\Psr\Log\LoggerInterface $logger,
		?string $userId,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\AgentsControllerServices(
				agentMapper: $agentMapper,
				organisationService: $organisationService,
				toolRegistry: $toolRegistry,
				logger: $logger,
			),
			$userId,
		];
	}

	/**
	 * Build AgentsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return AgentsController
	 */
	public static function create(mixed ...$args): AgentsController {
		return new AgentsController(...self::args(...$args));
	}
}
