<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectAccessServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectAuthorisationServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectCallerServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectHistoryServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectMapperServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectPlatformServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectQueryServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectReadServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectRemovalServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectRuntimeServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectSaveServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectSearchServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectShapingServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectStorageServices;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectWriteServices;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Builds ObjectService from its collaborators as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectServiceFactory {
	/**
	 * The constructor arguments of ObjectService, in the order it took them before the bundles.
	 *
	 * @param DataManipulationHandler        $dataManipHandler     Constructor argument.
	 * @param DeleteObject                   $deleteHandler        Constructor argument.
	 * @param GetObject                      $getHandler           Constructor argument.
	 * @param PermissionHandlerInterface     $permissionHandler    Constructor argument.
	 * @param RenderObject                   $renderHandler        Constructor argument.
	 * @param SaveObjectInterface            $saveHandler          Constructor argument.
	 * @param SaveObjects                    $saveObjectsHandler   Constructor argument.
	 * @param SearchQueryHandler             $searchQueryHandler   Constructor argument.
	 * @param ValidateObjectInterface        $validateHandler      Constructor argument.
	 * @param LockHandler                    $lockHandler          Constructor argument.
	 * @param AuditHandler                   $auditHandler         Constructor argument.
	 * @param RelationHandler                $relationHandler      Constructor argument.
	 * @param MergeHandler                   $mergeHandler         Constructor argument.
	 * @param FacetHandler                   $facetHandler         Constructor argument.
	 * @param MetadataHandler                $metadataHandler      Constructor argument.
	 * @param PerformanceOptimizationHandler $perfOptHandler       Constructor argument.
	 * @param QueryHandler                   $queryHandler         Constructor argument.
	 * @param RevertHandler                  $revertHandler        Constructor argument.
	 * @param UtilityHandler                 $utilityHandler       Constructor argument.
	 * @param ValidationHandler              $validationHandler    Constructor argument.
	 * @param CascadingHandler               $cascadingHandler     Constructor argument.
	 * @param MigrationHandler               $migrationHandler     Constructor argument.
	 * @param RegisterMapperInterface        $registerMapper       Constructor argument.
	 * @param SchemaMapperInterface          $schemaMapper         Constructor argument.
	 * @param ViewMapperInterface            $viewMapper           Constructor argument.
	 * @param MagicMapperInterface           $objectMapper         Constructor argument.
	 * @param FileFolderServiceInterface     $fileService          Constructor argument.
	 * @param IUserSession                   $userSession          Constructor argument.
	 * @param SearchTrailService             $searchTrailService   Constructor argument.
	 * @param IGroupManager                  $groupManager         Constructor argument.
	 * @param IUserManager                   $userManager          Constructor argument.
	 * @param OrganisationServiceInterface   $organisationService  Constructor argument.
	 * @param LoggerInterface                $logger               Constructor argument.
	 * @param CacheHandlerInterface          $cacheHandler         Constructor argument.
	 * @param SettingsServiceInterface       $settingsService      Constructor argument.
	 * @param DateTimeNormalizer             $dateTimeNormalizer   Constructor argument.
	 * @param IAppContainer                  $container            Constructor argument.
	 * @param ObjectSourceRegistry           $objectSourceRegistry Constructor argument.
	 * @param AutoTransitionPass|null        $autoTransitions      Constructor argument.
	 * @param TokenGrantSource|null          $tokenGrantSource     Constructor argument.
	 *
	 * @return array<int, object>
	 */
	public static function args(
		DataManipulationHandler $dataManipHandler,
		DeleteObject $deleteHandler,
		GetObject $getHandler,
		PermissionHandlerInterface $permissionHandler,
		RenderObject $renderHandler,
		SaveObjectInterface $saveHandler,
		SaveObjects $saveObjectsHandler,
		SearchQueryHandler $searchQueryHandler,
		ValidateObjectInterface $validateHandler,
		LockHandler $lockHandler,
		AuditHandler $auditHandler,
		RelationHandler $relationHandler,
		MergeHandler $mergeHandler,
		FacetHandler $facetHandler,
		MetadataHandler $metadataHandler,
		PerformanceOptimizationHandler $perfOptHandler,
		QueryHandler $queryHandler,
		RevertHandler $revertHandler,
		UtilityHandler $utilityHandler,
		ValidationHandler $validationHandler,
		CascadingHandler $cascadingHandler,
		MigrationHandler $migrationHandler,
		RegisterMapperInterface $registerMapper,
		SchemaMapperInterface $schemaMapper,
		ViewMapperInterface $viewMapper,
		MagicMapperInterface $objectMapper,
		FileFolderServiceInterface $fileService,
		IUserSession $userSession,
		SearchTrailService $searchTrailService,
		IGroupManager $groupManager,
		IUserManager $userManager,
		OrganisationServiceInterface $organisationService,
		LoggerInterface $logger,
		CacheHandlerInterface $cacheHandler,
		SettingsServiceInterface $settingsService,
		DateTimeNormalizer $dateTimeNormalizer,
		IAppContainer $container,
		ObjectSourceRegistry $objectSourceRegistry,
		?AutoTransitionPass $autoTransitions = null,
		?TokenGrantSource $tokenGrantSource = null,
	): array {
		return [
			new ObjectWriteServices(
				save: new ObjectSaveServices(
					saveHandler: $saveHandler,
					saveObjectsHandler: $saveObjectsHandler,
					validateHandler: $validateHandler,
					validationHandler: $validationHandler,
				),
				removal: new ObjectRemovalServices(
					deleteHandler: $deleteHandler,
					mergeHandler: $mergeHandler,
					cascadingHandler: $cascadingHandler,
					migrationHandler: $migrationHandler,
				),
				history: new ObjectHistoryServices(
					lockHandler: $lockHandler,
					auditHandler: $auditHandler,
					revertHandler: $revertHandler,
					autoTransitions: $autoTransitions,
				),
			),
			new ObjectQueryServices(
				read: new ObjectReadServices(
					getHandler: $getHandler,
					renderHandler: $renderHandler,
					relationHandler: $relationHandler,
					metadataHandler: $metadataHandler,
				),
				search: new ObjectSearchServices(
					searchQueryHandler: $searchQueryHandler,
					facetHandler: $facetHandler,
					perfOptHandler: $perfOptHandler,
					queryHandler: $queryHandler,
				),
				shaping: new ObjectShapingServices(
					dataManipHandler: $dataManipHandler,
					utilityHandler: $utilityHandler,
					dateTimeNormalizer: $dateTimeNormalizer,
				),
			),
			new ObjectAccessServices(
				caller: new ObjectCallerServices(
					userSession: $userSession,
					groupManager: $groupManager,
					userManager: $userManager,
					tokenGrantSource: $tokenGrantSource,
				),
				authorisation: new ObjectAuthorisationServices(
					permissionHandler: $permissionHandler,
					organisationService: $organisationService,
				),
			),
			new ObjectPlatformServices(
				mappers: new ObjectMapperServices(
					registerMapper: $registerMapper,
					schemaMapper: $schemaMapper,
					viewMapper: $viewMapper,
					objectMapper: $objectMapper,
				),
				storage: new ObjectStorageServices(
					fileService: $fileService,
					searchTrailService: $searchTrailService,
					cacheHandler: $cacheHandler,
					objectSourceRegistry: $objectSourceRegistry,
				),
				runtime: new ObjectRuntimeServices(
					logger: $logger,
					settingsService: $settingsService,
					container: $container,
				),
			),
		];
	}

	/**
	 * Build ObjectService; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectService
	 */
	public static function create(mixed ...$args): ObjectService {
		return new ObjectService(...self::args(...$args));
	}
}
