<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SourcesController;

/**
 * Builds SourcesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SourcesControllerFactory {
	/**
	 * The constructor arguments of SourcesController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param IAppConfig $config Constructor argument.
	 * @param SourceMapper $sourceMapper Constructor argument.
	 * @param IL10N $l10n Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param ICrypto $crypto Constructor argument.
	 * @param SourceFetcherRegistry $fetcherRegistry Constructor argument.
	 * @param HarvestPipelineService $pipeline Constructor argument.
	 * @param DbalConnectionFactory $connectionFactory Constructor argument.
	 * @param DatabaseIntrospectionService $introspectionService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\IAppConfig $config,
		\OCA\OpenRegister\Db\SourceMapper $sourceMapper,
		\OCP\IL10N $l10n,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCP\Security\ICrypto $crypto,
		\OCA\OpenRegister\Service\Sync\SourceFetcherRegistry $fetcherRegistry,
		\OCA\OpenRegister\Service\Sync\HarvestPipelineService $pipeline,
		\OCA\OpenRegister\Service\Dbal\DbalConnectionFactory $connectionFactory,
		\OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService $introspectionService,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\SourcesControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				mappers: new \OCA\OpenRegister\Controller\Support\SourcesMappers(
					sourceMapper: $sourceMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\SourcesDomain(
					fetcherRegistry: $fetcherRegistry,
					pipeline: $pipeline,
					connectionFactory: $connectionFactory,
					introspectionService: $introspectionService,
				),
				platform: new \OCA\OpenRegister\Controller\Support\SourcesPlatform(
					config: $config,
					l10n: $l10n,
					crypto: $crypto,
				),
			),
		];
	}

	/**
	 * Build SourcesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SourcesController
	 */
	public static function create(mixed ...$args): SourcesController {
		return new SourcesController(...self::args(...$args));
	}
}
