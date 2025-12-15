<?php


/**
 * OpenConnector Consumers Controller
 *
 * This file contains the controller for handling consumer related operations
 * in the OpenRegister application.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppInfo;

use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\ObjectEntity\LockingHandler;
use OCA\OpenRegister\Db\ObjectEntity\QueryBuilderHandler;
use OCA\OpenRegister\Db\ObjectEntity\CrudHandler;
use OCA\OpenRegister\Db\ObjectEntity\StatisticsHandler;
use OCA\OpenRegister\Db\ObjectEntity\FacetsHandler;
use OCA\OpenRegister\Db\ObjectEntity\BulkOperationsHandler;
use OCA\OpenRegister\Db\ObjectEntity\QueryOptimizationHandler;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileTextMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\DashboardService;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService\FacetHandler;
use OCA\OpenRegister\Service\ObjectService\MetadataHandler;
use OCA\OpenRegister\Service\ObjectService\BulkOperationsHandler as ServiceBulkOperationsHandler;
use OCA\OpenRegister\Service\ObjectService\RelationHandler;
use OCA\OpenRegister\Service\ObjectService\QueryHandler;
use OCA\OpenRegister\Service\ObjectService\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\ObjectService\MergeHandler;
use OCA\OpenRegister\Service\ObjectService\UtilityHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PublishObject;
use OCA\OpenRegister\Service\Object\DepublishObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\PublishHandler as PublishHandlerNew;
use OCA\OpenRegister\Service\Object\RelationHandler as RelationHandlerNew;
use OCA\OpenRegister\Service\Object\MergeHandler as MergeHandlerNew;
use OCA\OpenRegister\Service\Object\ExportHandler;
use OCA\OpenRegister\Service\Object\VectorizationHandler;
use OCA\OpenRegister\Service\Object\CrudHandler as ServiceCrudHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\FacetService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Index\Backends\SolrBackend;
use OCA\OpenRegister\Service\Index\Backends\Solr\SolrHttpClient;
use OCA\OpenRegister\Service\Index\Backends\Solr\SolrCollectionManager;
use OCA\OpenRegister\Service\Index\Backends\Solr\SolrDocumentIndexer;
use OCA\OpenRegister\Service\Index\Backends\Solr\SolrQueryExecutor;
use OCA\OpenRegister\Service\Index\Backends\Solr\SolrFacetProcessor;
use OCA\OpenRegister\Service\Index\Backends\Solr\SolrSchemaManager;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use OCA\OpenRegister\Service\NamedEntityRecognitionService;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Service\Chat\ConversationManagementHandler;
use OCA\OpenRegister\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\SolrSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Index\SetupHandler;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Command\SolrDebugCommand;
use OCA\OpenRegister\Command\SolrManagementCommand;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\BackgroundJob\SolrWarmupJob;
use OCA\OpenRegister\BackgroundJob\SolrNightlyWarmupJob;
use OCA\OpenRegister\BackgroundJob\CronFileTextExtractionJob;
use OCA\OpenRegister\Cron\WebhookRetryJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

use OCA\OpenRegister\EventListener\SolrEventListener;
use OCA\OpenRegister\Listener\FileChangeListener;
use OCA\OpenRegister\Listener\ObjectChangeListener;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ConversationCreatedEvent;
use OCA\OpenRegister\Event\ConversationUpdatedEvent;
use OCA\OpenRegister\Event\ConversationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;

use Twig\Loader\ArrayLoader;
use GuzzleHttp\Client;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\CacheHandler as ConfigurationCacheHandler;

/**
 * Class Application
 *
 * Application class for the OpenRegister app that handles bootstrapping.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author  Nextcloud Dev Team
 * @license AGPL-3.0-or-later
 *
 * @link https://github.com/nextcloud/server/blob/master/apps-extra/openregister
 */
class Application extends App implements IBootstrap
{
    /**
     * Application ID for the OpenRegister app
     *
     * @var string
     */
    public const APP_ID = 'openregister';


