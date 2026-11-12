<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SchemaImportController;

/**
 * Builds SchemaImportController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SchemaImportControllerFactory {
	/**
	 * The constructor arguments of SchemaImportController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param SchemaImportService $importService Constructor argument.
	 * @param SchemaMapperInterface $schemaMapper Constructor argument.
	 * @param RegisterMapperInterface $registerMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ?SchemaVersioningService $schemaVersioning Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\SchemaImport\SchemaImportService $importService,
		\OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
		\OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper,
		\Psr\Log\LoggerInterface $logger,
		?\OCA\OpenRegister\Service\Schema\SchemaVersioningService $schemaVersioning = null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\SchemaImportControllerServices(
				importService: $importService,
				schemaMapper: $schemaMapper,
				registerMapper: $registerMapper,
				schemaVersioning: $schemaVersioning,
			),
			$logger,
		];
	}

	/**
	 * Build SchemaImportController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SchemaImportController
	 */
	public static function create(mixed ...$args): SchemaImportController {
		return new SchemaImportController(...self::args(...$args));
	}
}
