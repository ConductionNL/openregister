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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppInfo;

use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCA\OpenRegister\Service\ObjectHandlers\DeleteObject;
use OCA\OpenRegister\Service\ObjectHandlers\GetObject;
use OCA\OpenRegister\Service\ObjectHandlers\RenderObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObjects;
use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use OCA\OpenRegister\Service\ObjectHandlers\PublishObject;
use OCA\OpenRegister\Service\ObjectHandlers\DepublishObject;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\FacetService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\SolrService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SolrSchemaService;
use OCA\OpenRegister\Setup\SolrSetup;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Command\SolrDebugCommand;
use OCA\OpenRegister\Command\SolrManagementCommand;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\BackgroundJob\SolrWarmupJob;
use OCA\OpenRegister\BackgroundJob\SolrNightlyWarmupJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

use OCA\OpenRegister\EventListener\TestEventListener;
use OCA\OpenRegister\EventListener\SolrEventListener;
use OCP\User\Events\UserLoggedInEvent;
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
     * @psalm-suppress PossiblyUnusedMethod
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

        // @TODO: Usually, services are autowired. Les figure out why we need to do this
        // Register SearchTrail components
        $context->registerService(
                SearchTrailMapper::class,
                function ($container) {
                    return new SearchTrailMapper(
                    $container->get('OCP\IDBConnection'),
                    $container->get('OCP\IRequest'),
                    $container->get('OCP\IUserSession')
                    );
                }
                );

        $context->registerService(
                SearchTrailService::class,
                function ($container) {
                    return new SearchTrailService(
                    $container->get(SearchTrailMapper::class),
                    $container->get(RegisterMapper::class),
                    $container->get(SchemaMapper::class)
                    );
                }
                );

        // Register OrganisationMapper (event dispatching removed - handled by cron job)
        // $context->registerService(OrganisationMapper::class, function ($container) {
        // return new OrganisationMapper(
        // $container->get('OCP\IDBConnection')
        // );
        // });
        // Register ObjectEntityMapper with IGroupManager and IUserManager dependencies
        $context->registerService(
                ObjectEntityMapper::class,
                function ($container) {
                    return new ObjectEntityMapper(
                    $container->get('OCP\IDBConnection'),
                    $container->get(MySQLJsonService::class),
                    $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    $container->get('OCP\IUserSession'),
                    $container->get(SchemaMapper::class),
                    $container->get('OCP\IGroupManager'),
                    $container->get('OCP\IUserManager'),
                    $container->get('OCP\IAppConfig'),
                    $container->get('Psr\Log\LoggerInterface'),
                    null // AuthorizationExceptionService
                    );
                }
                );

        // Register SolrService for advanced search capabilities (disabled due to performance issues)
        // Issue: Even with lazy loading, DI registration causes performance problems
        /*
        $context->registerService(
                SolrService::class,
                function ($container) {
                    return new SolrService(
                    $container->get(SettingsService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get(ObjectEntityMapper::class),
                    $container->get('OCP\IConfig')
                    );
                }
                );
        */

        // Register ObjectCacheService for performance optimization with lightweight SOLR
        $context->registerService(
                ObjectCacheService::class,
                function ($container) {
                    // Break circular dependency by lazy-loading GuzzleSolrService
                    $solrService = null;
                    try {
                        $solrService = $container->get(GuzzleSolrService::class);
                    } catch (\Exception $e) {
                        // If GuzzleSolrService is not available, continue without it
                        $solrService = null;
                    }
                    
                    return new ObjectCacheService(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(OrganisationMapper::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $solrService, // Lightweight SOLR service enabled!
                    $container->get('OCP\ICacheFactory'),
                    $container->get('OCP\IUserSession')
                    );
                }
                );

        // Register FacetService for centralized faceting operations
        $context->registerService(
                FacetService::class,
                function ($container) {
                    return new FacetService(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(SchemaMapper::class),
                    $container->get(RegisterMapper::class),
                    $container->get('OCP\ICacheFactory'),
                    $container->get('OCP\IUserSession'),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );


        // Register SaveObject with consolidated cache services
        $context->registerService(
                SaveObject::class,
                function ($container) {
                    return new SaveObject(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(FileService::class),
                    $container->get('OCP\IUserSession'),
                    $container->get('OCA\OpenRegister\Db\AuditTrailMapper'),
                    $container->get(SchemaMapper::class),
                    $container->get(RegisterMapper::class),
                    $container->get('OCP\IURLGenerator'),
                    $container->get(OrganisationService::class),
                    $container->get(ObjectCacheService::class),
                    $container->get(SchemaCacheService::class),
                    $container->get(SchemaFacetCacheService::class),
                    $container->get(SettingsService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    new \Twig\Loader\ArrayLoader([])
                    );
                }
                );

        // Register DeleteObject with consolidated cache services
        $context->registerService(
                DeleteObject::class,
                function ($container) {
                    return new DeleteObject(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(FileService::class),
                    $container->get(ObjectCacheService::class),
                    $container->get(SchemaCacheService::class),
                    $container->get(SchemaFacetCacheService::class),
                    $container->get('OCA\OpenRegister\Db\AuditTrailMapper'),
                    $container->get(SettingsService::class),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register GetObject with SettingsService dependency
        $context->registerService(
                GetObject::class,
                function ($container) {
                    return new GetObject(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(FileService::class),
                    $container->get('OCA\OpenRegister\Db\AuditTrailMapper'),
                    $container->get(SettingsService::class)
                    );
                }
                );

        // Register RenderObject with LoggerInterface dependency
        $context->registerService(
                RenderObject::class,
                function ($container) {
                    return new RenderObject(
                    $container->get('OCP\IURLGenerator'),
                    $container->get('OCA\OpenRegister\Db\FileMapper'),
                    $container->get('OCA\OpenRegister\Service\FileService'),
                    $container->get(ObjectEntityMapper::class),
                    $container->get('OCA\OpenRegister\Db\RegisterMapper'),
                    $container->get('OCA\OpenRegister\Db\SchemaMapper'),
                    $container->get('OCP\SystemTag\ISystemTagManager'),
                    $container->get('OCP\SystemTag\ISystemTagObjectMapper'),
                    $container->get(ObjectCacheService::class),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register OrganisationService with IConfig and IGroupManager dependencies
        $context->registerService(
                OrganisationService::class,
                function ($container) {
                    return new OrganisationService(
                    $container->get(OrganisationMapper::class),
                    $container->get('OCP\IUserSession'),
                    $container->get('OCP\ISession'),
                    $container->get('OCP\IConfig'),
                    $container->get('OCP\IGroupManager'),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register SaveObjects handler with dependencies
        $context->registerService(
                SaveObjects::class,
                function ($container) {
                    return new SaveObjects(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(SchemaMapper::class),
                    $container->get(RegisterMapper::class),
                    $container->get(SaveObject::class),
                    $container->get(ValidateObject::class),
                    $container->get('OCP\IUserSession'),
                    $container->get(OrganisationService::class),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register ObjectService with IGroupManager, IUserManager and LoggerInterface dependencies
        $context->registerService(
                ObjectService::class,
                function ($container) {
                    return new ObjectService(
                    $container->get(DeleteObject::class),
                    $container->get(GetObject::class),
                    $container->get(RenderObject::class),
                    $container->get(SaveObject::class),
                    $container->get(SaveObjects::class),
                    $container->get(ValidateObject::class),
                    $container->get(PublishObject::class),
                    $container->get(DepublishObject::class),
                    $container->get(RegisterMapper::class),
                    $container->get(SchemaMapper::class),
                    $container->get(ObjectEntityMapper::class),
                    $container->get(FileService::class),
                    $container->get('OCP\IUserSession'),
                    $container->get(SearchTrailService::class),
                    $container->get('OCP\IGroupManager'),
                    $container->get('OCP\IUserManager'),
                    $container->get(OrganisationService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get(FacetService::class),
                    $container->get(ObjectCacheService::class),
                    $container->get(SchemaCacheService::class),
                    $container->get(SchemaFacetCacheService::class),
                    $container->get(SettingsService::class),
                    $container
                    );
                }
                );

        // Register ImportService with IUserManager, IGroupManager, and IJobList dependencies
        $context->registerService(
                ImportService::class,
                function ($container) {
                    return new ImportService(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(SchemaMapper::class),
                    $container->get(ObjectService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get('OCP\IUserManager'),
                    $container->get('OCP\IGroupManager'),
                    $container->get('OCP\BackgroundJob\IJobList')
                    );
                }
                );

        // Register ExportService with IUserManager and IGroupManager dependencies
        $context->registerService(
                ExportService::class,
                function ($container) {
                    return new ExportService(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(RegisterMapper::class),
                    $container->get('OCP\IUserManager'),
                    $container->get('OCP\IGroupManager'),
                    $container->get(ObjectService::class)
                    );
                }
                );



        // Register TestEventListener for verifying event system works
        $context->registerService(
                TestEventListener::class,
                function ($container) {
                    return new TestEventListener(
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register SolrEventListener for automatic Solr indexing
        $context->registerService(
                SolrEventListener::class,
                function ($container) {
                    return new SolrEventListener(
                    $container->get(ObjectCacheService::class),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register SchemaCacheService for improved schema performance
        $context->registerService(
                SchemaCacheService::class,
                function ($container) {
                    return new SchemaCacheService(
                    $container->get('OCP\IDBConnection'),
                    $container->get(SchemaMapper::class),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register SchemaFacetCacheService for predictable facet caching
        $context->registerService(
                SchemaFacetCacheService::class,
                function ($container) {
                    return new SchemaFacetCacheService(
                    $container->get('OCP\IDBConnection'),
                    $container->get(SchemaMapper::class),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register ObjectsProvider for Nextcloud search integration
        $context->registerService(
                ObjectsProvider::class,
                function ($container) {
                    return new ObjectsProvider(
                    $container->get('OCP\IL10N'),
                    $container->get('OCP\IURLGenerator'),
                    $container->get(ObjectService::class),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register ObjectsProvider as a search provider for Nextcloud search
        $context->registerSearchProvider(ObjectsProvider::class);

        // Register SolrDebugCommand for SOLR debugging
        $context->registerService(
                SolrDebugCommand::class,
                function ($container) {
                    return new SolrDebugCommand(
                    $container->get(SettingsService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get('OCP\IConfig')
                    );
                }
                );

        // Register lightweight GuzzleSolrService directly (no factory needed!)
        $context->registerService(
                GuzzleSolrService::class,
                function ($container) {
                    return new GuzzleSolrService(
                    $container->get(SettingsService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get('OCP\Http\Client\IClientService'),
                    $container->get('OCP\IConfig'),
                    $container->get(SchemaMapper::class), // Add SchemaMapper for schema-aware mapping
                    $container->get(RegisterMapper::class), // Add RegisterMapper for register access
                    $container->get(OrganisationService::class), // Add OrganisationService for multi-tenancy
                    $container->get(OrganisationMapper::class) // Add OrganisationMapper for organisation label resolution
                    // Note: RenderObject removed to avoid circular dependency with ObjectCacheService
                    // SolrSchemaService will be resolved lazily to avoid circular dependency
                    );
                }
                );

        // Register SolrSchemaService for SOLR schema operations
        $context->registerService(
                SolrSchemaService::class,
                function ($container) {
                    return new SolrSchemaService(
                    $container->get(SchemaMapper::class),
                    $container->get(GuzzleSolrService::class),
                    $container->get(SettingsService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get('OCP\IConfig')
                    );
                }
                );

        // Register SolrManagementCommand for production SOLR operations
        $context->registerService(
                SolrManagementCommand::class,
                function ($container) {
                    return new SolrManagementCommand(
                    $container->get(SettingsService::class),
                    $container->get('Psr\Log\LoggerInterface'),
                    $container->get(GuzzleSolrService::class),
                    $container->get(SolrSchemaService::class),
                    $container->get('OCP\IConfig')
                    );
                }
                );

        // Register TEST event listener for easily triggerable Nextcloud events
        $context->registerEventListener(UserLoggedInEvent::class, TestEventListener::class);

        // Register Solr event listeners for automatic indexing
        $context->registerEventListener(ObjectCreatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(ObjectUpdatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(ObjectDeletedEvent::class, SolrEventListener::class);
        
        // Register Solr event listeners for schema lifecycle management
        $context->registerEventListener(SchemaCreatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(SchemaUpdatedEvent::class, SolrEventListener::class);
        $context->registerEventListener(SchemaDeletedEvent::class, SolrEventListener::class);

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
        // Register event listeners for testing and functionality
        $container = $context->getAppContainer();
        $eventDispatcher = $container->get(IEventDispatcher::class);
        $logger = $container->get('Psr\Log\LoggerInterface');
        
        // Log boot process
        $logger->info('OpenRegister boot: Registering event listeners', [
            'app' => 'openregister',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        try {

            
            // Register test event listener for UserLoggedInEvent
            $eventDispatcher->addServiceListener(UserLoggedInEvent::class, TestEventListener::class);
            
            $logger->info('OpenRegister boot: Event listeners registered successfully');
            
            // Register recurring SOLR nightly warmup job
            $jobList = $container->get('OCP\BackgroundJob\IJobList');
            
            // Check if the nightly warmup job is already registered
            if (!$jobList->has(SolrNightlyWarmupJob::class, null)) {
                $jobList->add(SolrNightlyWarmupJob::class);
                $logger->info('🌙 SOLR Nightly Warmup Job registered successfully', [
                    'job_class' => SolrNightlyWarmupJob::class,
                    'interval' => '24 hours (daily at 00:00)'
                ]);
            } else {
                $logger->debug('SOLR Nightly Warmup Job already registered');
            }
            
        } catch (\Exception $e) {
            $logger->error('OpenRegister boot: Failed to register event listeners and background jobs', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

    }//end boot()


}//end class