    /**
     * Constructor for the Application class
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(self::APP_ID);

    }//end __construct()


    /**
     * Register application components
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        /*
         * DEPENDENCY INJECTION STRATEGY:
         *
         * Nextcloud supports automatic dependency injection (autowiring) for services with
         * type-hinted constructor parameters. Most services can be autowired automatically.
         *
         * We only manually register services that require:
         * 1. Circular dependency resolution (e.g., SchemaMapper <-> ObjectEntityMapper <-> RegisterMapper)
         * 2. Special factory/configuration logic (e.g., VectorizationService with strategy registration)
         * 3. Services with non-type-hinted parameters (e.g., SaveObject with ArrayLoader)
         * 4. Services with lazy loading to break circular dependencies (e.g., CacheHandler)
         *
         * Services with only type-hinted interfaces/classes are automatically resolved by Nextcloud.
         *
         * MANUAL REGISTRATION REQUIRED FOR:
         *
         * Mappers with circular dependencies (must be registered in correct order):
         * - SchemaMapper: Used by ObjectEntityMapper, depends on OrganisationService
         * - ObjectEntityMapper: Used by many services, depends on SchemaMapper
         * - RegisterMapper: Depends on SchemaMapper and ObjectEntityMapper
         * - AuditTrailMapper: Depends on ObjectEntityMapper
         *
         * Services with special logic:
         * - CacheHandler: Lazy loading of IndexService to break circular dependency
         * - VectorizationService: Factory logic for strategy registration
         * - SaveObject: Requires ArrayLoader instance
         * - SettingsService: Currently uses ContainerInterface (to be refactored)
         */

        // ====================================================================
        // PHASE 1: MAPPERS WITH CIRCULAR DEPENDENCIES
        // These must be registered in the correct order to resolve dependencies.
        // ====================================================================
        // NOTE: SearchTrailMapper, ChunkMapper, GdprEntityMapper, EntityRelationMapper
        // can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire them automatically.
        // Register AuditTrailMapper with required dependencies.
        $context->registerService(
            AuditTrailMapper::class,
            function ($container) {
                return new AuditTrailMapper(
                    $container->get('OCP\IDBConnection'),
                    $container->get(ObjectEntityMapper::class)
                );
            }
        );

        // NOTE: WebhookLogMapper can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: WebhookMapper can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // Table existence checks are handled in mapper methods to gracefully handle missing tables.
        // NOTE: OrganisationMapper can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // Register OrganisationMapper with required dependencies (needed by SchemaMapper).
        $context->registerService(
            OrganisationMapper::class,
            function ($container) {
                return new OrganisationMapper(
                    $container->get('OCP\IDBConnection'),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get('OCP\EventDispatcher\IEventDispatcher')
                );
            }
        );

