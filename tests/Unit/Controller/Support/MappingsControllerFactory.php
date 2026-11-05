<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\MappingsController;

/**
 * Builds MappingsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class MappingsControllerFactory {
	/**
	 * The constructor arguments of MappingsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IAppConfig $config Constructor argument.
	 * @param MappingMapper $mappingMapper Constructor argument.
	 * @param MappingService $mappingService Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\IAppConfig $config,
		\OCA\OpenRegister\Db\MappingMapper $mappingMapper,
		\OCA\OpenRegister\Service\MappingService $mappingService,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\MappingsControllerServices(
				config: $config,
				mappingMapper: $mappingMapper,
				mappingService: $mappingService,
				organisationService: $organisationService,
			),
			$logger,
		];
	}

	/**
	 * Build MappingsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return MappingsController
	 */
	public static function create(mixed ...$args): MappingsController {
		return new MappingsController(...self::args(...$args));
	}
}
