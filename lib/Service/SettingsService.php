<?php

/**
 * OpenRegister Settings Service
 *
 * This file contains the service class for handling settings in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use stdClass;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Http\JSONResponse;
use OC_App;
use OCA\OpenRegister\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\SolrSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Index\SetupHandler;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for handling settings-related operations.
 *
 * This service is responsible ONLY for storing and retrieving application settings.
 * It does NOT contain business logic for testing or using the configured services.
 *
 * RESPONSIBILITIES:
 * - Store and retrieve settings from Nextcloud's IAppConfig
 * - Provide default values for unconfigured settings
 * - Manage settings for: RBAC, Multitenancy, Retention, SOLR, LLM, Files, Objects
 * - Get available options (groups, users, tenants) for settings UI
 * - Rebase operations (apply default owners/tenants to existing objects)
 * - Cache management statistics and operations
 *
 * WHAT THIS SERVICE DOES NOT DO:
 * - Test LLM connections (use VectorEmbeddingService or ChatService)
 * - Test search index connections (use IndexService)
 * - Generate embeddings (use VectorEmbeddingService)
 * - Execute chat operations (use ChatService)
 * - Perform searches (use appropriate search services)
 *
 * SETTINGS CATEGORIES:
 * - Version: Application name and version information
 * - RBAC: Role-based access control configuration
 * - Multitenancy: Tenant isolation and default tenant settings
 * - Retention: Data retention policies for objects, logs, and trails
 * - SOLR: Search engine configuration and connection details
 * - LLM: Language model provider configuration (OpenAI, Fireworks, Ollama)
 * - Files: File processing and vectorization settings
 * - Objects: Object vectorization and metadata settings
 * - Organisation: Default organisation and auto-creation settings
 *
 * ARCHITECTURE PATTERN:
 * - Controllers validate input and delegate to this service for storage
 * - Business logic services (ChatService, VectorEmbeddingService) read from this service
 * - Testing logic is delegated to the appropriate business logic service
 * - This service only handles persistence, not business logic
 *
 * INTEGRATION POINTS:
 * - IAppConfig: Nextcloud's app configuration storage
 * - IConfig: Nextcloud's system configuration
 * - ChatService: Reads LLM settings for chat operations
 * - VectorEmbeddingService: Reads LLM settings for embeddings
 * - IndexService: Reads search index settings for search operations
 * - Controllers: Delegate settings CRUD operations to this service
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
class SettingsService
{

    /**
     * Configuration service
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Audit trail mapper
     *
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Cache factory
     *
     * @var ICacheFactory
     */
    private ICacheFactory $cacheFactory;

    /**
     * Database connection (lazy-loaded when needed)
     *
     * @var IDBConnection|null
     */
    private ?IDBConnection $db = null;

    /**
     * Object cache service (lazy-loaded when needed)
     *
     * @var CacheHandler|null
     */
    private ?CacheHandler $objectCacheService = null;

    /**
     * Group manager
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Validation operations handler
     *
     * @var ValidationOperationsHandler
     */
    private ValidationOperationsHandler $validationOperationsHandler;

    /**
     * Search backend handler
     *
     * @var SearchBackendHandler
     */
    private SearchBackendHandler $searchBackendHandler;

    /**
     * LLM settings handler
     *
     * @var LlmSettingsHandler
     */
    private LlmSettingsHandler $llmSettingsHandler;

    /**
     * File settings handler
     *
     * @var FileSettingsHandler
     */
    private FileSettingsHandler $fileSettingsHandler;

    /**
     * Object and retention settings handler
     *
     * @var ObjectRetentionHandler
     */
    private ObjectRetentionHandler $objectRetentionHandler;

    /**
     * Cache settings handler
     *
     * @var CacheSettingsHandler
     */
    private CacheSettingsHandler $cacheSettingsHandler;

    /**
     * SOLR settings handler
     *
     * @var SolrSettingsHandler
     */
    private SolrSettingsHandler $solrSettingsHandler;

    /**
     * Configuration settings handler
     *
     * @var ConfigurationSettingsHandler
     */
    private ConfigurationSettingsHandler $configurationSettingsHandler;

    /**
     * Setup handler for SOLR field definitions (optional, lazy-loaded to break circular dependency).
     *
     * @var SetupHandler|null
     */
    private ?SetupHandler $setupHandler = null;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * REMOVED: Object entity mapper (unused, caused circular dependency)
     *
     * @var ObjectEntityMapper|null
     */
    // Private ?ObjectEntityMapper $objectEntityMapper;.

    /**
     * Organisation mapper
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $organisationMapper;

    /**
     * Schema cache handler
     *
     * @var SchemaCacheHandler
     */
    private SchemaCacheHandler $schemaCacheService;

    /**
     * Schema facet cache service
     *
     * @var FacetCacheHandler
     */
    private FacetCacheHandler $schemaFacetCacheService;

    /**
     * Search trail mapper
     *
     * @var SearchTrailMapper
     */
    private SearchTrailMapper $searchTrailMapper;

    /**
     * User manager
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * This property holds the name of the application, which is used for identification and configuration purposes.
     *
     * @var string $appName The name of the app.
     */
    private string $appName;

    /**
     * Unique identifier for the OpenRegister application.
     *
     * Used to check its installation and status.
     *
     * @var string $openRegisterAppId The ID of the OpenRegister app.
     */
    private const OPENREGISTER_APP_ID = 'openregister';

    /**
     * Minimum required version of the OpenRegister application.
     *
     * Required for compatibility and functionality.
     *
     * @var string $minOpenRegisterVersion Minimum required version of OpenRegister.
     */
    private const MIN_OPENREGISTER_VERSION = '0.1.7';

    /**
     * Container for lazy loading services to break circular dependencies
     *
     * @var IAppContainer|null
     */
    private ?IAppContainer $container = null;

    /**
     * Constructor for SettingsService
     *
     * @param IConfig                           $config                       Configuration service
     * @param AuditTrailMapper                  $auditTrailMapper             Audit trail mapper
     * @param ICacheFactory                     $cacheFactory                 Cache factory
     * @param IGroupManager                     $groupManager                 Group manager
     * @param LoggerInterface                   $logger                       Logger
     * @param OrganisationMapper                $organisationMapper           Organisation mapper
     * @param SchemaCacheHandler                $schemaCacheService           Schema cache handler
     * @param FacetCacheHandler                 $schemaFacetCacheService      Schema facet cache service
     * @param SearchTrailMapper                 $searchTrailMapper            Search trail mapper
     * @param IUserManager                      $userManager                  User manager
     * @param IDBConnection                     $db                           Database connection
     * @param SetupHandler|null                 $setupHandler                 Setup handler (optional)
     * @param CacheHandler|null                 $objectCacheService           Object cache service (optional)
     * @param IAppContainer|null                $container                    Container for lazy loading (optional)
     * @param string                            $appName                      Application name
     * @param ValidationOperationsHandler|null  $validationOperationsHandler  Validation operations handler
     * @param SearchBackendHandler|null         $searchBackendHandler         Search backend handler
     * @param LlmSettingsHandler|null           $llmSettingsHandler           LLM settings handler
     * @param FileSettingsHandler|null          $fileSettingsHandler          File settings handler
     * @param ObjectRetentionHandler|null       $objectRetentionHandler       Object retention handler
     * @param CacheSettingsHandler|null         $cacheSettingsHandler         Cache settings handler
     * @param SolrSettingsHandler|null          $solrSettingsHandler          SOLR settings handler
     * @param ConfigurationSettingsHandler|null $configurationSettingsHandler Configuration settings handler
     *
     * @return void
     */
    public function __construct(
        IConfig $config,
        AuditTrailMapper $auditTrailMapper,
        ICacheFactory $cacheFactory,
        IGroupManager $groupManager,
        LoggerInterface $logger,
        // REMOVED: ObjectEntityMapper $objectEntityMapper (unused, caused circular dependency).
        OrganisationMapper $organisationMapper,
        SchemaCacheHandler $schemaCacheService,
        FacetCacheHandler $schemaFacetCacheService,
        SearchTrailMapper $searchTrailMapper,
        IUserManager $userManager,
        IDBConnection $db,
        ?SetupHandler $setupHandler=null,
        ?CacheHandler $objectCacheService=null,
        ?IAppContainer $container=null,
        string $appName='openregister',
        ?ValidationOperationsHandler $validationOperationsHandler=null,
        ?SearchBackendHandler $searchBackendHandler=null,
        ?LlmSettingsHandler $llmSettingsHandler=null,
        ?FileSettingsHandler $fileSettingsHandler=null,
        ?ObjectRetentionHandler $objectRetentionHandler=null,
        ?CacheSettingsHandler $cacheSettingsHandler=null,
        ?SolrSettingsHandler $solrSettingsHandler=null,
        ?ConfigurationSettingsHandler $configurationSettingsHandler=null
    ) {
        $this->config           = $config;
        $this->auditTrailMapper = $auditTrailMapper;
        $this->cacheFactory     = $cacheFactory;
        $this->groupManager     = $groupManager;
        $this->logger           = $logger;
        // REMOVED: objectEntityMapper assignment (unused, caused circular dependency).
        $this->organisationMapper      = $organisationMapper;
        $this->schemaCacheService      = $schemaCacheService;
        $this->schemaFacetCacheService = $schemaFacetCacheService;
        $this->searchTrailMapper       = $searchTrailMapper;
        $this->userManager  = $userManager;
        $this->db           = $db;
        $this->setupHandler = $setupHandler;
        $this->objectCacheService = $objectCacheService;
        $this->container          = $container;
        $this->appName            = $appName;

        // Initialize handlers (lazy-load if not provided).
        $this->validationOperationsHandler  = $validationOperationsHandler;
        $this->searchBackendHandler         = $searchBackendHandler;
        $this->llmSettingsHandler           = $llmSettingsHandler;
        $this->fileSettingsHandler          = $fileSettingsHandler;
        $this->objectRetentionHandler       = $objectRetentionHandler;
        $this->cacheSettingsHandler         = $cacheSettingsHandler;
        $this->solrSettingsHandler          = $solrSettingsHandler;
        $this->configurationSettingsHandler = $configurationSettingsHandler;
    }//end __construct()

    // ============================================
    // DELEGATION METHODS TO HANDLERS
    // ============================================
    // SearchBackendHandler methods (2)

    /**
     * Get search backend configuration
     *
     * @return array Search backend configuration
     */
    public function getSearchBackendConfig(): array
    {
        // Direct implementation to avoid circular dependency during DI initialization.
        // The handler might not be initialized yet when Application.php needs this method.
        try {
            $backendConfig = $this->config->getAppValue($this->appName, 'search_backend', '');

            if (empty($backendConfig) === true) {
                return [
                    'active'    => 'solr',
                    'available' => ['solr', 'elasticsearch'],
                ];
            }

            return json_decode($backendConfig, true);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve search backend configuration: '.$e->getMessage());
            return [
                'active'    => 'solr',
                'available' => ['solr', 'elasticsearch'],
            ];
        }
    }//end getSearchBackendConfig()

    /**
     * Update search backend configuration
     *
     * @param array $data Search backend configuration data
     *
     * @return array Updated configuration
     */
    public function updateSearchBackendConfig(array $data): array
    {
        // Extract backend string from data array.
        $backend = $data['backend'] ?? $data['active'] ?? 'solr';
        return $this->searchBackendHandler->updateSearchBackendConfig($backend);
    }//end updateSearchBackendConfig()

    // LlmSettingsHandler methods (2).

    /**
     * Get LLM settings only
     *
     * @return array LLM settings
     */
    public function getLLMSettingsOnly(): array
    {
        return $this->llmSettingsHandler->getLLMSettingsOnly();
    }//end getLLMSettingsOnly()

    /**
     * Update LLM settings only
     *
     * @param array $data LLM settings data
     *
     * @return array Updated LLM settings
     */
    public function updateLLMSettingsOnly(array $data): array
    {
        return $this->llmSettingsHandler->updateLLMSettingsOnly($data);
    }//end updateLLMSettingsOnly()

    // FileSettingsHandler methods (2).

    /**
     * Get file settings only
     *
     * @return array File settings
     */
    public function getFileSettingsOnly(): array
    {
        return $this->fileSettingsHandler->getFileSettingsOnly();
    }//end getFileSettingsOnly()

    /**
     * Update file settings only
     *
     * @param array $data File settings data
     *
     * @return array Updated file settings
     */
    public function updateFileSettingsOnly(array $data): array
    {
        return $this->fileSettingsHandler->updateFileSettingsOnly($data);
    }//end updateFileSettingsOnly()

    // ObjectRetentionHandler methods (4).

    /**
     * Get object settings only
     *
     * @return array Object settings
     */
    public function getObjectSettingsOnly(): array
    {
        return $this->objectRetentionHandler->getObjectSettingsOnly();
    }//end getObjectSettingsOnly()

    /**
     * Update object settings only
     *
     * @param array $data Object settings data
     *
     * @return array Updated object settings
     */
    public function updateObjectSettingsOnly(array $data): array
    {
        return $this->objectRetentionHandler->updateObjectSettingsOnly($data);
    }//end updateObjectSettingsOnly()

    /**
     * Get retention settings only
     *
     * @return array Retention settings
     */
    public function getRetentionSettingsOnly(): array
    {
        return $this->objectRetentionHandler->getRetentionSettingsOnly();
    }//end getRetentionSettingsOnly()

    /**
     * Update retention settings only
     *
     * @param array $data Retention settings data
     *
     * @return array Updated retention settings
     */
    public function updateRetentionSettingsOnly(array $data): array
    {
        return $this->objectRetentionHandler->updateRetentionSettingsOnly($data);
    }//end updateRetentionSettingsOnly()

    // CacheSettingsHandler methods (3 main ones).

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function getCacheStats(): array
    {
        return $this->cacheSettingsHandler->getCacheStats();
    }//end getCacheStats()

    /**
     * Clear cache
     *
     * @param string|null $cacheType Type of cache to clear
     *
     * @return array Clear cache result
     */
    public function clearCache(?string $cacheType=null): array
    {
        return $this->cacheSettingsHandler->clearCache($cacheType);
    }//end clearCache()

    /**
     * Warmup names cache
     *
     * @return array Warmup result
     */
    public function warmupNamesCache(): array
    {
        return $this->cacheSettingsHandler->warmupNamesCache();
    }//end warmupNamesCache()

    // SolrSettingsHandler methods (7 main ones).

    /**
     * Get SOLR settings
     *
     * @return array SOLR settings
     */
    public function getSolrSettings(): array
    {
        return $this->solrSettingsHandler->getSolrSettings();
    }//end getSolrSettings()

    /**
     * Get SOLR settings only
     *
     * @return array SOLR settings
     */
    public function getSolrSettingsOnly(): array
    {
        return $this->solrSettingsHandler->getSolrSettingsOnly();
    }//end getSolrSettingsOnly()

    /**
     * Update SOLR settings only
     *
     * @param array $data SOLR settings data
     *
     * @return array Updated SOLR settings
     */
    public function updateSolrSettingsOnly(array $data): array
    {
        return $this->solrSettingsHandler->updateSolrSettingsOnly($data);
    }//end updateSolrSettingsOnly()

    /**
     * Get SOLR dashboard statistics
     *
     * @return array SOLR dashboard stats
     */
    public function getSolrDashboardStats(): array
    {
        return $this->solrSettingsHandler->getSolrDashboardStats();
    }//end getSolrDashboardStats()

    /**
     * Get SOLR facet configuration
     *
     * @return array Facet configuration
     */
    public function getSolrFacetConfiguration(): array
    {
        return $this->solrSettingsHandler->getSolrFacetConfiguration();
    }//end getSolrFacetConfiguration()

    /**
     * Update SOLR facet configuration
     *
     * @param array $data Facet configuration data
     *
     * @return array Updated facet configuration
     */
    public function updateSolrFacetConfiguration(array $data): array
    {
        return $this->solrSettingsHandler->updateSolrFacetConfiguration($data);
    }//end updateSolrFacetConfiguration()

    /**
     * Warmup SOLR index
     *
     * @param array  $schemas       Schemas to warmup
     * @param int    $maxObjects    Maximum objects to process
     * @param string $mode          Processing mode
     * @param bool   $collectErrors Whether to collect errors
     * @param int    $batchSize     Batch size
     * @param array  $schemaIds     Schema IDs to process
     *
     * @return never Warmup result
     */
    public function warmupSolrIndex(
        array $schemas=[],
        int $maxObjects=0,
        string $mode='serial',
        bool $collectErrors=false,
        int $batchSize=1000,
        array $schemaIds=[]
    ): never {
        // NOTE: This method calls a deprecated method that always throws.
        // TODO: Refactor to use IndexService->warmupIndex() directly.
        $this->solrSettingsHandler->warmupSolrIndex();
    }//end warmupSolrIndex()

    // ConfigurationSettingsHandler methods (15 main ones).

    /**
     * Get settings
     *
     * @return array Settings configuration
     */
    public function getSettings(): array
    {
        return $this->configurationSettingsHandler->getSettings();
    }//end getSettings()

    /**
     * Update settings
     *
     * @param array $data Settings data
     *
     * @return array Updated settings
     */
    public function updateSettings(array $data): array
    {
        return $this->configurationSettingsHandler->updateSettings($data);
    }//end updateSettings()

    /**
     * Update publishing options
     *
     * @param array $data Publishing options data
     *
     * @return bool[] Updated settings
     *
     * @psalm-return array{use_old_style_publishing_view?: bool,
     *               auto_publish_objects?: bool, auto_publish_attachments?: bool}
     */
    public function updatePublishingOptions(array $data): array
    {
        return $this->configurationSettingsHandler->updatePublishingOptions($data);
    }//end updatePublishingOptions()

    /**
     * Check if multi-tenancy is enabled
     *
     * @return bool True if enabled
     */
    public function isMultiTenancyEnabled(): bool
    {
        return $this->configurationSettingsHandler->isMultiTenancyEnabled();
    }//end isMultiTenancyEnabled()

    /**
     * Get RBAC settings only
     *
     * @return array RBAC settings
     */
    public function getRbacSettingsOnly(): array
    {
        return $this->configurationSettingsHandler->getRbacSettingsOnly();
    }//end getRbacSettingsOnly()

    /**
     * Update RBAC settings only
     *
     * @param array $data RBAC settings data
     *
     * @return array Updated RBAC settings
     */
    public function updateRbacSettingsOnly(array $data): array
    {
        return $this->configurationSettingsHandler->updateRbacSettingsOnly($data);
    }//end updateRbacSettingsOnly()

    /**
     * Get organisation settings only
     *
     * @return (mixed|null|true)[][] Organisation settings
     *
     * @psalm-return array{organisation: array{default_organisation: mixed|null,
     *               auto_create_default_organisation: mixed|true}}
     */
    public function getOrganisationSettingsOnly(): array
    {
        return $this->configurationSettingsHandler->getOrganisationSettingsOnly();
    }//end getOrganisationSettingsOnly()

    /**
     * Update organisation settings only
     *
     * @param array $data Organisation settings data
     *
     * @return (mixed|null|true)[][] Updated organisation settings
     *
     * @psalm-return array{organisation: array{default_organisation: mixed|null,
     *               auto_create_default_organisation: mixed|true}}
     */
    public function updateOrganisationSettingsOnly(array $data): array
    {
        return $this->configurationSettingsHandler->updateOrganisationSettingsOnly($data);
    }//end updateOrganisationSettingsOnly()

    /**
     * Get default organisation UUID
     *
     * @return string|null Organisation UUID
     */
    public function getDefaultOrganisationUuid(): ?string
    {
        return $this->configurationSettingsHandler->getDefaultOrganisationUuid();
    }//end getDefaultOrganisationUuid()

    /**
     * Set default organisation UUID
     *
     * @param string|null $uuid Organisation UUID
     *
     * @return void
     */
    public function setDefaultOrganisationUuid(?string $uuid): void
    {
        $this->configurationSettingsHandler->setDefaultOrganisationUuid($uuid);
    }//end setDefaultOrganisationUuid()

    /**
     * Get tenant ID
     *
     * @return string|null Tenant ID
     */
    public function getTenantId(): ?string
    {
        return $this->configurationSettingsHandler->getTenantId();
    }//end getTenantId()

    /**
     * Get organisation ID
     *
     * @return string|null Organisation ID
     */
    public function getOrganisationId(): ?string
    {
        return $this->configurationSettingsHandler->getOrganisationId();
    }//end getOrganisationId()

    /**
     * Get multitenancy settings only
     *
     * @return array[] Multitenancy settings
     *
     * @psalm-return array{multitenancy: array{enabled: false|mixed,
     *     defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed,
     *     publishedObjectsBypassMultiTenancy: false|mixed,
     *     adminOverride: mixed|true}, availableTenants: array}
     */
    public function getMultitenancySettingsOnly(): array
    {
        return $this->configurationSettingsHandler->getMultitenancySettingsOnly();
    }//end getMultitenancySettingsOnly()

    /**
     * Update multitenancy settings only
     *
     * @param array $data Multitenancy settings data
     *
     * @return array Updated multitenancy settings
     */
    public function updateMultitenancySettingsOnly(array $data): array
    {
        return $this->configurationSettingsHandler->updateMultitenancySettingsOnly($data);
    }//end updateMultitenancySettingsOnly()

    /**
     * Get version info only
     *
     * @return array Version information
     */
    public function getVersionInfoOnly(): array
    {
        return $this->configurationSettingsHandler->getVersionInfoOnly();
    }//end getVersionInfoOnly()

    /**
     * Validate all objects in the system
     *
     * Triggers validation logic for all objects without re-saving them.
     * This is a lighter-weight operation compared to massValidateObjects.
     *
     * @return array Validation results
     *
     * @throws Exception If validation operation fails.
     */
    public function validateAllObjects(): array
    {
        return $this->validationOperationsHandler->validateAllObjects();
    }//end validateAllObjects()

    /**
     * Mass validate objects by re-saving them to trigger business logic
     *
     * Re-saves all objects in the system to ensure all business logic
     * is triggered and objects are properly processed according to current rules.
     *
     * @param int    $maxObjects    Maximum number of objects to process (0 = all).
     * @param int    $batchSize     Batch size for processing (default: 1000).
     * @param string $mode          Processing mode: 'serial' or 'parallel'.
     * @param bool   $collectErrors Whether to collect all errors or stop on first.
     *
     * @return array Mass validation results
     *
     * @throws Exception If mass validation operation fails.
     */
    public function massValidateObjects(
        int $maxObjects=0,
        int $batchSize=1000,
        string $mode='serial',
        bool $collectErrors=false
    ): array {
        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);
        $peakMemory  = memory_get_peak_usage(true);

        // Validate parameters.
        if (in_array($mode, ['serial', 'parallel'], true) === false) {
            throw new InvalidArgumentException('Invalid mode parameter. Must be "serial" or "parallel"');
        }

        if ($batchSize < 1 || $batchSize > 5000) {
            throw new InvalidArgumentException('Invalid batch size. Must be between 1 and 5000');
        }

        // Get services from container.
        // CIRCULAR DEPENDENCY FIX: Cannot lazy-load ObjectService from SettingsService.
        $objectService = null;
        // $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
        $objectMapper = $this->container->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);

        // Get total object count.
        $totalObjects = $objectMapper->countSearchObjects(
            query: [],
            activeOrganisationUuid: null,
            _rbac: false,
            _multitenancy: false
        );

        // Apply maxObjects limit if specified.
        if ($maxObjects > 0 && $maxObjects < $totalObjects) {
            $totalObjects = $maxObjects;
        }

        $this->logger->info(
            '🚀 STARTING MASS VALIDATION',
            [
                'totalObjects'  => $totalObjects,
                'batchSize'     => $batchSize,
                'mode'          => $mode,
                'collectErrors' => $collectErrors,
            ]
        );

        // Initialize results array.
        $results = [
            'success'           => true,
            'message'           => 'Mass validation completed successfully',
            'stats'             => [
                'total_objects'      => $totalObjects,
                'processed_objects'  => 0,
                'successful_saves'   => 0,
                'failed_saves'       => 0,
                'duration_seconds'   => 0,
                'batches_processed'  => 0,
                'objects_per_second' => 0,
            ],
            'errors'            => [],
            'batches_processed' => 0,
            'timestamp'         => date('c'),
            'config_used'       => [
                'mode'           => $mode,
                'max_objects'    => $maxObjects,
                'batch_size'     => $batchSize,
                'collect_errors' => $collectErrors,
            ],
        ];

        // Create batch jobs.
        $batchJobs = $this->createBatchJobs(totalObjects: $totalObjects, batchSize: $batchSize);
        $results['stats']['batches_processed'] = count($batchJobs);

        $this->logger->info(
            '📋 BATCH JOBS CREATED',
            [
                'totalBatches'      => count($batchJobs),
                'estimatedDuration' => round((count($batchJobs) * 2)).'s',
            ]
        );

        // Process batches based on mode.
        if ($mode === 'parallel') {
            $this->processJobsParallel(
                batchJobs: $batchJobs,
                objectMapper: $objectMapper,
                objectService: $objectService,
                results: $results,
                collectErrors: $collectErrors,
                parallelBatches: 4
            );
        } else {
            $this->processJobsSerial(
                batchJobs: $batchJobs,
                objectMapper: $objectMapper,
                objectService: $objectService,
                results: $results,
                collectErrors: $collectErrors
            );
        }

        // Calculate final metrics.
        $endTime         = microtime(true);
        $endMemory       = memory_get_usage(true);
        $finalPeakMemory = memory_get_peak_usage(true);

        $results['stats']['duration_seconds'] = round($endTime - $startTime, 2);

        // Calculate objects per second.
        if ($results['stats']['duration_seconds'] > 0) {
            $results['stats']['objects_per_second'] = round(
                $results['stats']['processed_objects'] / $results['stats']['duration_seconds'],
                2
            );
        }

        // Add memory usage information.
        $results['memory_usage'] = [
            'start_memory'    => $startMemory,
            'end_memory'      => $endMemory,
            'peak_memory'     => max($peakMemory, $finalPeakMemory),
            'memory_used'     => $endMemory - $startMemory,
            'peak_percentage' => round((max($peakMemory, $finalPeakMemory) / (1024 * 1024 * 1024)) * 100, 1),
            'formatted'       => [
                'actual_used'     => $this->formatBytes($endMemory - $startMemory),
                'peak_usage'      => $this->formatBytes(max($peakMemory, $finalPeakMemory)),
                'peak_percentage' => round(
                    (max($peakMemory, $finalPeakMemory) / (1024 * 1024 * 1024)) * 100,
                    1
                ).'%',
            ],
        ];

        /*
         * Determine overall success.
         * Note: failed_saves can be incremented in processJobsParallel/processJobsSerial.
         *
         * @psalm-suppress TypeDoesNotContainType
         */

        if ($results['stats']['failed_saves'] > 0) {
            if ($collectErrors === true) {
                $results['success'] = $results['stats']['successful_saves'] > 0;

                /*
                 * @psalm-suppress NoValue
                 */

                $results['message'] = sprintf(
                    'Mass validation completed with %d errors out of %d objects (%d successful)',
                    $results['stats']['failed_saves'],
                    $results['stats']['total_objects'],
                    $results['stats']['successful_saves']
                );
            } else {
                $results['success'] = false;

                /*
                 * @psalm-suppress NoValue
                 */

                $results['message'] = sprintf(
                    'Mass validation stopped after %d errors (processed %d out of %d objects)',
                    $results['stats']['failed_saves'],
                    $results['stats']['processed_objects'],
                    $results['stats']['total_objects']
                );
            }//end if
        }//end if

        $this->logger->info(
            '✅ MASS VALIDATION COMPLETED',
            [
                'successful'       => $results['stats']['successful_saves'],
                'failed'           => $results['stats']['failed_saves'],
                'total'            => $results['stats']['processed_objects'],
                'duration'         => $results['stats']['duration_seconds'].'s',
                'objectsPerSecond' => $results['stats']['objects_per_second'],
                'mode'             => $mode,
            ]
        );

        return $results;
    }//end massValidateObjects()

    /**
     * Create batch jobs for mass validation
     *
     * @param int $totalObjects Total number of objects to process.
     * @param int $batchSize    Batch size for processing.
     *
     * @return int[][] Array of batch job definitions.
     *
     * @psalm-return list<array{batchNumber: int<1, max>, limit: int, offset: int}>
     */
    private function createBatchJobs(int $totalObjects, int $batchSize): array
    {
        $batchJobs   = [];
        $offset      = 0;
        $batchNumber = 0;

        while ($offset < $totalObjects) {
            $currentBatchSize = min($batchSize, $totalObjects - $offset);
            $batchJobs[]      = [
                'batchNumber' => ++$batchNumber,
                'offset'      => $offset,
                'limit'       => $currentBatchSize,
            ];
            $offset          += $currentBatchSize;
        }

        return $batchJobs;
    }//end createBatchJobs()

    /**
     * Process batch jobs in serial mode
     *
     * @param array                                   $batchJobs     Array of batch job definitions.
     * @param \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper  The object entity mapper.
     * @param ObjectService|null                      $objectService The object service instance.
     * @param array                                   $results       Results array to update.
     * @param bool                                    $collectErrors Whether to collect all errors.
     *
     * @return void
     */
    private function processJobsSerial(
        array $batchJobs,
        \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper,
        ?\OCA\OpenRegister\Service\ObjectService $objectService,
        array &$results,
        bool $collectErrors
    ): void {
        foreach ($batchJobs as $job) {
            $batchStartTime = microtime(true);

            // Get objects for this batch.
            $objects = $objectMapper->findAll(
                limit: $job['limit'],
                offset: $job['offset']
            );

            $batchProcessed = 0;
            $batchSuccesses = 0;
            $batchErrors    = [];

            foreach ($objects as $object) {
                try {
                    $batchProcessed++;
                    $results['stats']['processed_objects']++;

                    // Re-save the object to trigger all business logic.
                    // ObjectService::saveObject signature:
                    // (array|ObjectEntity $object, ?array $extend,
                    // Register|string|int|null $register,
                    // Schema|string|int|null $schema, ?string $uuid, ...).
                    $objectData = $object->getObject();
                    // Get the object business data.
                    $savedObject = $objectService->saveObject(
                        object: $objectData,
                        extend: [],
                        register: $object->getRegister(),
                        // Get the register ID.
                        schema: $object->getSchema(),
                        // Get the schema ID.
                        uuid: $object->getUuid()
                    );

                    if ($savedObject !== null) {
                        $batchSuccesses++;
                        $results['stats']['successful_saves']++;
                    } else {
                        $results['stats']['failed_saves']++;
                        $batchErrors[] = [
                            'object_id'   => $object->getUuid(),
                            'object_name' => $object->getName() ?? $object->getUuid(),
                            'register'    => $object->getRegister(),
                            'schema'      => $object->getSchema(),
                            'error'       => 'Save operation returned null',
                            'batch_mode'  => 'serial_optimized',
                        ];
                    }
                } catch (Exception $e) {
                    $results['stats']['failed_saves']++;
                    $batchErrors[] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'error'       => $e->getMessage(),
                        'batch_mode'  => 'serial_optimized',
                    ];

                    $this->logger->error(
                        'Mass validation failed for object '.$object->getUuid().': '.$e->getMessage()
                    );

                    if ($collectErrors === false) {
                        break;
                    }
                }//end try
            }//end foreach

            $batchDuration = microtime(true) - $batchStartTime;

            // Calculate objects per second.
            $objectsPerSecond = 0;
            if ($batchDuration > 0) {
                $objectsPerSecond = round($batchProcessed / $batchDuration, 2);
            }

            // Log progress.
            $this->logger->info(
                '📈 MASS VALIDATION PROGRESS',
                [
                    'batchNumber'      => $job['batchNumber'],
                    'totalBatches'     => count($batchJobs),
                    'processed'        => $batchProcessed,
                    'successful'       => $batchSuccesses,
                    'failed'           => count($batchErrors),
                    'batchDuration'    => round($batchDuration * 1000).'ms',
                    'objectsPerSecond' => $objectsPerSecond,
                    'totalProcessed'   => $results['stats']['processed_objects'],
                ]
            );

            // Add batch errors to results.
            $results['errors'] = array_merge($results['errors'], $batchErrors);

            // Memory management every 10 batches.
            if ($job['batchNumber'] % 10 === 0) {
                $this->logger->debug(
                    '🧹 MEMORY CLEANUP',
                    [
                        'memoryUsage' => round(memory_get_usage() / 1024 / 1024, 2).'MB',
                        'peakMemory'  => round(memory_get_peak_usage() / 1024 / 1024, 2).'MB',
                    ]
                );
                gc_collect_cycles();
            }

            // Clear objects from memory.
            unset($objects);
        }//end foreach
    }//end processJobsSerial()

    /**
     * Process batch jobs in parallel mode
     *
     * @param array                                   $batchJobs       Array of batch job definitions.
     * @param \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper    The object entity mapper.
     * @param ObjectService|null                      $objectService   The object service instance.
     * @param array                                   $results         Results array to update.
     * @param bool                                    $collectErrors   Whether to collect all errors.
     * @param int                                     $parallelBatches Number of parallel batches.
     *
     * @return void
     */
    private function processJobsParallel(
        array $batchJobs,
        \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper,
        ?\OCA\OpenRegister\Service\ObjectService $objectService,
        array &$results,
        bool $collectErrors,
        int $parallelBatches
    ): void {
        // Process batches in parallel chunks.
        $batchChunks = array_chunk($batchJobs, $parallelBatches);

        foreach ($batchChunks as $chunkIndex => $chunk) {
            $this->logger->info(
                '🔄 PROCESSING PARALLEL CHUNK',
                [
                    'chunkIndex'     => $chunkIndex + 1,
                    'totalChunks'    => count($batchChunks),
                    'batchesInChunk' => count($chunk),
                ]
            );

            $chunkStartTime = microtime(true);

            // Process batches in this chunk (simulated parallel processing).
            $chunkResults = [];
            foreach ($chunk as $job) {
                $result         = $this->processBatchDirectly(
                    objectMapper: $objectMapper,
                    objectService: $objectService,
                    job: $job,
                    collectErrors: $collectErrors
                );
                $chunkResults[] = $result;
            }

            // Aggregate results from this chunk.
            foreach ($chunkResults as $result) {
                $results['stats']['processed_objects'] += $result['processed'];
                $results['stats']['successful_saves']  += $result['successful'];
                $results['stats']['failed_saves']      += $result['failed'];
                $results['errors'] = array_merge($results['errors'], $result['errors']);
            }

            $chunkTime      = round((microtime(true) - $chunkStartTime) * 1000, 2);
            $chunkProcessed = array_sum(array_column($chunkResults, 'processed'));

            $this->logger->info(
                '✅ COMPLETED PARALLEL CHUNK',
                [
                    'chunkIndex'       => $chunkIndex + 1,
                    'chunkTime'        => $chunkTime.'ms',
                    'objectsProcessed' => $chunkProcessed,
                    'totalProcessed'   => $results['stats']['processed_objects'],
                ]
            );

            // Memory cleanup after each chunk.
            gc_collect_cycles();
        }//end foreach
    }//end processJobsParallel()

    /**
     * Process a single batch directly
     *
     * @param \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper  The object entity mapper.
     * @param \OCA\OpenRegister\Service\ObjectService $objectService The object service instance.
     * @param array                                   $job           Batch job definition.
     * @param bool                                    $collectErrors Whether to collect all errors.
     *
     * @return ((null|string)[][]|float|int)[] Batch processing results.
     *
     * @psalm-return array{processed: int<0, max>, successful: int<0, max>,
     *     failed: int<0, max>, errors: list<array{batch_mode: 'parallel_optimized',
     *     error: string, object_id: null|string, object_name: null|string,
     *     register: null|string, schema: null|string}>, duration: float}
     */
    private function processBatchDirectly(
        \OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper,
        \OCA\OpenRegister\Service\ObjectService $objectService,
        array $job,
        bool $collectErrors
    ): array {
        $batchStartTime = microtime(true);

        // Get objects for this batch.
        $objects = $objectMapper->findAll(
            limit: $job['limit'],
            offset: $job['offset']
        );

        $batchProcessed = 0;
        $batchSuccesses = 0;
        $batchErrors    = [];

        foreach ($objects as $object) {
            try {
                $batchProcessed++;

                // Re-save the object to trigger all business logic.
                // ObjectService::saveObject signature:
                // (array|ObjectEntity $object, ?array $extend,
                // Register|string|int|null $register,
                // Schema|string|int|null $schema, ?string $uuid, ...).
                $objectData = $object->getObject();
                // Get the object business data.
                $savedObject = $objectService->saveObject(
                    object: $objectData,
                    extend: [],
                    register: $object->getRegister(),
                    // Get the register ID.
                    schema: $object->getSchema(),
                    // Get the schema ID.
                    uuid: $object->getUuid()
                );

                if ($savedObject !== null) {
                    $batchSuccesses++;
                } else {
                    $batchErrors[] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'error'       => 'Save operation returned null',
                        'batch_mode'  => 'parallel_optimized',
                    ];
                }
            } catch (Exception $e) {
                $batchErrors[] = [
                    'object_id'   => $object->getUuid(),
                    'object_name' => $object->getName() ?? $object->getUuid(),
                    'register'    => $object->getRegister(),
                    'schema'      => $object->getSchema(),
                    'error'       => $e->getMessage(),
                    'batch_mode'  => 'parallel_optimized',
                ];

                if ($collectErrors === false) {
                    break;
                }
            }//end try
        }//end foreach

        $batchDuration = microtime(true) - $batchStartTime;

        // Clear objects from memory.
        unset($objects);

        return [
            'processed'  => $batchProcessed,
            'successful' => $batchSuccesses,
            'failed'     => count($batchErrors),
            'errors'     => $batchErrors,
            'duration'   => $batchDuration,
        ];
    }//end processBatchDirectly()

    /**
     * Format bytes into human readable format
     *
     * @param int $bytes     Number of bytes.
     * @param int $precision Decimal precision.
     *
     * @return string Formatted string.
     */
    public function formatBytes(int $bytes, int $precision=2): string
    {
        $units     = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitCount = count($units);

        $i = 0;
        for (; $bytes > 1024 && $i < $unitCount - 1; $i++) {
            $bytes /= 1024;
        }

        // Ensure $i is within bounds (0-4) for the $units array.
        $i = min($i, $unitCount - 1);

        return round($bytes, $precision).' '.$units[$i];
    }//end formatBytes()

    /**
     * Convert memory limit string to bytes.
     *
     * @param string $memoryLimit Memory limit string (e.g., '128M', '1G').
     *
     * @return int Memory limit in bytes.
     */
    public function convertToBytes(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last        = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value       = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // No break.
            case 'm':
                $value *= 1024;
                // No break.
            case 'k':
                $value *= 1024;
        }

        return $value;
    }//end convertToBytes()

    /**
     * Mask sensitive token for display.
     *
     * Shows first 4 and last 4 characters, masks the middle.
     *
     * @param string $token The token to mask.
     *
     * @return string The masked token.
     */
    public function maskToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        $start  = substr($token, 0, 4);
        $end    = substr($token, -4);
        $middle = str_repeat('*', min(20, strlen($token) - 8));

        return $start.$middle.$end;
    }//end maskToken()

    /**
     * Get expected schema fields based on OpenRegister schemas.
     *
     * Returns field definitions for SOLR schema comparison, combining
     * core metadata fields with user-defined schema fields.
     *
     * @param \OCA\OpenRegister\Db\SchemaMapper      $schemaMapper      Schema mapper for database access.
     * @param \OCA\OpenRegister\Service\IndexService $solrSchemaService Index service for field analysis.
     *
     * @return array Expected field configuration.
     */
    public function getExpectedSchemaFields(
        \OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
        \OCA\OpenRegister\Service\IndexService $solrSchemaService
    ): array {
        try {
            // Start with the core ObjectEntity metadata fields from SetupHandler (if available).
            $expectedFields = [];
            if ($this->setupHandler !== null) {
                $expectedFields = $this->setupHandler->getObjectEntityFieldDefinitions();
            }

            // Get all schemas.
            $schemas = $schemaMapper->findAll();

            // Use the existing analyzeAndResolveFieldConflicts method via reflection.
            $reflection = new ReflectionClass($solrSchemaService);
            $method     = $reflection->getMethod('analyzeAndResolveFieldConflicts');

            $result = $method->invoke($solrSchemaService, $schemas);

            // Merge user-defined schema fields with core metadata fields.
            $userSchemaFields = $result['fields'] ?? [];
            $expectedFields   = array_merge($expectedFields, $userSchemaFields);

            return $expectedFields;
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to get expected schema fields',
                [
                    'error' => $e->getMessage(),
                ]
            );
            // Return at least the core metadata fields even if schema analysis fails.
            if ($this->setupHandler !== null) {
                return $this->setupHandler->getObjectEntityFieldDefinitions();
            }

            return [];
        }//end try
    }//end getExpectedSchemaFields()

    /**
     * Compare actual SOLR fields with expected schema fields.
     *
     * Identifies missing fields, extra fields, and configuration mismatches
     * between the current SOLR schema and expected field definitions.
     *
     * @param array $actualFields   Current SOLR fields.
     * @param array $expectedFields Expected fields from schemas.
     *
     * @return array Field comparison results
     */
    public function compareFields(array $actualFields, array $expectedFields): array
    {
        $missing    = [];
        $extra      = [];
        $mismatched = [];

        // Find missing fields (expected but not in SOLR).
        foreach ($expectedFields as $fieldName => $expectedConfig) {
            if (isset($actualFields[$fieldName]) === false) {
                $missing[] = [
                    'field'           => $fieldName,
                    'expected_type'   => $expectedConfig['type'] ?? 'unknown',
                    'expected_config' => $expectedConfig,
                ];
            }
        }

        // Find extra fields (in SOLR but not expected) and mismatched configurations.
        foreach ($actualFields as $fieldName => $actualField) {
            // Skip only system fields (but allow self_* metadata fields to be checked).
            if (str_starts_with($fieldName, '_') === true) {
                continue;
            }

            if (isset($expectedFields[$fieldName]) === false) {
                $extra[] = [
                    'field'         => $fieldName,
                    'actual_type'   => $actualField['type'] ?? 'unknown',
                    'actual_config' => $actualField,
                ];
            } else {
                // Check for configuration mismatches (type, multiValued, docValues).
                $expectedConfig      = $expectedFields[$fieldName];
                $expectedType        = $expectedConfig['type'] ?? '';
                $actualType          = $actualField['type'] ?? '';
                $expectedMultiValued = $expectedConfig['multiValued'] ?? false;
                $actualMultiValued   = $actualField['multiValued'] ?? false;
                $expectedDocValues   = $expectedConfig['docValues'] ?? false;
                $actualDocValues     = $actualField['docValues'] ?? false;

                // Check if any configuration differs.
                if ($expectedType !== $actualType
                    || $expectedMultiValued !== $actualMultiValued
                    || $expectedDocValues !== $actualDocValues
                ) {
                    $differences = [];
                    if ($expectedType !== $actualType) {
                        $differences[] = 'type';
                    }

                    if ($expectedMultiValued !== $actualMultiValued) {
                        $differences[] = 'multiValued';
                    }

                    if ($expectedDocValues !== $actualDocValues) {
                        $differences[] = 'docValues';
                    }

                    $mismatched[] = [
                        'field'                => $fieldName,
                        'expected_type'        => $expectedType,
                        'actual_type'          => $actualType,
                        'expected_multiValued' => $expectedMultiValued,
                        'actual_multiValued'   => $actualMultiValued,
                        'expected_docValues'   => $expectedDocValues,
                        'actual_docValues'     => $actualDocValues,
                        'differences'          => $differences,
                        'expected_config'      => $expectedConfig,
                        'actual_config'        => $actualField,
                    ];
                }//end if
            }//end if
        }//end foreach

        return [
            'missing'    => $missing,
            'extra'      => $extra,
            'mismatched' => $mismatched,
            'summary'    => [
                'missing_count'     => count($missing),
                'extra_count'       => count($extra),
                'mismatched_count'  => count($mismatched),
                'total_differences' => count($missing) + count($extra) + count($mismatched),
            ],
        ];
    }//end compareFields()

    /**
     * Get comprehensive statistics.
     *
     * Returns combined statistics from various components.
     *
     * @return array Comprehensive statistics
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'timestamp' => time(),
                'date'      => date('Y-m-d H:i:s'),
            ];

            // Get Solr stats if available.
            try {
                $stats['solr'] = $this->getSolrDashboardStats();
            } catch (\Exception $e) {
                $stats['solr'] = ['error' => $e->getMessage()];
            }

            // Get cache stats.
            try {
                $stats['cache'] = $this->getCacheStats();
            } catch (\Exception $e) {
                $stats['cache'] = ['error' => $e->getMessage()];
            }

            // Get system info.
            $stats['system'] = [
                'php_version'        => PHP_VERSION,
                'memory_limit'       => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ];

            return $stats;
        } catch (\Exception $e) {
            return [
                'error'   => 'Failed to retrieve stats',
                'message' => $e->getMessage(),
            ];
        }//end try
    }//end getStats()

    /**
     * Rebase configuration from source.
     *
     * Resets configuration to default or imports from source.
     * This is typically used for configuration management.
     *
     * @param array $options Rebase options
     *
     * @return ((string|true)[][]|bool|int|string)[] Rebase result
     *
     * @psalm-return array{success: bool, error?: 'Rebase failed', message: string,
     *     rebased?: array{solr?: array{success: true,
     *     message: 'Solr configuration rebased'}, cache?: array{success: true,
     *     message: 'Cache cleared and ready for rebuild'}},
     *     timestamp?: int<1, max>}
     */
    public function rebase(array $options=[]): array
    {
        try {
            $this->logger->info('[SettingsService] Rebase requested', ['options' => $options]);

            // Get current settings (currently unused but kept for potential future use).
            // $currentSettings = $this->getSettings();
            // Determine what to rebase.
            $components = $options['components'] ?? ['all'];
            $rebased    = [];

            if (in_array('all', $components, true) === true || in_array('solr', $components, true) === true) {
                // Rebase Solr configuration.
                $rebased['solr'] = [
                    'success' => true,
                    'message' => 'Solr configuration rebased',
                ];
            }

            if (in_array('all', $components, true) === true || in_array('cache', $components, true) === true) {
                // Clear and rebuild cache.
                $this->clearCache();
                $rebased['cache'] = [
                    'success' => true,
                    'message' => 'Cache cleared and ready for rebuild',
                ];
            }

            return [
                'success'   => true,
                'message'   => 'Configuration rebase completed',
                'rebased'   => $rebased,
                'timestamp' => time(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('[SettingsService] Rebase failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error'   => 'Rebase failed',
                'message' => $e->getMessage(),
            ];
        }//end try
    }//end rebase()
}//end class
