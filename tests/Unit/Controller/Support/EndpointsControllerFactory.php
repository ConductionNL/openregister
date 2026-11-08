<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\EndpointsController;

/**
 * Builds EndpointsController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class EndpointsControllerFactory {
	/**
	 * The constructor arguments of EndpointsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param EndpointMapperInterface $endpointMapper Constructor argument.
	 * @param EndpointLogMapperInterface $endpointLogMapper Constructor argument.
	 * @param EndpointServiceInterface $endpointService Constructor argument.
	 * @param ControllerSession $session Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\EndpointMapperInterface $endpointMapper,
		\OCA\OpenRegister\Db\EndpointLogMapperInterface $endpointLogMapper,
		\OCA\OpenRegister\Service\EndpointServiceInterface $endpointService,
		\OCA\OpenRegister\Controller\Support\ControllerSession $session,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\EndpointsControllerServices(
				endpointMapper: $endpointMapper,
				endpointLogMapper: $endpointLogMapper,
				endpointService: $endpointService,
				session: $session,
			),
		];
	}//end args()

	/**
	 * Build EndpointsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return EndpointsController
	 */
	public static function create(mixed ...$args): EndpointsController {
		return new EndpointsController(...self::args(...$args));
	}//end create()
}//end class
