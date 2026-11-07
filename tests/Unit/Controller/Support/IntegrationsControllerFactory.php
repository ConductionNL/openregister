<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\IntegrationsController;

/**
 * Builds IntegrationsController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class IntegrationsControllerFactory {
	/**
	 * The constructor arguments of IntegrationsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IntegrationCatalogueInterface $registry Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface $registry,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\IntegrationsControllerServices(
				registry: $registry,
				userSession: $userSession,
				groupManager: $groupManager,
				logger: $logger,
			),
		];
	}//end args()

	/**
	 * Build IntegrationsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return IntegrationsController
	 */
	public static function create(mixed ...$args): IntegrationsController {
		return new IntegrationsController(...self::args(...$args));
	}//end create()
}//end class
