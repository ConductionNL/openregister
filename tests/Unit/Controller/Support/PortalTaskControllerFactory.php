<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\PortalTaskController;

/**
 * Builds PortalTaskController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class PortalTaskControllerFactory {
	/**
	 * The constructor arguments of PortalTaskController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param PortalSubjectAssertion $assertion Constructor argument.
	 * @param PortalTaskService $portal Constructor argument.
	 * @param PortalTaskDeliveryService $delivery Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param ?IGroupManager $groupManager Constructor argument.
	 * @param ?LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Portal\PortalSubjectAssertion $assertion,
		\OCA\OpenRegister\Service\Portal\PortalTaskService $portal,
		\OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService $delivery,
		\OCP\IUserSession $userSession,
		?\OCP\IGroupManager $groupManager = null,
		?\Psr\Log\LoggerInterface $logger = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\PortalTaskControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\PortalTaskDomain(
					assertion: $assertion,
					portal: $portal,
					delivery: $delivery,
					userSession: $userSession,
				),
				platform: new \OCA\OpenRegister\Controller\Support\PortalTaskPlatform(
					groupManager: $groupManager,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build PortalTaskController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return PortalTaskController
	 */
	public static function create(mixed ...$args): PortalTaskController {
		return new PortalTaskController(...self::args(...$args));
	}
}
