<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\RegistrySubscriptionController;

/**
 * Builds RegistrySubscriptionController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class RegistrySubscriptionControllerFactory {
	/**
	 * The constructor arguments of RegistrySubscriptionController.
	 *
	 * @param string $appName Unused; kept so existing calls still fit.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param PermissionHandler $permissionHandler Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param RegistrySubscriptionService $subscriptions Constructor argument.
	 * @param ?LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Service\Object\PermissionHandler $permissionHandler,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\Registry\RegistrySubscriptionService $subscriptions,
		?\Psr\Log\LoggerInterface $logger = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\RegistrySubscriptionControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\RegistrySubscriptionDomain(
					schemaMapper: $schemaMapper,
					objectService: $objectService,
					permissionHandler: $permissionHandler,
					subscriptions: $subscriptions,
				),
				platform: new \OCA\OpenRegister\Controller\Support\RegistrySubscriptionPlatform(
					userSession: $userSession,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build RegistrySubscriptionController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return RegistrySubscriptionController
	 */
	public static function create(mixed ...$args): RegistrySubscriptionController {
		return new RegistrySubscriptionController(...self::args(...$args));
	}
}
