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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
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
use OCA\OpenRegister\Service\UserService;
use OCA\OpenRegister\Service\Objects\DataManipulationHandler;
use OCA\OpenRegister\Service\Objects\DeleteObject;
use OCA\OpenRegister\Service\Objects\GetObject;
use OCA\OpenRegister\Service\Objects\PerformanceHandler;
use OCA\OpenRegister\Service\Objects\PermissionHandler;
use OCA\OpenRegister\Service\Objects\RenderObject;
use OCA\OpenRegister\Service\Objects\SaveObject;
use OCA\OpenRegister\Service\Objects\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Objects\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Objects\SaveObjects;
use OCA\OpenRegister\Service\Objects\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Objects\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Objects\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService\FacetHandler;
use OCA\OpenRegister\Service\ObjectService\MetadataHandler;
use OCA\OpenRegister\Service\ObjectService\BulkOperationsHandler;
use OCA\OpenRegister\Service\ObjectService\RelationHandler;
use OCA\OpenRegister\Service\ObjectService\QueryHandler;
use OCA\OpenRegister\Service\ObjectService\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\ObjectService\MergeHandler;
use OCA\OpenRegister\Service\ObjectService\UtilityHandler;
use OCA\OpenRegister\Service\Object\PublishObject;
use OCA\OpenRegister\Service\Object\DepublishObject;
use OCA\OpenRegister\Service\Object\Handlers\LockHandler;
use OCA\OpenRegister\Service\Object\Handlers\AuditHandler;
use OCA\OpenRegister\Service\Object\Handlers\PublishHandler as PublishHandlerNew;
use OCA\OpenRegister\Service\Object\Handlers\RelationHandler as RelationHandlerNew;
use OCA\OpenRegister\Service\Object\Handlers\MergeHandler as MergeHandlerNew;
use OCA\OpenRegister\Service\Object\Handlers\ExportHandler;
use OCA\OpenRegister\Service\Object\Handlers\VectorizationHandler;
use OCA\OpenRegister\Service\Object\Handlers\CrudHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
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
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
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
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
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
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\CacheHandler as ConfigurationCacheHandler;
use OCA\OpenRegister\Service\Configuration\ExportHandler as ConfigurationExportHandler;
use OCA\OpenRegister\Service\Configuration\ImportHandler as ConfigurationImportHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler as ConfigurationUploadHandler;

