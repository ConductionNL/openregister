<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\PartyController;

/**
 * Builds PartyController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class PartyControllerFactory {
	/**
	 * The constructor arguments of PartyController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objects Constructor argument.
	 * @param PartyRoleService $roles Constructor argument.
	 * @param PartyService $parties Constructor argument.
	 * @param PartyIndicatorGuard $indicators Constructor argument.
	 * @param PartySearchService $search Constructor argument.
	 * @param PermissionHandler $permissions Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objects,
		\OCA\OpenRegister\Service\Party\PartyRoleService $roles,
		\OCA\OpenRegister\Service\Party\PartyService $parties,
		\OCA\OpenRegister\Service\Party\PartyIndicatorGuard $indicators,
		\OCA\OpenRegister\Service\Party\PartySearchService $search,
		\OCA\OpenRegister\Service\Object\PermissionHandler $permissions,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\PartyControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\PartyDomain(
					objects: $objects,
					roles: $roles,
					parties: $parties,
					indicators: $indicators,
				),
				search: new \OCA\OpenRegister\Controller\Support\PartySearch(
					search: $search,
					permissions: $permissions,
				),
			),
		];
	}

	/**
	 * Build PartyController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return PartyController
	 */
	public static function create(mixed ...$args): PartyController {
		return new PartyController(...self::args(...$args));
	}
}