        // Register OrganisationService (needed by SchemaMapper, MUST be before SchemaMapper).
        $context->registerService(
            OrganisationService::class,
            function ($container) {
                return new OrganisationService(
                    organisationMapper: $container->get(OrganisationMapper::class),
                    userSession: $container->get('OCP\IUserSession'),
                    session: $container->get('OCP\ISession'),
                    config: $container->get('OCP\IConfig'),
                    appConfig: $container->get('OCP\IAppConfig'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    userManager: $container->get('OCP\IUserManager'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        // Register SchemaMapper with required dependencies (MUST be before ObjectEntityMapper).
        $context->registerService(
            SchemaMapper::class,
            function ($container) {
                return new SchemaMapper(
                    db: $container->get('OCP\IDBConnection'),
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    validator: $container->get(PropertyValidatorHandler::class),
                    organisationService: $container->get(OrganisationService::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    appConfig: $container->get('OCP\IAppConfig')
                );
            }
        );

        // Register ObjectEntityMapper with IGroupManager and IUserManager dependencies + 7 handlers.
        $context->registerService(
            ObjectEntityMapper::class,
            function ($container) {
                return new ObjectEntityMapper(
                    db: $container->get('OCP\IDBConnection'),
                    // mySQLJsonService: $container->get(MySQLJsonService::class), // REMOVED: No services in mappers
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    userSession: $container->get('OCP\IUserSession'),
                    schemaMapper: $container->get(SchemaMapper::class),
                    groupManager: $container->get('OCP\IGroupManager'),
                    userManager: $container->get('OCP\IUserManager'),
                    appConfig: $container->get('OCP\IAppConfig'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                    // organisationService: REMOVED - No services in mappers
                    // ALL HANDLERS REMOVED: No handlers in mappers
                );
            }
        );

        // Register RegisterMapper with required dependencies (MUST be after ObjectEntityMapper).
        $context->registerService(
            RegisterMapper::class,
            function ($container) {
                return new RegisterMapper(
                    db: $container->get('OCP\IDBConnection'),
                    schemaMapper: $container->get(SchemaMapper::class),
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    organisationService: $container->get(OrganisationService::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    appConfig: $container->get('OCP\IAppConfig')
                );
            }
        );

        // NOTE: SearchTrailService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        /*
         * Register SolrService for advanced search capabilities (disabled due to performance issues).
         * Issue: Even with lazy loading, DI registration causes performance problems.
         *
         * $context->registerService(
         *     SolrService::class,
         *     function ($container) {
         *         return new SolrService(
         *             $container->get(SettingsService::class),
         *             $container->get('Psr\Log\LoggerInterface'),
         *             $container->get(ObjectEntityMapper::class),
         *             $container->get('OCP\IConfig')
         *         );
         *     }
         * );
         */

        // Register CacheHandler for performance optimization with lightweight SOLR.
        // NOTE: CacheHandler uses IAppContainer for lazy loading IndexService to break circular dependency.
        // This breaks the circular dependency: CacheHandler <-> IndexService.
        $context->registerService(
            CacheHandler::class,
            function ($container) {
                return new CacheHandler(
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    organisationMapper: $container->get(OrganisationMapper::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    cacheFactory: $container->get('OCP\ICacheFactory'),
                    userSession: $container->get('OCP\IUserSession'),
                    container: $container
                );
            }
        );

        // NOTE: FacetService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ValidationOperationsHandler can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: MetadataHydrationHandler can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: BulkValidationHandler can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ObjectService handlers can be autowired (only type-hinted parameters).
        // - ValidationHandler (validateRequiredFields, validateObjectsBySchema, handleValidationException)
        // - FacetHandler (getFacetsForObjects, getFacetableFields)
        // - MetadataHandler (getValueFromPath, generateSlugFromValue, createSlugHelper)
        // - BulkOperationsHandler (saveObjects, deleteObjects, publishObjects, depublishObjects)
        // - RelationHandler (applyInversedByFilter, extractRelatedData, bulk relationship loading)
        // - QueryHandler (searchObjects, searchObjectsPaginated, countSearchObjects)
        // - PerformanceOptimizationHandler (getActiveOrganisationForContext)
        // - MergeHandler (mergeObjects, transferObjectFiles, deleteObjectFiles)
        // - UtilityHandler (isUuid, normalizeEntity, normalizeToArray, cleanQuery, calculateEfficiency, getUrlSeparator)
        // All removed manual registration - Nextcloud will autowire them automatically.
        // NOTE: New Objects\Handlers (Phase 1 complete - Dec 2024) can be autowired:
        // - LockHandler (lock, unlock, isLocked, getLockInfo)
        // - AuditHandler (getLogs, validateObjectOwnership)
        // - PublishHandler (publish, depublish, isPublished, getPublicationStatus)
        // - RelationHandler (getContracts, getUses, getUsedBy, resolveReferences)
        // - MergeHandler (merge, migrate)
        // - ExportHandler (export, import, downloadObjectFiles)
        // - VectorizationHandler (vectorizeBatch, getStatistics, getCount)
        // - CrudHandler (list, get, create, update, patch, delete, buildSearchQuery)
        // All autowired automatically - no manual registration needed.
        // Register SaveObject with handlers and consolidated cache services.
        $context->registerService(
            SaveObject::class,
            function ($container) {
                return new SaveObject(
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    metadataHydrationHandler: $container->get(MetadataHydrationHandler::class),
                    filePropertyHandler: $container->get(FilePropertyHandler::class),
                    userSession: $container->get('OCP\IUserSession'),
                    auditTrailMapper: $container->get('OCA\OpenRegister\Db\AuditTrailMapper'),
                    schemaMapper: $container->get(SchemaMapper::class),
                    registerMapper: $container->get(RegisterMapper::class),
                    urlGenerator: $container->get('OCP\IURLGenerator'),
                    cacheHandler: $container->get(CacheHandler::class),
                    settingsService: $container->get(SettingsService::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    arrayLoader: new ArrayLoader([])
                );
            }
        );

        // NOTE: DeleteObject can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: GetObject can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: RenderObject can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: OrganisationService is registered earlier (before SchemaMapper) to break circular dependency.
        // NOTE: SaveObjects can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ObjectService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // Register ConfigurationService with appDataPath parameter.
        $context->registerService(
            ConfigurationService::class,
            function ($container) {
                // Get the app data directory path.
                $dataDir     = $container->get('OCP\IConfig')->getSystemValue('datadirectory', '');
                $appDataPath = $dataDir.'/appdata_openregister';

                return new ConfigurationService(
                    schemaMapper: $container->get(SchemaMapper::class),
                    registerMapper: $container->get(RegisterMapper::class),
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
                    appManager: $container->get('OCP\App\IAppManager'),
                    container: $container,
                    appConfig: $container->get('OCP\IAppConfig'),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    client: new Client(),
                    objectService: null,
                // CIRCULAR FIX
                    githubHandler: $container->get(GitHubHandler::class),
                    gitlabHandler: $container->get(GitLabHandler::class),
                    cacheHandler: $container->get(ConfigurationCacheHandler::class),
                    appDataPath: $appDataPath
                );
            }
        );

        // NOTE: ImportService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ExportService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: SolrEventListener can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: SchemaCacheService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: FacetCacheHandler can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ObjectsProvider can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // Register ObjectsProvider as a search provider for Nextcloud search.
        $context->registerSearchProvider(ObjectsProvider::class);

        // ====================================================================
        // SETTINGS HANDLERS
        // Handler-based architecture for SettingsService to break down God Object.
        // ====================================================================
        // NOTE: All Settings handlers can be autowired except those requiring the container.
        // ValidationOperationsHandler, SearchBackendHandler, LlmSettingsHandler,
        // FileSettingsHandler, ObjectRetentionHandler can be autowired.
        // CacheSettingsHandler, SolrSettingsHandler require container for lazy loading.
        // Register SettingsService BEFORE IndexService to break circular dependency.
        // ====================================================================
        // SETTINGS HANDLERS - Register all 8 specialized handlers
        // ====================================================================
        // Handlers have string parameters ($appName) that can't be autowired, so we register them manually.
        // 1. ValidationOperationsHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\ValidationOperationsHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\ValidationOperationsHandler(
                $c->get(\OCA\OpenRegister\Service\Object\ValidateObject::class),
                $c->get(\OCA\OpenRegister\Db\SchemaMapper::class),
                $c->get('Psr\Log\LoggerInterface'),
                $c
            )
        );

        // 2. SearchBackendHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\SearchBackendHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\SearchBackendHandler(
                $c->get('OCP\IConfig'),
                $c->get('Psr\Log\LoggerInterface'),
                'openregister'
            )
        );

        // 3. LlmSettingsHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\LlmSettingsHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\LlmSettingsHandler(
                $c->get('OCP\IConfig'),
                'openregister'
            )
        );

        // 4. FileSettingsHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\FileSettingsHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\FileSettingsHandler(
                $c->get('OCP\IConfig'),
                'openregister'
            )
        );

        // 5. ObjectRetentionHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\ObjectRetentionHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\ObjectRetentionHandler(
                $c->get('OCP\IConfig'),
                'openregister'
            )
        );

