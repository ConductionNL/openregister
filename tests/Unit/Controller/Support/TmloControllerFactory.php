<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TmloController;

/**
 * Builds TmloController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TmloControllerFactory {
	/**
	 * The constructor arguments of TmloController.
	 *
	 * @param string $appName Unused; kept so existing calls still fit.
	 * @param IRequest $request Constructor argument.
	 * @param TmloService $tmloService Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ExportGate $exportGate Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\TmloService $tmloService,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\Export\ExportGate $exportGate,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\TmloControllerServices(
				records: new \OCA\OpenRegister\Controller\Support\TmloRecords(
					registerMapper: $registerMapper,
					schemaMapper: $schemaMapper,
					tmloService: $tmloService,
					objectService: $objectService,
				),
				guards: new \OCA\OpenRegister\Controller\Support\TmloGuards(
					exportGate: $exportGate,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build TmloController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TmloController
	 */
	public static function create(mixed ...$args): TmloController {
		return new TmloController(...self::args(...$args));
	}
}
