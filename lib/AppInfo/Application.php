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
 * @author    Conduction Development Team <info@conduction.nl>
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

use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileTextMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\DeployedWorkflowMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\DashboardService;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
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
use OCA\OpenRegister\Service\ObjectService\RelationHandler;
use OCA\OpenRegister\Service\ObjectService\QueryHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\ObjectService\MergeHandler;
use OCA\OpenRegister\Service\ObjectService\UtilityHandler;
use OCA\OpenRegister\Service\Object\PublishObject;
use OCA\OpenRegister\Service\Object\Handlers\LockHandler;
use OCA\OpenRegister\Service\Object\Handlers\AuditHandler;
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
use OCA\OpenRegister\Service\TenantKeyService;
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
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\BackgroundJob\SolrWarmupJob;
use OCA\OpenRegister\BackgroundJob\SolrNightlyWarmupJob;
use OCA\OpenRegister\BackgroundJob\NameCacheWarmupJob;
use OCA\OpenRegister\BackgroundJob\BlobMigrationJob;
use OCA\OpenRegister\BackgroundJob\CronFileTextExtractionJob;
use OCA\OpenRegister\Cron\WebhookRetryJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\OpenRegister\EventListener\SolrEventListener;
use OCA\OpenRegister\Listener\CommentsEntityListener;
use OCA\OpenRegister\Listener\FileChangeListener;
use OCA\OpenRegister\Listener\ObjectChangeListener;
use OCA\OpenRegister\Listener\ObjectCleanupListener;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Listener\GraphQLSubscriptionListener;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCA\OpenRegister\Listener\FilesSidebarListener;
use OCA\OpenRegister\Listener\AggregationCacheInvalidationListener;
use OCA\OpenRegister\Listener\AggregationThresholdListener;
use OCA\OpenRegister\Listener\RealtimeEventListener;
use OCA\OpenRegister\Listener\TranslationProjectionListener;
use OCA\OpenRegister\Listener\AnnotationNotificationListener;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use OCA\OpenRegister\Notification\AnnotationNotifier;
use OCA\OpenRegister\Listener\CalculationOnSaveListener;
use OCA\OpenRegister\Listener\HookListener;
use OCA\OpenRegister\Listener\LifecycleInitialStateListener;
use OCA\OpenRegister\Listener\LifecycleValidationListener;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TaskService;
use OCP\Comments\CommentsEntityEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
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
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Middleware\LanguageMiddleware;
use OCA\OpenRegister\Capabilities\UrnCapability;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\ObjectsToolProvider;
use OCA\OpenRegister\Service\Mcp\McpToolsService;

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
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register application components
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register request-scoped LanguageService as a singleton (shared per request).
        $context->registerService(
            LanguageService::class,
            function () {
                return new LanguageService();
            }
        );

        // Register the LanguageMiddleware for Accept-Language header parsing.
        $context->registerMiddleware(LanguageMiddleware::class);

        // Register the default no-op TranslationProvider. Operators replace
        // this binding with a real provider (LibreTranslate / DeepL / etc.)
        // by overriding it in their own app's registration.
        $context->registerService(
            TranslationProviderInterface::class,
            function () {
                return new IdentityTranslationProvider();
            }
        );

        // Register the TenantQuotaMiddleware for tenant quota enforcement and status checks.
        $context->registerMiddleware(\OCA\OpenRegister\Middleware\TenantQuotaMiddleware::class);

        // Register the OasValidationMiddleware for opt-in request-body
        // validation against per-operation OAS schemas. Activates only on
        // POST/PUT/PATCH with `?_validate=true`; pass-through otherwise.
        $context->registerMiddleware(\OCA\OpenRegister\Middleware\OasValidationMiddleware::class);

        // Register all services in phases to resolve circular dependencies.
        $this->registerMappersWithCircularDependencies(context: $context);
        $this->registerCacheAndFileHandlers(context: $context);
        $this->registerConfigurationServices(context: $context);
        $this->registerSettingsServices(context: $context);
        $this->registerSearchBackend(context: $context);
        $this->registerVectorizationService(context: $context);
        $this->registerObjectInteractionServices(context: $context);
        $this->registerIntegrationRegistry(context: $context);
        $this->registerEventListeners(context: $context);
        $this->registerMcpToolProviders(context: $context);

        // Register the annotation-driven INotifier so notifications fired by
        // AnnotationNotificationDispatcher get a parsed subject — without
        // this Nextcloud silently drops the notification.
        $context->registerNotifierService(AnnotationNotifier::class);

        // Surface URN identifier surface via Nextcloud capabilities API so
        // clients can discover URN endpoints + the instance slug without
        // probing routes.
        $context->registerCapability(UrnCapability::class);

        // pluggable-integration-registry task 4.5 (tasks.md#task-22):
        // advertise the integration registry through the OCS
        // capabilities endpoint.
        $context->registerCapability(\OCA\OpenRegister\Capabilities\IntegrationsCapability::class);
    }//end register()

    /**
     * Register mappers with circular dependencies.
     *
     * These must be registered in the correct order to resolve dependencies:
     * 1. OrganisationService (breaks circular dependency with SettingsService)
     * 2. SchemaMapper (depends on OrganisationMapper)
     * 3. RegisterMapper (depends on SchemaMapper)
     * 4. MagicMapper and MagicMapper (depend on the above)
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
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
                    organisationService: $container->get(OrganisationService::class),
                    eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    avatarManager: $container->get('OCP\IAvatarManager'),
                    auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
                    secureRandom: $container->get('OCP\Security\ISecureRandom'),
                    db: $container->get('OCP\IDBConnection'),
                    l10nFactory: $container->get('OCP\L10N\IFactory')
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
                    appConfig: $container->get('OCP\IAppConfig'),
                    logger: $container->get('Psr\Log\LoggerInterface')
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
                    container: $container,
                    organisationMapper: $container->get(OrganisationMapper::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    appConfig: $container->get('OCP\IAppConfig')
                );
            }
        );

        $context->registerService(
            WebhookMapper::class,
            function (ContainerInterface $container) {
                return new WebhookMapper(
                    db: $container->get('OCP\IDBConnection'),
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

    }//end registerMappersWithCircularDependencies()

    /**
     * Register cache and file handling services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
     */
    private function registerCacheAndFileHandlers(IRegistrationContext $context): void
    {
        // CacheHandler uses lazy loading of IndexService to break circular dependency.
        $context->registerService(
            CacheHandler::class,
            function (ContainerInterface $container) {
                return new CacheHandler(
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
                    objectEntityMapper: $container->get(MagicMapper::class),
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
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
                    client: $container->get('OCP\Http\Client\IClientService')->newClient(),
                    appConfig: $container->get('OCP\IAppConfig'),
                    config: $container->get('OCP\IConfig'),
                    cacheFactory: $container->get('OCP\ICacheFactory'),
                    attributionFormatter: $container->get('OCA\OpenRegister\Service\Configuration\AttributionFormatter'),
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
                objectEntityMapper: $container->get(MagicMapper::class),
                configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
                mappingMapper: $container->get(MappingMapper::class),
                client: new Client(),
                appConfig: $container->get('OCP\IAppConfig'),
                logger: $logger,
                appDataPath: $appDataPath,
                uploadHandler: $container->get(ConfigurationUploadHandler::class),
                objectService: $container->get(ObjectService::class)
            );

            // Inject MagicMapper for pre-creating magic mapper tables before seed data import.
            $importHandler->setMagicMapper($container->get(MagicMapper::class));

            // Inject MagicMapper for routing seed data to correct magic table.
            $importHandler->setObjectMapper($container->get(MagicMapper::class));

            // Inject workflow dependencies for deploying workflows during import.
            $importHandler->setWorkflowEngineRegistry($container->get(WorkflowEngineRegistry::class));
            $importHandler->setDeployedWorkflowMapper($container->get(DeployedWorkflowMapper::class));

            // Optional: services used by seed-related-items to attach files /
            // notes / tasks. Wrapped in try/catch so a missing dependency
            // doesn't break import for apps that don't seed related items.
            try {
                $importHandler->setFileService($container->get(\OCA\OpenRegister\Service\FileService::class));
            } catch (\Throwable $e) {
                $logger->debug('[Application] FileService unavailable for ImportHandler: '.$e->getMessage());
            }

            try {
                $importHandler->setNoteService($container->get(\OCA\OpenRegister\Service\NoteService::class));
            } catch (\Throwable $e) {
                $logger->debug('[Application] NoteService unavailable for ImportHandler: '.$e->getMessage());
            }

            try {
                $importHandler->setTaskService($container->get(\OCA\OpenRegister\Service\TaskService::class));
            } catch (\Throwable $e) {
                $logger->debug('[Application] TaskService unavailable for ImportHandler: '.$e->getMessage());
            }

            try {
                $importHandler->setUserSession($container->get('OCP\IUserSession'));
            } catch (\Throwable $e) {
                $logger->debug('[Application] IUserSession unavailable for ImportHandler: '.$e->getMessage());
            }

            return $importHandler;
        };

        // Register under alias.
        $context->registerService(ConfigurationImportHandler::class, $importHandlerFactory);

        // Register under real class name (pointing to same factory).
        $context->registerService(
            'OCA\OpenRegister\Service\Configuration\ImportHandler',
            $importHandlerFactory
        );

        // Register ExportHandler with workflow dependencies.
        $context->registerService(
            ConfigurationExportHandler::class,
            function (ContainerInterface $container): ConfigurationExportHandler {
                $exportHandler = new ConfigurationExportHandler(
                    schemaMapper: $container->get(SchemaMapper::class),
                    registerMapper: $container->get(RegisterMapper::class),
                    objectEntityMapper: $container->get(MagicMapper::class),
                    configurationMapper: $container->get('OCA\OpenRegister\Db\ConfigurationMapper'),
                    mappingMapper: $container->get(MappingMapper::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );

                $exportHandler->setWorkflowEngineRegistry($container->get(WorkflowEngineRegistry::class));
                $exportHandler->setDeployedWorkflowMapper($container->get(DeployedWorkflowMapper::class));

                return $exportHandler;
            }
        );

        $context->registerService(
            ConfigurationService::class,
            function (ContainerInterface $container) {
                $dataDir     = $container->get('OCP\IConfig')->getSystemValue('datadirectory', '');
                $appDataPath = $dataDir.'/appdata_openregister';

                return new ConfigurationService(
                    schemaMapper: $container->get(SchemaMapper::class),
                    registerMapper: $container->get(RegisterMapper::class),
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
        $context->registerReferenceProvider(\OCA\OpenRegister\Reference\ObjectReferenceProvider::class);
        $context->registerCalendarProvider(\OCA\OpenRegister\Calendar\RegisterCalendarProvider::class);
    }//end registerConfigurationServices()

    /**
     * Register settings-related services including handlers.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
     */
    private function registerSettingsServices(IRegistrationContext $context): void
    {
        $context->registerService(
            ValidationOperationsHandler::class,
            function (ContainerInterface $container) {
                return new ValidationOperationsHandler(
                    validateHandler: null,
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

        // Register TenantKeyService for audit-trail HMAC key management.
        $context->registerService(
            TenantKeyService::class,
            function (ContainerInterface $container) {
                return new TenantKeyService(
                    db: $container->get('OCP\IDBConnection'),
                    crypto: $container->get('OCP\Security\ICrypto'),
                    logger: $container->get('Psr\Log\LoggerInterface')
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
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
     * Register task and note services for object interactions.
     *
     * TaskService wraps CalDAV VTODO operations, NoteService wraps Nextcloud Comments.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
     */
    private function registerObjectInteractionServices(IRegistrationContext $context): void
    {
        $context->registerService(
            TaskService::class,
            function (ContainerInterface $container) {
                return new TaskService(
                    calDavBackend: $container->get('OCA\DAV\CalDAV\CalDavBackend'),
                    userSession: $container->get('OCP\IUserSession'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        $context->registerService(
            NoteService::class,
            function (ContainerInterface $container) {
                return new NoteService(
                    commentsManager: $container->get('OCP\Comments\ICommentsManager'),
                    userSession: $container->get('OCP\IUserSession'),
                    userManager: $container->get('OCP\IUserManager'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );
    }//end registerObjectInteractionServices()

    /**
     * Register the IntegrationRegistry + ExternalIntegrationRouter
     * services used by the pluggable integration registry.
     *
     * Both are shared per-request singletons. Apps that ship their own
     * IntegrationProvider implementations register them via
     * `$this->container->get(IntegrationRegistry::class)->addProvider(...)`
     * from their own Application::register() hook — see
     * `openspec/changes/pluggable-integration-registry/proposal.md` (AD-1).
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-5
     */
    private function registerIntegrationRegistry(IRegistrationContext $context): void
    {
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\IntegrationRegistry::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\IntegrationRegistry(
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter(
                    appManager: $container->get('OCP\App\IAppManager'),
                    container: $container,
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        // PropertyReferenceTypeValidator — consumed by schema-property
        // validation paths that opt in to the new referenceType marker
        // (AD-18). Stays separate from the entity-level
        // validateLinkedTypesValue path so existing schemas keep
        // validating exactly as before.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator(
                    registry: $container->get(\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class),
                );
            }
        );

        // Repair step LogDanglingLinkedTypes — runs on install +
        // post-migration to surface schemas whose linkedTypes contain
        // ids that the registry can no longer resolve. Strictly
        // informational; never throws, never modifies data.
        $context->registerService(
            \OCA\OpenRegister\Repair\LogDanglingLinkedTypes::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Repair\LogDanglingLinkedTypes(
                    registry: $container->get(\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class),
                    container: $container,
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        $this->registerBuiltinIntegrationProviders($context);

        // IntegrationsController — read-only API over the registry.
        $context->registerService(
            \OCA\OpenRegister\Controller\IntegrationsController::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Controller\IntegrationsController(
                    appName: 'openregister',
                    request: $container->get('OCP\IRequest'),
                    registry: $container->get(\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager'),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        // ObjectIntegrationsController — object-scoped sub-resource
        // dispatch through the registry.
        $context->registerService(
            \OCA\OpenRegister\Controller\ObjectIntegrationsController::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Controller\ObjectIntegrationsController(
                    appName: 'openregister',
                    request: $container->get('OCP\IRequest'),
                    registry: $container->get(\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class),
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );

        // IntegrationsAdminSettings is declared in info.xml <admin> and
        // resolved by Nextcloud's container — IntegrationRegistry +
        // ExternalIntegrationRouter are already registered above and the
        // remaining constructor deps (IAppManager / IURLGenerator /
        // IL10N) are framework services NC autowires. No explicit
        // registerService needed, mirroring OpenRegisterAdmin.

        // IntegrationsCapability — surfaces the registry through the
        // Nextcloud OCS capabilities endpoint, role-redacted per AD-17.
        $context->registerService(
            \OCA\OpenRegister\Capabilities\IntegrationsCapability::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Capabilities\IntegrationsCapability(
                    registry: $container->get(\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class),
                    userSession: $container->get('OCP\IUserSession'),
                    groupManager: $container->get('OCP\IGroupManager')
                );
            }
        );

    }//end registerIntegrationRegistry()

    /**
     * Register the 5 BuiltinProviders/* services so they can be
     * resolved lazily from the container.
     *
     * Each provider wraps an existing OR service and exposes it
     * through the IntegrationProvider contract. They self-register
     * with the IntegrationRegistry during `boot()` —
     * `bootBuiltinIntegrationProviders()` walks this same list.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-12
     */
    private function registerBuiltinIntegrationProviders(IRegistrationContext $context): void
    {
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider(
                    fileService: $container->get(\OCA\OpenRegister\Service\FileService::class),
                    container: $container,
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider(
                    noteService: $container->get(\OCA\OpenRegister\Service\NoteService::class),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider(
                    taskService: $container->get(\OCA\OpenRegister\Service\TaskService::class),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider(
                    tagManager: $container->get('OCP\SystemTag\ISystemTagManager'),
                    objectMapper: $container->get('OCP\SystemTag\ISystemTagObjectMapper'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        $context->registerService(
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider(
                    mapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        // Leaf provider: XWiki (external, OpenConnector-backed). Ships
        // in-repo as the worked external-storage example; routed
        // through ExternalIntegrationRouter, credentials on the
        // OpenConnector `xwiki` source.
        // @spec openspec/changes/integration-xwiki/tasks.md.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\Providers\XwikiProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\Providers\XwikiProvider(
                    router: $container->get(\OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class),
                    appManager: $container->get('OCP\App\IAppManager'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        // Leaf provider: OpenProject (external, OpenConnector-backed) —
        // mirrors the XwikiProvider pattern (AD-4 / AD-22). Credentials
        // on the OpenConnector `openproject` source.
        // @spec openspec/changes/integration-openproject/tasks.md.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider(
                    router: $container->get(\OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class),
                    appManager: $container->get('OCP\App\IAppManager'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        // Leaf providers — NC-native, "backend already shipped":
        // wrap the existing OR services (Calendar, Contacts, Deck, Email)
        // through the registry contract so they surface in the sidebar /
        // widgets / admin UI / OCS caps without per-app glue. Each
        // provider gates on its required NC app via IAppManager.
        // @spec openspec/changes/integration-calendar/tasks.md.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\Providers\CalendarProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\Providers\CalendarProvider(
                    calendarEventService: $container->get(\OCA\OpenRegister\Service\CalendarEventService::class),
                    appManager: $container->get('OCP\App\IAppManager'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        // @spec openspec/changes/integration-contacts/tasks.md.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\Providers\ContactsProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\Providers\ContactsProvider(
                    contactService: $container->get(\OCA\OpenRegister\Service\ContactService::class),
                    appManager: $container->get('OCP\App\IAppManager'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        // @spec openspec/changes/integration-deck/tasks.md.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\Providers\DeckProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\Providers\DeckProvider(
                    deckCardService: $container->get(\OCA\OpenRegister\Service\DeckCardService::class),
                    appManager: $container->get('OCP\App\IAppManager'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        // @spec openspec/changes/integration-email/tasks.md.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\Providers\EmailProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\Providers\EmailProvider(
                    emailService: $container->get(\OCA\OpenRegister\Service\EmailService::class),
                    appManager: $container->get('OCP\App\IAppManager'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );

        // Leaf providers — NC-app-backed greenfield. Each registers the
        // registry surface (sidebar slot + admin UI presence + OCS caps
        // entry) gated on the named NC app. The wrapped read service +
        // link table land in per-leaf follow-up PRs; until then `list()`
        // returns an empty array so the slot renders an empty state
        // rather than a 500.
        $greenfieldProviders = [
            // @spec openspec/changes/integration-activity/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\ActivityProvider::class,
            // @spec openspec/changes/integration-analytics/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider::class,
            // @spec openspec/changes/integration-bookmarks/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider::class,
            // @spec openspec/changes/integration-collectives/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider::class,
            // @spec openspec/changes/integration-cospend/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\CospendProvider::class,
            // @spec openspec/changes/integration-flow/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\FlowProvider::class,
            // @spec openspec/changes/integration-forms/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\FormsProvider::class,
            // @spec openspec/changes/integration-maps/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\MapsProvider::class,
            // @spec openspec/changes/integration-photos/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\PhotosProvider::class,
            // @spec openspec/changes/integration-polls/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\PollsProvider::class,
            // @spec openspec/changes/integration-talk/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\TalkProvider::class,
            // @spec openspec/changes/integration-time-tracker/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\TimeProvider::class,
        ];
        foreach ($greenfieldProviders as $providerClass) {
            $context->registerService(
                $providerClass,
                function (ContainerInterface $container) use ($providerClass) {
                    return new $providerClass(
                        appManager: $container->get('OCP\App\IAppManager'),
                        l10n: $container->get('OCP\IL10N'),
                    );
                }
            );
        }

        // SharesProvider takes the Share Manager rather than IAppManager —
        // shares are NC core (always-available) so the required-app gate
        // is moot, but `delete()` delegates to IManager::deleteShare().
        // @spec openspec/changes/integration-shares/tasks.md.
        $context->registerService(
            \OCA\OpenRegister\Service\Integration\Providers\SharesProvider::class,
            function (ContainerInterface $container) {
                return new \OCA\OpenRegister\Service\Integration\Providers\SharesProvider(
                    shareManager: $container->get('OCP\Share\IManager'),
                    l10n: $container->get('OCP\IL10N'),
                );
            }
        );
    }//end registerBuiltinIntegrationProviders()

    /**
     * Register all event listeners for the application.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
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

        // Lifecycle annotation listeners — see x-openregister-lifecycle.
        // Order matters: initial state runs on creating; validation runs on updating.
        $context->registerEventListener(ObjectCreatingEvent::class, LifecycleInitialStateListener::class);
        $context->registerEventListener(ObjectUpdatingEvent::class, LifecycleValidationListener::class);

        // Calculations annotation listener — materialises declared calculations
        // into the object payload before persistence (see x-openregister-calculations).
        $context->registerEventListener(ObjectCreatingEvent::class, CalculationOnSaveListener::class);
        $context->registerEventListener(ObjectUpdatingEvent::class, CalculationOnSaveListener::class);

        // Notifications annotation listener — fires INotificationManager
        // notifications declared on the schema's x-openregister-notifications.
        $context->registerEventListener(ObjectCreatedEvent::class, AnnotationNotificationListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, AnnotationNotificationListener::class);
        $context->registerEventListener(ObjectTransitionedEvent::class, AnnotationNotificationListener::class);

        // Aggregation cache eviction on every object write.
        $context->registerEventListener(ObjectCreatedEvent::class, AggregationCacheInvalidationListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, AggregationCacheInvalidationListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, AggregationCacheInvalidationListener::class);

        // Realtime event log — append-only CloudEvent records for SSE/polling clients.
        $context->registerEventListener(ObjectCreatedEvent::class,      RealtimeEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class,      RealtimeEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class,      RealtimeEventListener::class);
        $context->registerEventListener(ObjectTransitionedEvent::class, RealtimeEventListener::class);

        // Translation sidecar projection — keeps oc_openregister_translations in sync with JSONB property data.
        $context->registerEventListener(ObjectCreatedEvent::class,      TranslationProjectionListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class,      TranslationProjectionListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class,      TranslationProjectionListener::class);
        $context->registerEventListener(ObjectTransitionedEvent::class, TranslationProjectionListener::class);
        $context->registerEventListener(ObjectTransitionedEvent::class, AggregationCacheInvalidationListener::class);

        // Webhook auto-create installer for x-openregister-notifications with webhook.persistent: true.
        $context->registerEventListener(SchemaCreatedEvent::class, NotificationsAnnotationInstaller::class);
        $context->registerEventListener(SchemaUpdatedEvent::class, NotificationsAnnotationInstaller::class);

        // Threshold trigger evaluator: re-runs aggregations on writes and dispatches when thresholds are crossed.
        $context->registerEventListener(ObjectCreatedEvent::class, AggregationThresholdListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, AggregationThresholdListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, AggregationThresholdListener::class);
        $context->registerEventListener(ObjectTransitionedEvent::class, AggregationThresholdListener::class);

        // HookListener for schema hook execution on lifecycle events.
        $context->registerEventListener(ObjectCreatingEvent::class, HookListener::class);
        $context->registerEventListener(ObjectUpdatingEvent::class, HookListener::class);
        $context->registerEventListener(ObjectDeletingEvent::class, HookListener::class);
        $context->registerEventListener(ObjectCreatedEvent::class, HookListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, HookListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, HookListener::class);

        // WebhookEventListener for webhook delivery.
        $context->registerEventListener(ObjectCreatedEvent::class, WebhookEventListener::class);

        // GraphQL subscription event listeners.
        $context->registerEventListener(ObjectCreatedEvent::class, GraphQLSubscriptionListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, GraphQLSubscriptionListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, GraphQLSubscriptionListener::class);

        // Notify_push real-time push listeners (soft-fail when notify_push not installed).
        $context->registerEventListener(ObjectCreatedEvent::class, NotifyPushListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, NotifyPushListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, NotifyPushListener::class);

        // FilesSidebarListener injects the sidebar tab script into the Files app.
        $context->registerEventListener('OCA\Files\Event\LoadAdditionalScriptsEvent', FilesSidebarListener::class);

        // MailAppScriptListener injects the mail sidebar when schemas have linkedTypes: ["mail"].
        $context->registerEventListener(
            \OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
            \OCA\OpenRegister\Listener\MailAppScriptListener::class
        );

        // CommentsEntityListener registers "openregister" objectType for Nextcloud Comments.
        $context->registerEventListener(CommentsEntityEvent::class, CommentsEntityListener::class);

        // ObjectCleanupListener cleans up notes and tasks when an object is deleted.
        $context->registerEventListener(ObjectDeletedEvent::class, ObjectCleanupListener::class);

        // ActivityEventListener publishes Nextcloud Activity events for entity lifecycle.
        $activityListener = \OCA\OpenRegister\Listener\ActivityEventListener::class;
        $context->registerEventListener(ObjectCreatedEvent::class, $activityListener);
        $context->registerEventListener(ObjectUpdatedEvent::class, $activityListener);
        $context->registerEventListener(ObjectDeletedEvent::class, $activityListener);
        $context->registerEventListener(RegisterCreatedEvent::class, $activityListener);
        $context->registerEventListener(RegisterUpdatedEvent::class, $activityListener);
        $context->registerEventListener(RegisterDeletedEvent::class, $activityListener);
        $context->registerEventListener(SchemaCreatedEvent::class, $activityListener);
        $context->registerEventListener(SchemaUpdatedEvent::class, $activityListener);
        $context->registerEventListener(SchemaDeletedEvent::class, $activityListener);
    }//end registerEventListeners()

    /**
     * Register MCP tool providers (built-ins first).
     *
     * Wires the three built-in IMcpToolProvider implementations into
     * McpToolsService. External apps may call addProvider() after boot
     * or override the McpToolsService binding to prepend their own providers.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-orchestrator/specs/chat-ai/spec.md#mcptoolsservice-provider-discovery-refactor
     */
    private function registerMcpToolProviders(IRegistrationContext $context): void
    {
        $context->registerService(
            McpToolsService::class,
            function (ContainerInterface $container) {
                $providers = [
                    $container->get(RegistersToolProvider::class),
                    $container->get(SchemasToolProvider::class),
                    $container->get(ObjectsToolProvider::class),
                ];

                return new McpToolsService(
                    providers: $providers,
                    logger: $container->get('Psr\Log\LoggerInterface')
                );
            }
        );
    }//end registerMcpToolProviders()

    /**
     * Boot application components
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-24
     */
    public function boot(IBootContext $context): void
    {
        // Dispatch the deep link registration event so consuming apps
        // (Procest, Pipelinq, etc.) can register their URL patterns.
        // DeepLinkRegistryService uses ContainerInterface for lazy mapper
        // resolution, so no circular DI issues during registration.
        $server     = $context->getServerContainer();
        $dispatcher = $server->get(IEventDispatcher::class);
        $registry   = $server->get(DeepLinkRegistryService::class);
        $dispatcher->dispatchTyped(new DeepLinkRegistrationEvent(registry: $registry));

        // Register the built-in IntegrationProvider implementations
        // with the IntegrationRegistry. The 5 wrap existing services
        // (FileService / NoteService / TaskService / system tag manager /
        // AuditTrailMapper) and surface them through the unified
        // registry contract. Each provider is constructed lazily — the
        // registry never touches a provider's wrapped service unless a
        // caller actually invokes that provider's CRUD path.
        $this->bootBuiltinIntegrationProviders($server);
    }//end boot()

    /**
     * Resolve every BuiltinProviders/* class and register it with the
     * shared IntegrationRegistry.
     *
     * Kept separate from the DI registration in
     * `registerIntegrationRegistry()` because addProvider() needs the
     * registry instance — i.e. it has to run after the container has
     * fully bootstrapped. `boot()` is the canonical post-registration
     * hook for that.
     *
     * @param mixed $server Server container (passed in from boot()).
     *
     * @return void
     *
     * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-17
     */
    private function bootBuiltinIntegrationProviders($server): void
    {
        try {
            $integrationRegistry = $server->get(
                \OCA\OpenRegister\Service\Integration\IntegrationRegistry::class
            );
        } catch (\Throwable $e) {
            // Registry binding not available — skip silently; the
            // service would log its own warning at use-time anyway.
            return;
        }

        $providerClasses = [
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider::class,
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider::class,
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider::class,
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider::class,
            \OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider::class,
            // Leaves: external (OpenConnector-backed).
            // @spec openspec/changes/integration-xwiki/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\XwikiProvider::class,
            // @spec openspec/changes/integration-openproject/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider::class,
            // Leaves: NC-native, backend-shipped (wrap existing OR services).
            // @spec openspec/changes/integration-calendar/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\CalendarProvider::class,
            // @spec openspec/changes/integration-contacts/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\ContactsProvider::class,
            // @spec openspec/changes/integration-deck/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\DeckProvider::class,
            // @spec openspec/changes/integration-email/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\EmailProvider::class,
            // Leaves: NC-app-backed greenfield (registry surface only;
            // service + link table land in per-leaf follow-ups).
            // @spec openspec/changes/integration-activity/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\ActivityProvider::class,
            // @spec openspec/changes/integration-analytics/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider::class,
            // @spec openspec/changes/integration-bookmarks/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider::class,
            // @spec openspec/changes/integration-collectives/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider::class,
            // @spec openspec/changes/integration-cospend/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\CospendProvider::class,
            // @spec openspec/changes/integration-flow/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\FlowProvider::class,
            // @spec openspec/changes/integration-forms/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\FormsProvider::class,
            // @spec openspec/changes/integration-maps/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\MapsProvider::class,
            // @spec openspec/changes/integration-photos/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\PhotosProvider::class,
            // @spec openspec/changes/integration-polls/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\PollsProvider::class,
            // @spec openspec/changes/integration-shares/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\SharesProvider::class,
            // @spec openspec/changes/integration-talk/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\TalkProvider::class,
            // @spec openspec/changes/integration-time-tracker/tasks.md.
            \OCA\OpenRegister\Service\Integration\Providers\TimeProvider::class,
        ];

        foreach ($providerClasses as $providerClass) {
            try {
                $provider = $server->get($providerClass);
                $integrationRegistry->addProvider($provider);
            } catch (\Throwable $e) {
                // Provider construction can fail if a wrapped service
                // is missing on this NC build — don't take the whole
                // app down for one absent provider. The user-facing
                // surface will simply not show the failing tab.
            }
        }
    }//end bootBuiltinIntegrationProviders()
}//end class
