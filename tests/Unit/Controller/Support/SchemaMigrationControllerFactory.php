<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SchemaMigrationController;

/**
 * Builds SchemaMigrationController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SchemaMigrationControllerFactory {
	/**
	 * The constructor arguments of SchemaMigrationController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param SchemaChangelogMapper $changelogMapper Constructor argument.
	 * @param SchemaRunMapper $runMapper Constructor argument.
	 * @param SchemaRunEntryMapper $runEntryMapper Constructor argument.
	 * @param SchemaRevalidationService $revalidationService Constructor argument.
	 * @param SchemaMigrationService $migrationService Constructor argument.
	 * @param SchemaObjectReader $objectReader Constructor argument.
	 * @param IJobList $jobList Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ?PropertyConversionService $conversions Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Db\SchemaChangelogMapper $changelogMapper,
		\OCA\OpenRegister\Db\SchemaRunMapper $runMapper,
		\OCA\OpenRegister\Db\SchemaRunEntryMapper $runEntryMapper,
		\OCA\OpenRegister\Service\Schema\SchemaRevalidationService $revalidationService,
		\OCA\OpenRegister\Service\Schema\SchemaMigrationService $migrationService,
		\OCA\OpenRegister\Service\Schema\SchemaObjectReader $objectReader,
		\OCP\BackgroundJob\IJobList $jobList,
		\OCP\IUserSession $userSession,
		\Psr\Log\LoggerInterface $logger,
		?\OCA\OpenRegister\Service\Schema\PropertyConversionService $conversions = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\SchemaMigrationControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\SchemaMigrationMappers(
					schemaMapper: $schemaMapper,
					changelogMapper: $changelogMapper,
					runMapper: $runMapper,
					runEntryMapper: $runEntryMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\SchemaMigrationDomain(
					revalidationService: $revalidationService,
					migrationService: $migrationService,
					objectReader: $objectReader,
					conversions: $conversions,
				),
				platform: new \OCA\OpenRegister\Controller\Support\RetentionPlatform(
					jobList: $jobList,
					userSession: $userSession,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build SchemaMigrationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SchemaMigrationController
	 */
	public static function create(mixed ...$args): SchemaMigrationController {
		return new SchemaMigrationController(...self::args(...$args));
	}
}
