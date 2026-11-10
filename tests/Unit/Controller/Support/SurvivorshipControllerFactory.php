<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SurvivorshipController;

/**
 * Builds SurvivorshipController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SurvivorshipControllerFactory {
	/**
	 * The constructor arguments of SurvivorshipController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param SchemaMapperInterface $schemaMapper Constructor argument.
	 * @param SourceRecordResolverInterface $sourceRecordResolver Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
		\OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface $sourceRecordResolver,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\SurvivorshipControllerServices(
				objectService: $objectService,
				schemaMapper: $schemaMapper,
				sourceRecordResolver: $sourceRecordResolver,
				userSession: $userSession,
			),
		];
	}

	/**
	 * Build SurvivorshipController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SurvivorshipController
	 */
	public static function create(mixed ...$args): SurvivorshipController {
		return new SurvivorshipController(...self::args(...$args));
	}
}
