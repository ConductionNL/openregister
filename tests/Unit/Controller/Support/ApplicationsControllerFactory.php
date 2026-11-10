<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ApplicationsController;

/**
 * Builds ApplicationsController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class ApplicationsControllerFactory {
	/**
	 * The constructor arguments of ApplicationsController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ApplicationServiceInterface $applicationService Constructor argument.
	 * @param ApplicationMapperInterface $applicationMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ApplicationServiceInterface $applicationService,
		\OCA\OpenRegister\Db\ApplicationMapperInterface $applicationMapper,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ApplicationsControllerServices(
				applicationService: $applicationService,
				applicationMapper: $applicationMapper,
				logger: $logger,
			),
		];
	}//end args()

	/**
	 * Build ApplicationsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ApplicationsController
	 */
	public static function create(mixed ...$args): ApplicationsController {
		return new ApplicationsController(...self::args(...$args));
	}//end create()
}//end class
