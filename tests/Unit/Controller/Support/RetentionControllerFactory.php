<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\RetentionController;

/**
 * Builds RetentionController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class RetentionControllerFactory {
	/**
	 * The constructor arguments of RetentionController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param RetentionService $retentionService Constructor argument.
	 * @param ObjectRetentionHandler $settingsHandler Constructor argument.
	 * @param SaveObject $saveObject Constructor argument.
	 * @param MagicMapper $objectMapper Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param AuditTrailMapper $auditMapper Constructor argument.
	 * @param DestructionService $destructionService Constructor argument.
	 * @param IJobList $jobList Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\RetentionService $retentionService,
		\OCA\OpenRegister\Service\Settings\ObjectRetentionHandler $settingsHandler,
		\OCA\OpenRegister\Service\Object\SaveObject $saveObject,
		\OCA\OpenRegister\Db\MagicMapper $objectMapper,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Db\AuditTrailMapper $auditMapper,
		\OCA\OpenRegister\Service\Archival\DestructionService $destructionService,
		\OCP\BackgroundJob\IJobList $jobList,
		\OCP\IUserSession $userSession,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\RetentionControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\RetentionMappers(
					objectMapper: $objectMapper,
					schemaMapper: $schemaMapper,
					auditMapper: $auditMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\RetentionDomain(
					retentionService: $retentionService,
					settingsHandler: $settingsHandler,
					saveObject: $saveObject,
					destructionService: $destructionService,
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
	 * Build RetentionController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return RetentionController
	 */
	public static function create(mixed ...$args): RetentionController {
		return new RetentionController(...self::args(...$args));
	}
}