/**
 * Class Application
 *
 * Application class for the OpenRegister app that handles bootstrapping.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author  Nextcloud Dev Team
 * @license AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @link https://github.com/nextcloud/server/blob/master/apps-extra/openregister
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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

        // Register all services in phases to resolve circular dependencies.
        $this->registerMappersWithCircularDependencies($context);
        $this->registerCacheAndFileHandlers($context);
        $this->registerConfigurationServices($context);
        $this->registerSettingsServices($context);
        $this->registerSearchBackend($context);
        $this->registerVectorizationService($context);
        $this->registerEventListeners($context);
    }//end register()

    /**
     * Register mappers with circular dependencies.
     *
     * These must be registered in the correct order to resolve dependencies:
     * 1. OrganisationService (breaks circular dependency with SettingsService)
     * 2. SchemaMapper (depends on OrganisationMapper)
     * 3. ObjectEntityMapper (depends on SchemaMapper)
     * 4. RegisterMapper (depends on both SchemaMapper and ObjectEntityMapper)
     * 5. MagicMapper and UnifiedObjectMapper (depend on the above)
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function registerMappersWithCircularDependencies(IRegistrationContext $context): void
    {
        // Register OrganisationService without SettingsService to break circular dependency.
        $context->registerService(
            OrganisationService::class,
            function (ContainerInterface $container) {
                return new OrganisationService(
                    organisationMapper: $container->get(OrganisationMapper::class),
                    userSession: $container->get('OCP\IUserSession'),
                    session: $container->get('OCP\ISession'),
                    config: $container->get('OCP\IConfig'),
                    appConfig: $container->get('OCP\IAppConfig'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    userManager: $container->get('OCP\IUserManager'),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: null
                );
            }
        );

        // Register UserService for UserController (after OrganisationService which it depends on).
        $context->registerService(
            \OCA\OpenRegister\Service\UserService::class,
            function (ContainerInterface $container) {
                return new UserService(
                    userManager: $container->get('OCP\IUserManager'),
                    userSession: $container->get('OCP\IUserSession'),
                    config: $container->get('OCP\IConfig'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    accountManager: $container->get('OCP\Accounts\IAccountManager'),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    organisationService: $container->get(OrganisationService::class)
                );
            }
        );

        $context->registerService(
            SchemaMapper::class,
            function (ContainerInterface $container) {
                return new SchemaMapper(
                    db: $container->get('OCP\IDBConnection'),
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    validator: $container->get(PropertyValidatorHandler::class),
                    organisationMapper: $container->get(OrganisationMapper::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    appConfig: $container->get('OCP\IAppConfig')
                );
            }
        );

        $context->registerService(
            ObjectEntityMapper::class,
            function (ContainerInterface $container) {
                return new ObjectEntityMapper(
                    db: $container->get('OCP\IDBConnection'),
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    userSession: $container->get('OCP\IUserSession'),
                    schemaMapper: $container->get(SchemaMapper::class),
                    groupManager: $container->get('OCP\IGroupManager'),
                    userManager: $container->get('OCP\IUserManager'),
                    appConfig: $container->get('OCP\IAppConfig'),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    organisationMapper: $container->get(OrganisationMapper::class)
                );
            }
        );

        $context->registerService(
            RegisterMapper::class,
            function (ContainerInterface $container) {
                return new RegisterMapper(
                    db: $container->get('OCP\IDBConnection'),
                    schemaMapper: $container->get(SchemaMapper::class),
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    organisationMapper: $container->get(OrganisationMapper::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    appConfig: $container->get('OCP\IAppConfig')
                );
            }
        );

        $context->registerService(
            MagicMapper::class,
            function (ContainerInterface $container) {
                return new MagicMapper(
                    db: $container->get('OCP\IDBConnection'),
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    schemaMapper: $container->get(SchemaMapper::class),
                    registerMapper: $container->get(RegisterMapper::class),
                    config: $container->get('OCP\IConfig'),
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    userManager: $container->get('OCP\IUserManager'),
                    appConfig: $container->get('OCP\IAppConfig'),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    settingsService: $container->get(SettingsService::class),
                    container: $container
                );
            }
        );

        $context->registerService(
            UnifiedObjectMapper::class,
            function (ContainerInterface $container) {
                return new UnifiedObjectMapper(
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    magicMapper: $container->get(MagicMapper::class),
                    registerMapper: $container->get(RegisterMapper::class),
                    schemaMapper: $container->get(SchemaMapper::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    eventDispatcher: $container->get(\OCP\EventDispatcher\IEventDispatcher::class)
                );
            }
        );
    }//end registerMappersWithCircularDependencies()

    /**
     * Register cache and file handling services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerCacheAndFileHandlers(IRegistrationContext $context): void
    {
        // CacheHandler uses lazy loading of IndexService to break circular dependency.
        $context->registerService(
            CacheHandler::class,
            function (ContainerInterface $container) {
                return new CacheHandler(
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    organisationMapper: $container->get(OrganisationMapper::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    cacheFactory: $container->get('OCP\ICacheFactory'),
                    userSession: $container->get('OCP\IUserSession'),
                    container: $container,
                    registerMapper: $container->get(RegisterMapper::class),
                    schemaMapper: $container->get(SchemaMapper::class),
                    db: $container->get('OCP\IDBConnection')
                );
            }
        );

        // FolderManagementHandler without FileService to break circular dependency.
        $context->registerService(
            FolderManagementHandler::class,
            function (ContainerInterface $container) {
                return new FolderManagementHandler(
                    rootFolder: $container->get('OCP\Files\IRootFolder'),
                    objectEntityMapper: $container->get(ObjectEntityMapper::class),
                    registerMapper: $container->get(RegisterMapper::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    fileService: null
                );
            }
        );
    }//end registerCacheAndFileHandlers()

    /**
     * Register configuration-related services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerConfigurationServices(IRegistrationContext $context): void
    {
        $context->registerService(
            ConfigurationUploadHandler::class,
            function (ContainerInterface $container) {
                return new ConfigurationUploadHandler(
                    client: new Client(),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        // Register GitHubHandler explicitly to fix IConfig auto-wiring issue.
        $context->registerService(
            GitHubHandler::class,
            function (ContainerInterface $container) {
                return new GitHubHandler(
                    clientService: $container->get('OCP\Http\Client\IClientService'),
                    appConfig: $container->get('OCP\IAppConfig'),
                    config: $container->get('OCP\IConfig'),
                    cacheFactory: $container->get('OCP\ICacheFactory'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        // Register ImportHandler (with both alias and real name to prevent auto-wiring conflicts).
        $importHandlerFactory = function (
            ContainerInterface $container
        ): \OCA\OpenRegister\Service\Configuration\ImportHandler {
            $dataDir     = $container->get('OCP\IConfig')->getSystemValue('datadirectory', '');
            $appDataPath = $dataDir.'/appdata_openregister';

            $logger = $container->get('Psr\Log\LoggerInterface');

            $importHandler = new ConfigurationImportHandler(
                schemaMapper: $container->get(SchemaMapper::class),
                registerMapper: $container->get(RegisterMapper::class),
                objectEntityMapper: $container->get(ObjectEntityMapper::class),
                configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
                client: new Client(),
                appConfig: $container->get('OCP\IAppConfig'),
                logger: $logger,
                appDataPath: $appDataPath,
                uploadHandler: $container->get(ConfigurationUploadHandler::class),
                objectService: $container->get(ObjectService::class)
            );

            // Inject MagicMapper for pre-creating magic mapper tables before seed data import.
            // This prevents the race condition where the first seed object goes to blob storage.
            $importHandler->setMagicMapper($container->get(MagicMapper::class));

            // Inject UnifiedObjectMapper for routing seed data to correct storage (magic/blob).
            // This ensures objects go to the magic mapper table when the register is configured for it.
            $importHandler->setUnifiedObjectMapper($container->get(UnifiedObjectMapper::class));

            return $importHandler;
        };

        // Register under alias.
        $context->registerService(ConfigurationImportHandler::class, $importHandlerFactory);

        // Register under real class name (pointing to same factory).
        $context->registerService(
            'OCA\OpenRegister\Service\Configuration\ImportHandler',
            $importHandlerFactory
        );

        $context->registerService(
            ConfigurationService::class,
            function (ContainerInterface $container) {
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
                    objectService: $container->get(ObjectService::class),
                    githubHandler: $container->get(GitHubHandler::class),
                    gitlabHandler: $container->get(GitLabHandler::class),
                    cacheHandler: $container->get(ConfigurationCacheHandler::class),
                    previewHandler: $container->get(PreviewHandler::class),
                    exportHandler: $container->get(ConfigurationExportHandler::class),
                    // NOTE: ImportHandler is lazy-loaded in ConfigurationService to prevent circular dependency.
                    uploadHandler: $container->get(ConfigurationUploadHandler::class),
                    appDataPath: $appDataPath
                );
            }
        );

        $context->registerSearchProvider(ObjectsProvider::class);
    }//end registerConfigurationServices()

    /**
     * Register settings-related services including handlers.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerSettingsServices(IRegistrationContext $context): void
    {
        $context->registerService(
            ValidationOperationsHandler::class,
            function (ContainerInterface $container) {
                return new ValidationOperationsHandler(
                    validateHandler: $container->get(ValidateObject::class),
                    schemaMapper: $container->get(SchemaMapper::class),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    container: $container
                );
            }
        );

        $context->registerService(
            SettingsService::class,
            function (ContainerInterface $container) {
                return new SettingsService(
                    config: $container->get('OCP\IConfig'),
                    auditTrailMapper: $container->get(AuditTrailMapper::class),
                    cacheFactory: $container->get('OCP\ICacheFactory'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    logger: $container->get('Psr\Log\LoggerInterface'),
                    organisationMapper: $container->get(OrganisationMapper::class),
                    schemaCacheService: $container->get(SchemaCacheHandler::class),
                    facetCacheSvc: $container->get(FacetCacheHandler::class),
                    searchTrailMapper: $container->get(SearchTrailMapper::class),
                    userManager: $container->get('OCP\IUserManager'),
                    db: $container->get('OCP\IDBConnection'),
                    setupHandler: null,
                    objectCacheService: null,
                    container: $container,
                    appName: 'openregister',
                    validOpsHandler: $container->get(ValidationOperationsHandler::class),
                    searchBackendHandler: $container->get(SearchBackendHandler::class),
                    llmSettingsHandler: $container->get(LlmSettingsHandler::class),
                    fileSettingsHandler: $container->get(FileSettingsHandler::class),
                    objRetentionHandler: $container->get(ObjectRetentionHandler::class),
                    cacheSettingsHandler: $container->get(CacheSettingsHandler::class),
                    solrSettingsHandler: $container->get(SolrSettingsHandler::class),
                    cfgSettingsHandler: $container->get(ConfigurationSettingsHandler::class)
                );
            }
        );
    }//end registerSettingsServices()

    /**
     * Register search backend interface with dynamic backend selection.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerSearchBackend(IRegistrationContext $context): void
    {
        $context->registerService(
            \OCA\OpenRegister\Service\Index\SearchBackendInterface::class,
            function (ContainerInterface $container): \OCA\OpenRegister\Service\Index\SearchBackendInterface {
                $settingsService = $container->get(SettingsService::class);
                $backendConfig   = $settingsService->getSearchBackendConfig();
                $activeBackend   = $backendConfig['active'] ?? 'solr';

                switch ($activeBackend) {
                    case 'elasticsearch':
                        return $container->get(\OCA\OpenRegister\Service\Index\Backends\ElasticsearchBackend::class);

                    case 'solr':
                    default:
                        return $container->get(SolrBackend::class);
                }
            }
        );
    }//end registerSearchBackend()

    /**
     * Register vectorization service with strategies.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerVectorizationService(IRegistrationContext $context): void
    {
        $context->registerService(
            VectorizationService::class,
            function (ContainerInterface $container) {
                $service = new VectorizationService(
                    vectorService: $container->get(VectorEmbeddings::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );

                $fileStrategy   = $container->get(FileVectorizationStrategy::class);
                $objectStrategy = $container->get(ObjectVectorizationStrategy::class);
                $service->registerStrategy('file', $fileStrategy);
                $service->registerStrategy('object', $objectStrategy);

                return $service;
            }
        );
    }//end registerVectorizationService()

    /**
     * Register all event listeners for the application.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    private function registerEventListeners(IRegistrationContext $context): void
    {
        // Solr event listeners for automatic indexing.
        $context->registerEventListener(ObjectCreatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, SolrEventListener::class);

        // Solr event listeners for schema lifecycle management.
        $context->registerEventListener(SchemaCreatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(SchemaUpdatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(SchemaDeletedEvent::class, SolrEventListener::class);

        // FileChangeListener for automatic file text extraction.
        $context->registerEventListener(NodeCreatedEvent::class, FileChangeListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, FileChangeListener::class);

        // ObjectChangeListener for automatic object text extraction.
        $context->registerEventListener(ObjectCreatedEvent::class, ObjectChangeListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, ObjectChangeListener::class);

        // ToolRegistrationListener for agent function tools.
        $context->registerEventListener(ToolRegistrationEvent::class, ToolRegistrationListener::class);

        // WebhookEventListener for webhook delivery.
        $context->registerEventListener(ObjectCreatedEvent::class, WebhookEventListener::class);
    }//end registerEventListeners()

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
        $logger->debug('OpenRegister boot() method started.');
        $logger->debug('Got app container.');
        $logger->debug('Got event dispatcher.');
        $logger->debug('Got logger.');

        // Log boot process.
        $logger->info(
            'OpenRegister boot: Registering event listeners',
            [
                'app'       => 'openregister',
                'timestamp' => date('Y-m-d H:i:s'),
            ]
        );
        $logger->debug('Logged boot message.');

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
            }

            if ($jobList->has(SolrNightlyWarmupJob::class, null) === true) {
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
            }

            if ($jobList->has(CronFileTextExtractionJob::class, null) === true) {
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
            }

            if ($jobList->has($webhookRetryJobClass, null) === true) {
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
