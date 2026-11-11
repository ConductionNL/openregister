<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\CalculationsController;

/**
 * Builds CalculationsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class CalculationsControllerFactory {
	/**
	 * The constructor arguments of CalculationsController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param OperatorCatalogue $catalogue Constructor argument.
	 * @param CalculationTrialService $trials Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param MagicMapper $objectMapper Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Calculation\OperatorCatalogue $catalogue,
		\OCA\OpenRegister\Service\Calculation\CalculationTrialService $trials,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Db\MagicMapper $objectMapper,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\CalculationsControllerServices(
				catalogue: $catalogue,
				trials: $trials,
				schemaCatalog: new \OCA\OpenRegister\Db\SchemaCatalog($registerMapper, $schemaMapper, $objectMapper),
				objectMapper: $objectMapper,
			),
		];
	}

	/**
	 * Build CalculationsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return CalculationsController
	 */
	public static function create(mixed ...$args): CalculationsController {
		return new CalculationsController(...self::args(...$args));
	}
}
