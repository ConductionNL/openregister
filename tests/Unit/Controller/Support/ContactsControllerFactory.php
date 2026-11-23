<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ContactsController;

/**
 * Builds ContactsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ContactsControllerFactory {
	/**
	 * The constructor arguments of ContactsController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ContactService $contactService Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param ContactMatcherInterface $matchingService Constructor argument.
	 * @param DeepLinkRegistryService $deepLinkRegistry Constructor argument.
	 * @param IL10N $l10n Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param PersonLinkService $personLinks Constructor argument.
	 * @param PermissionHandler $permissions Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ContactService $contactService,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface $matchingService,
		\OCA\OpenRegister\Service\DeepLinkRegistryService $deepLinkRegistry,
		\OCP\IL10N $l10n,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\PersonLinkService $personLinks,
		\OCA\OpenRegister\Service\Object\PermissionHandler $permissions,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ContactsControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\ContactsDomain(
					contactService: $contactService,
					objectService: $objectService,
					matchingService: $matchingService,
					deepLinkRegistry: $deepLinkRegistry,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ContactsPlatform(
					personLinks: $personLinks,
					l10n: $l10n,
					logger: $logger,
					permissions: $permissions,
				),
			),
		];
	}

	/**
	 * Build ContactsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ContactsController
	 */
	public static function create(mixed ...$args): ContactsController {
		return new ContactsController(...self::args(...$args));
	}
}