        // 6. CacheSettingsHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\CacheSettingsHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\CacheSettingsHandler(
                $c->get('OCP\ICacheFactory'),
                $c->get(SchemaCacheService::class),
                $c->get(FacetCacheHandler::class),
                null,
// objectCacheService - lazy loaded.
                $c
// container for lazy loading.
            )
        );

        // 7. SolrSettingsHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\SolrSettingsHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\SolrSettingsHandler(
                $c->get('OCP\IConfig'),
                null,
// objectCacheService - lazy loaded.
                $c,
// container for lazy loading.
                'openregister'
            )
        );

        // 8. ConfigurationSettingsHandler
        $context->registerService(
            \OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler::class,
            fn($c) => new \OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler(
                $c->get('OCP\IConfig'),
                $c->get('OCP\IGroupManager'),
                $c->get('OCP\IUserManager'),
                $c->get(OrganisationMapper::class),
                $c->get('Psr\Log\LoggerInterface'),
                'openregister'
            )
        );

        // NOTE: SettingsService no longer depends on IndexService (removed to break circular dependency).
        // IndexService operations are now handled directly in the controller.
        // SettingsService uses IAppContainer for lazy loading SchemaMapper and CacheHandler.
        // SettingsService now delegates to 8 specialized handlers following handler-based architecture.
        $context->registerService(
            SettingsService::class,
            function ($container) {
                // CacheHandler is not available yet (will be lazy-loaded via container if needed).
                return new SettingsService(
                    $container->get('OCP\IConfig'),
                    $container->get(AuditTrailMapper::class),
                    $container->get('OCP\ICacheFactory'),
                    $container->get('OCP\IGroupManager'),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get(ObjectEntityMapper::class),
                    $container->get(OrganisationMapper::class),
                    $container->get(SchemaCacheService::class),
                    $container->get(FacetCacheHandler::class),
                    $container->get(SearchTrailMapper::class),
                    $container->get('OCP\IUserManager'),
                    $container->get('OCP\IDBConnection'),
                    null,
                    // CacheHandler - lazy-loaded via container.
                    $container,
                    'openregister',
                    // Settings handlers - delegated business logic.
                    $container->get(\OCA\OpenRegister\Service\Settings\ValidationOperationsHandler::class),
                    $container->get(\OCA\OpenRegister\Service\Settings\SearchBackendHandler::class),
                    $container->get(\OCA\OpenRegister\Service\Settings\LlmSettingsHandler::class),
                    $container->get(\OCA\OpenRegister\Service\Settings\FileSettingsHandler::class),
                    $container->get(\OCA\OpenRegister\Service\Settings\ObjectRetentionHandler::class),
                    $container->get(\OCA\OpenRegister\Service\Settings\CacheSettingsHandler::class),
                    $container->get(\OCA\OpenRegister\Service\Settings\SolrSettingsHandler::class),
                    $container->get(\OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler::class)
                );
            }
        );

        // NOTE: SolrHttpClient, SolrCollectionManager, SolrDocumentIndexer,
        // SolrQueryExecutor, SolrFacetProcessor, SolrSchemaManager, and SolrBackend
        // can all be autowired (only type-hinted parameters).
        // Nextcloud will automatically resolve them via dependency injection.
        // Register SearchBackendInterface - dynamically select backend from configuration
        $context->registerService(
            \OCA\OpenRegister\Service\Index\SearchBackendInterface::class,
            function ($container) {
                // Read backend configuration from settings
                $settingsService = $container->get(SettingsService::class);
                $backendConfig   = $settingsService->getSearchBackendConfig();
                $activeBackend   = $backendConfig['active'] ?? 'solr';

                // Select backend based on configuration
                switch ($activeBackend) {
                    case 'elasticsearch':
                        return $container->get(\OCA\OpenRegister\Service\Index\Backends\ElasticsearchBackend::class);

                    case 'solr':
                    default:
                        return $container->get(SolrBackend::class);
                }
            }
        );

        // ====================================================================
        // PHASE 3: INDEX HANDLERS
        // All index handlers can be autowired (only type-hinted parameters).
        // Nextcloud will automatically resolve them via dependency injection.
        // ====================================================================
        // NOTE: DocumentBuilder, BulkIndexer, WarmupHandler, and FacetBuilder
        // can all be autowired. No manual registration needed.
        // Register SolrDebugCommand for SOLR debugging.
        // NOTE: Must be registered manually because it depends on SettingsService which has circular dependencies.
        $context->registerService(
            SolrDebugCommand::class,
            function ($container) {
                return new SolrDebugCommand(
                    settingsService: $container->get(SettingsService::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    config: $container->get('OCP\IConfig'),
                    clientService: $container->get('OCP\Http\Client\IClientService')
                );
            }
        );

        // NOTE: IndexService and handlers can be autowired.
        // Removed manual registration - Nextcloud will autowire them automatically.
        // NOTE: VectorEmbeddings and all handlers (EmbeddingGeneratorHandler, VectorStorageHandler,
        // VectorSearchHandler, VectorStatsHandler) can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire them automatically.
        // NOTE: NamedEntityRecognitionService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: FileVectorizationStrategy can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ObjectVectorizationStrategy can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // Register unified VectorizationService with strategies.
        $context->registerService(
            VectorizationService::class,
            function ($container) {
                $service = new VectorizationService(
                    vectorService: $container->get(VectorEmbeddings::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );

                // Register strategies.
                $service->registerStrategy(entityType: 'file', strategy: $container->get(FileVectorizationStrategy::class));
                $service->registerStrategy(entityType: 'object', strategy: $container->get(ObjectVectorizationStrategy::class));

                return $service;
            }
        );

        // NOTE: ChatService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // Register Chat handlers explicitly (used by ChatService facade).
        // While these could autowire, explicit registration ensures proper DI chain.
        $context->registerService(
            \OCA\OpenRegister\Service\Chat\ContextRetrievalHandler::class,
            function ($c) {
                return new \OCA\OpenRegister\Service\Chat\ContextRetrievalHandler(
                    $c->get(\OCA\OpenRegister\Service\Vectorization\VectorEmbeddings::class),
                    $c->get(\OCA\OpenRegister\Service\IndexService::class),
                    $c->get('Psr\Log\LoggerInterface')
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Chat\ToolManagementHandler::class,
            function ($c) {
                return new \OCA\OpenRegister\Service\Chat\ToolManagementHandler(
                    $c->get(\OCA\OpenRegister\Db\AgentMapper::class),
                    $c->get(\OCA\OpenRegister\Service\ToolRegistry::class),
                    $c->get('Psr\Log\LoggerInterface')
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Chat\ResponseGenerationHandler::class,
            function ($c) {
                return new \OCA\OpenRegister\Service\Chat\ResponseGenerationHandler(
                    $c->get(\OCA\OpenRegister\Service\SettingsService::class),
                    $c->get(\OCA\OpenRegister\Service\Chat\ToolManagementHandler::class),
                    $c->get('Psr\Log\LoggerInterface')
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Chat\MessageHistoryHandler::class,
            function ($c) {
                return new \OCA\OpenRegister\Service\Chat\MessageHistoryHandler(
                    $c->get(\OCA\OpenRegister\Db\MessageMapper::class),
                    $c->get(\OCA\OpenRegister\Db\ConversationMapper::class),
                    $c->get('Psr\Log\LoggerInterface')
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Chat\ConversationManagementHandler::class,
            function ($c) {
                return new \OCA\OpenRegister\Service\Chat\ConversationManagementHandler(
                    $c->get(\OCA\OpenRegister\Db\ConversationMapper::class),
                    $c->get(\OCA\OpenRegister\Db\MessageMapper::class),
                    $c->get(\OCA\OpenRegister\Service\SettingsService::class),
                    $c->get(\OCA\OpenRegister\Service\Chat\ResponseGenerationHandler::class),
                    $c->get('Psr\Log\LoggerInterface')
                );
            }
        );
        // NOTE: TextExtractionService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: FileChangeListener can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ObjectChangeListener can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: SolrManagementCommand can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: ToolRegistry can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // NOTE: WebhookService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // WebhookService creates GuzzleHttp\Client directly.
        // Register GitHubHandler for GitHub API operations.
        $context->registerService(
            GitHubHandler::class,
            function ($container) {
                return new GitHubHandler(
                    client: $container->get('OCP\Http\Client\IClientService')->newClient(),
                    config: $container->get('OCP\IConfig'),
                    cacheFactory: $container->get('OCP\ICacheFactory'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        // NOTE: DashboardService can be autowired (only type-hinted parameters).
        // Removed manual registration - Nextcloud will autowire it automatically.
        // Register GitLabHandler for GitLab API operations.
        // NOTE: Must be registered manually because it requires IClientService->newClient() factory call.
        $context->registerService(
            GitLabHandler::class,
            function ($container) {
                return new GitLabHandler(
                    $container->get('OCP\Http\Client\IClientService')->newClient(),
                    $container->get(id: 'OCP\IConfig'),
                    $container->get(id: 'Psr\Log\LoggerInterface')
                );
            }
        );

        // NOTE: Configuration\CacheHandler can be autowired (only type-hinted parameters).
        // Nextcloud will automatically resolve it via dependency injection.
        // Register Solr event listeners for automatic indexing.
        $context->registerEventListener(ObjectCreatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, SolrEventListener::class);

        // Register Solr event listeners for schema lifecycle management.
        $context->registerEventListener(SchemaCreatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(SchemaUpdatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(SchemaDeletedEvent::class, SolrEventListener::class);

        // Register FileChangeListener for automatic file text extraction.
        $context->registerEventListener(NodeCreatedEvent::class, FileChangeListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, FileChangeListener::class);

        // Register ObjectChangeListener for automatic object text extraction.
        $context->registerEventListener(ObjectCreatedEvent::class, ObjectChangeListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ObjectChangeListener::class);

        // Register ToolRegistrationListener for agent function tools.
        $context->registerEventListener(ToolRegistrationEvent::class, ToolRegistrationListener::class);

        // Register WebhookEventListener for webhook delivery on all OpenRegister events.
        $context->registerEventListener(ObjectCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ObjectLockedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ObjectUnlockedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ObjectRevertedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(RegisterCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(RegisterUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(RegisterDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(SchemaCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(SchemaUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(SchemaDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ApplicationCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ApplicationUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ApplicationDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(AgentCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(AgentUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(AgentDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(SourceCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(SourceUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(SourceDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ConfigurationCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ConfigurationUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ConfigurationDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ViewCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ViewUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ViewDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ConversationCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ConversationUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(ConversationDeletedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(OrganisationCreatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(OrganisationUpdatedEvent::class, WebhookEventListener::class);
        $context->registerEventListener(OrganisationDeletedEvent::class, WebhookEventListener::class);

    }//end register()


    /**
     * Boot application components
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        // Register event listeners for testing and functionality.
        $container = $context->getAppContainer();
        $container->get(IEventDispatcher::class);
        $logger = $container->get(id: 'Psr\Log\LoggerInterface');

        // Log boot process.
        $logger->info(
            'OpenRegister boot: Registering event listeners',
            [
                'app'       => 'openregister',
                'timestamp' => date('Y-m-d H:i:s'),
            ]
        );

        try {
            $logger->info('OpenRegister boot: Event listeners registered successfully');

            // Register recurring SOLR nightly warmup job.
            $jobList = $container->get('OCP\BackgroundJob\IJobList');

            // Check if the nightly warmup job is already registered.
            if ($jobList->has(SolrNightlyWarmupJob::class, null) === false) {
                $jobList->add(SolrNightlyWarmupJob::class);
                $logger->info(
                    '🌙 SOLR Nightly Warmup Job registered successfully',
                    [
                        'job_class' => SolrNightlyWarmupJob::class,
                        'interval'  => '24 hours (daily at 00:00)',
                    ]
                );
            } else {
                $logger->debug('SOLR Nightly Warmup Job already registered');
            }

            // Register recurring cron file text extraction job.
            if ($jobList->has(CronFileTextExtractionJob::class, null) === false) {
                $jobList->add(CronFileTextExtractionJob::class);
                $logger->info(
                    '🔄 Cron File Text Extraction Job registered successfully',
                    [
                        'job_class' => CronFileTextExtractionJob::class,
                        'interval'  => '15 minutes',
                    ]
                );
            } else {
                $logger->debug('Cron File Text Extraction Job already registered');
            }

            // Register recurring webhook retry job.
            $webhookRetryJobClass = 'OCA\OpenRegister\Cron\WebhookRetryJob';
            if ($jobList->has($webhookRetryJobClass, null) === false) {
                $jobList->add($webhookRetryJobClass);
                $logger->info(
                    '🔄 Webhook Retry Job registered successfully',
                    [
                        'job_class' => $webhookRetryJobClass,
                        'interval'  => '5 minutes',
                    ]
                );
            } else {
                $logger->debug('Webhook Retry Job already registered');
            }
        } catch (\Exception $e) {
            $logger->error(
                'OpenRegister boot: Failed to register event listeners and background jobs',
                [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );
        }//end try

    }//end boot()


}//end class
