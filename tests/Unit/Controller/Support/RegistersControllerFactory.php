<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\RegistersController;

/**
 * Builds RegistersController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class RegistersControllerFactory {
	/**
	 * The constructor arguments of RegistersController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param RegisterService $registerService Constructor argument.
	 * @param MagicMapper $objectEntityMapper Constructor argument.
	 * @param UploadService $uploadService Unused; kept so existing calls still fit.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param ConfigurationService $configurationService Constructor argument.
	 * @param AuditTrailMapper $auditTrailMapper Constructor argument.
	 * @param ObjectExporterInterface $exportService Constructor argument.
	 * @param ObjectImporterInterface $importService Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param GitHubClientInterface $githubService Constructor argument.
	 * @param IAppManager $appManager Unused; kept so existing calls still fit.
	 * @param OasService $oasService Constructor argument.
	 * @param ContainerInterface $container Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param RegisterCacheHandler $registerCacheHandler Constructor argument.
	 * @param RegisterSerializer $registerSerializer Constructor argument.
	 * @param MigrationPackService $migrationPackService Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\RegisterService $registerService,
		\OCA\OpenRegister\Db\MagicMapper $objectEntityMapper,
		\OCA\OpenRegister\Files\Service\UploadService $uploadService,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\ConfigurationService $configurationService,
		\OCA\OpenRegister\Db\AuditTrailMapper $auditTrailMapper,
		\OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface $exportService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface $importService,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface $githubService,
		\OCP\App\IAppManager $appManager,
		\OCA\OpenRegister\Service\OasService $oasService,
		\Psr\Container\ContainerInterface $container,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\Registers\RegisterCacheHandler $registerCacheHandler,
		\OCA\OpenRegister\Service\Serializer\RegisterSerializer $registerSerializer,
		\OCA\OpenRegister\Service\MigrationPackService $migrationPackService,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ControllerSession(
				userSession: $userSession,
				groupManager: $groupManager,
				logger: $logger ?? new \Psr\Log\NullLogger(),
			),
			new \OCA\OpenRegister\Controller\Support\RegistersControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\RegisterMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
						registers: $registerMapper,
						schemas: $schemaMapper,
						tables: $objectEntityMapper,
					),
					auditTrailMapper: $auditTrailMapper,
				),
				registers: new \OCA\OpenRegister\Controller\Support\RegisterServices(
					registerService: $registerService,
					registerCacheHandler: $registerCacheHandler,
					registerSerializer: $registerSerializer,
					migrationPackService: $migrationPackService,
				),
				integrations: new \OCA\OpenRegister\Controller\Support\RegisterIntegrations(
					configurationService: $configurationService,
					githubService: $githubService,
					oasService: $oasService,
					container: $container,
				),
				transfer: new \OCA\OpenRegister\Controller\Support\ObjectTransfer(
					exportService: $exportService,
					importService: $importService,
				),
			),
		];
	}

	/**
	 * Build RegistersController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return RegistersController
	 */
	public static function create(mixed ...$args): RegistersController {
		return new RegistersController(...self::args(...$args));
	}
}
