<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectsController;

/**
 * Builds ObjectsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectsControllerFactory {
	/**
	 * The constructor arguments of ObjectsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IAppConfig $config Unused; kept so existing calls still fit.
	 * @param IAppManager $appManager Unused; kept so existing calls still fit.
	 * @param ContainerInterface $container Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param AuditTrailMapper $auditTrailMapper Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param ObjectExporterInterface $exportService Constructor argument.
	 * @param ObjectImporterInterface $importService Constructor argument.
	 * @param ?WebhookService $webhookService Constructor argument.
	 * @param ?LoggerInterface $logger Constructor argument.
	 * @param ?GeoFilterParser $geoFilterParser Constructor argument.
	 * @param ?GeoFilterApplier $geoFilterApplier Constructor argument.
	 * @param ?JsonLdSerializer $jsonLdSerializer Constructor argument.
	 * @param ?JsonLdContextService $jsonLdContextService Constructor argument.
	 * @param ?GeoFeatureCollectionBuilder $geoFeatureBuilder Constructor argument.
	 * @param ?PdokGeocoder $pdokGeocoder Constructor argument.
	 * @param ?DeepLinkRegistryService $deepLinkRegistry Constructor argument.
	 * @param ?IURLGenerator $relationUrlGenerator Constructor argument.
	 * @param ?DeletionWindowService $windowService Constructor argument.
	 * @param ?UniqueHintWarnings $uniqueHintWarnings Constructor argument.
	 * @param ?PurposeGuard $purposeGuard Constructor argument.
	 * @param ?StateHistoryProjector $stateHistory Constructor argument.
	 * @param ?UpsertOnKeyHandler $upsertOnKeyHandler Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\IAppConfig $config,
		\OCP\App\IAppManager $appManager,
		\Psr\Container\ContainerInterface $container,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Db\AuditTrailMapper $auditTrailMapper,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface $exportService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface $importService,
		?\OCA\OpenRegister\Service\WebhookService $webhookService = null,
		?\Psr\Log\LoggerInterface $logger = null,
		?\OCA\OpenRegister\Search\Service\Geo\GeoFilterParser $geoFilterParser = null,
		?\OCA\OpenRegister\Search\Service\Geo\GeoFilterApplier $geoFilterApplier = null,
		?\OCA\OpenRegister\Service\JsonLd\JsonLdSerializer $jsonLdSerializer = null,
		?\OCA\OpenRegister\Service\JsonLd\JsonLdContextService $jsonLdContextService = null,
		?\OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder $geoFeatureBuilder = null,
		?\OCA\OpenRegister\Service\Geo\PdokGeocoder $pdokGeocoder = null,
		?\OCA\OpenRegister\Service\DeepLinkRegistryService $deepLinkRegistry = null,
		?\OCP\IURLGenerator $relationUrlGenerator = null,
		?\OCA\OpenRegister\Service\Deletion\DeletionWindowService $windowService = null,
		?\OCA\OpenRegister\Service\Quality\UniqueHintWarnings $uniqueHintWarnings = null,
		?\OCA\OpenRegister\Service\Audit\PurposeGuard $purposeGuard = null,
		?\OCA\OpenRegister\Service\History\StateHistoryProjector $stateHistory = null,
		?\OCA\OpenRegister\Service\Object\UpsertOnKeyHandler $upsertOnKeyHandler = null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ControllerSession(
				userSession: $userSession,
				groupManager: $groupManager,
				logger: $logger ?? new \Psr\Log\NullLogger(),
			),
			new \OCA\OpenRegister\Controller\Support\ObjectsControllerServices(
				store: new \OCA\OpenRegister\Controller\Support\ObjectStore(
					objectService: $objectService,
					registerMapper: $registerMapper,
					schemaMapper: $schemaMapper,
					auditTrailMapper: $auditTrailMapper,
				),
				transfer: new \OCA\OpenRegister\Controller\Support\ObjectTransfer(
					exportService: $exportService,
					importService: $importService,
					webhookService: $webhookService,
					upsertOnKeyHandler: $upsertOnKeyHandler,
				),
				governance: new \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices(
					windowService: $windowService,
					uniqueHintWarnings: $uniqueHintWarnings,
					purposeGuard: $purposeGuard,
					stateHistory: $stateHistory,
				),
				extensions: new \OCA\OpenRegister\Controller\Support\ObjectsExtensions(
					geo: new \OCA\OpenRegister\Controller\Support\GeoServices(
						geoFilterParser: $geoFilterParser,
						geoFilterApplier: $geoFilterApplier,
						geoFeatureBuilder: $geoFeatureBuilder,
						pdokGeocoder: $pdokGeocoder,
					),
					linkedData: new \OCA\OpenRegister\Controller\Support\LinkedDataServices(
						jsonLdSerializer: $jsonLdSerializer,
						jsonLdContextService: $jsonLdContextService,
						deepLinkRegistry: $deepLinkRegistry,
						relationUrlGenerator: $relationUrlGenerator,
					),
					container: $container,
				),
			),
		];
	}

	/**
	 * Build ObjectsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectsController
	 */
	public static function create(mixed ...$args): ObjectsController {
		return new ObjectsController(...self::args(...$args));
	}
}
