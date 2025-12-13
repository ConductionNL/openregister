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

use Exception;
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
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
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
 * - Test SOLR connections (use GuzzleSolrService)
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
 * - GuzzleSolrService: Reads SOLR settings for search operations
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
     * @var ObjectCacheService|null
     */
    private ?ObjectCacheService $objectCacheService = null;

    /**
     * Group manager
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Object entity mapper
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectEntityMapper;

    /**
     * Organisation mapper
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $organisationMapper;

    /**
     * Schema cache service
     *
     * @var SchemaCacheService
     */
    private SchemaCacheService $schemaCacheService;

    /**
     * Schema facet cache service
     *
     * @var SchemaFacetCacheService
     */
    private SchemaFacetCacheService $schemaFacetCacheService;

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
     * This constant represents the unique identifier for the OpenRegister application, used to check its installation and status.
     *
     * @var string $openRegisterAppId The ID of the OpenRegister app.
     */
    private const OPENREGISTER_APP_ID = 'openregister';

    /**
     * This constant defines the minimum version of the OpenRegister application that is required for compatibility and functionality.
     *
     * @var string $minOpenRegisterVersion The minimum required version of OpenRegister.
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
     * @param IConfig                 $config                  Configuration service
     * @param AuditTrailMapper        $auditTrailMapper        Audit trail mapper
     * @param ICacheFactory           $cacheFactory            Cache factory
     * @param IGroupManager           $groupManager            Group manager
     * @param LoggerInterface         $logger                  Logger
     * @param ObjectEntityMapper      $objectEntityMapper      Object entity mapper
     * @param OrganisationMapper      $organisationMapper      Organisation mapper
     * @param SchemaCacheService      $schemaCacheService      Schema cache service
     * @param SchemaFacetCacheService $schemaFacetCacheService Schema facet cache service
     * @param SearchTrailMapper       $searchTrailMapper       Search trail mapper
     * @param IUserManager            $userManager             User manager
     * @param IDBConnection           $db                      Database connection
     * @param ObjectCacheService|null $objectCacheService      Object cache service (optional, lazy-loaded)
     * @param IAppContainer|null      $container               Container for lazy loading (optional)
     * @param string                  $appName                 Application name
     *
     * @return void
     */
    public function __construct(
        IConfig $config,
        AuditTrailMapper $auditTrailMapper,
        ICacheFactory $cacheFactory,
        IGroupManager $groupManager,
        LoggerInterface $logger,
        ObjectEntityMapper $objectEntityMapper,
        OrganisationMapper $organisationMapper,
        SchemaCacheService $schemaCacheService,
        SchemaFacetCacheService $schemaFacetCacheService,
        SearchTrailMapper $searchTrailMapper,
        IUserManager $userManager,
        IDBConnection $db,
        ?ObjectCacheService $objectCacheService=null,
        ?IAppContainer $container=null,
        string $appName='openregister'
    ) {
        $this->config           = $config;
        $this->auditTrailMapper = $auditTrailMapper;
        $this->cacheFactory     = $cacheFactory;
        $this->groupManager     = $groupManager;
        $this->logger           = $logger;
        $this->objectEntityMapper      = $objectEntityMapper;
        $this->organisationMapper      = $organisationMapper;
        $this->schemaCacheService      = $schemaCacheService;
        $this->schemaFacetCacheService = $schemaFacetCacheService;
        $this->searchTrailMapper       = $searchTrailMapper;
        $this->userManager = $userManager;
        $this->db          = $db;
        $this->objectCacheService = $objectCacheService;
        $this->container          = $container;
        $this->appName            = $appName;

    }//end __construct()


    /**
     * Check if multi-tenancy is enabled
     *
     * @return bool True if multi-tenancy is enabled, false otherwise
     */
    public function isMultiTenancyEnabled(): bool
    {
        $multitenancyConfig = $this->config->getAppValue($this->appName, 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['enabled'] ?? false;

    }//end isMultiTenancyEnabled()


    /**
     * Retrieve the current settings including RBAC and Multitenancy.
     *
     * @return array[] The current settings configuration.
     *
     * @throws \RuntimeException If settings retrieval fails.
     *
     * @psalm-return array{version: array{appName: 'Open Register', appVersion: '0.2.3'}, rbac: array{enabled: false|mixed, anonymousGroup: 'public'|mixed, defaultNewUserGroup: 'viewer'|mixed, defaultObjectOwner: ''|mixed, adminOverride: mixed|true}, multitenancy: array{enabled: false|mixed, defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed, publishedObjectsBypassMultiTenancy: false|mixed, adminOverride: mixed|true}, availableGroups: array, availableTenants: array, availableUsers: array, retention: array{objectArchiveRetention: 31536000000|mixed, objectDeleteRetention: 63072000000|mixed, searchTrailRetention: 2592000000|mixed, createLogRetention: 2592000000|mixed, readLogRetention: 86400000|mixed, updateLogRetention: 604800000|mixed, deleteLogRetention: 2592000000|mixed, auditTrailsEnabled: mixed|true, searchTrailsEnabled: mixed|true}, solr: array{enabled: false|mixed, host: 'solr'|mixed, port: 8983|mixed, path: '/solr'|mixed, core: 'openregister'|mixed, configSet: '_default'|mixed, scheme: 'http'|mixed, username: 'solr'|mixed, password: 'SolrRocks'|mixed, timeout: 30|mixed, autoCommit: mixed|true, commitWithin: 1000|mixed, enableLogging: mixed|true, zookeeperHosts: 'zookeeper:2181'|mixed, zookeeperUsername: ''|mixed, zookeeperPassword: ''|mixed, collection: 'openregister'|mixed, useCloud: mixed|true, objectCollection: mixed|null, fileCollection: mixed|null}}
     */
    public function getSettings(): array
    {
        try {
            $data = [];

            // Version information.
            $data['version'] = [
                'appName'    => 'Open Register',
                'appVersion' => '0.2.3',
            ];

            // RBAC Settings.
            $rbacConfig = $this->config->getAppValue($this->appName, 'rbac', '');
            if (empty($rbacConfig) === true) {
                $data['rbac'] = [
                    'enabled'             => false,
                    'anonymousGroup'      => 'public',
                    'defaultNewUserGroup' => 'viewer',
                    'defaultObjectOwner'  => '',
                    'adminOverride'       => true,
                ];
            } else {
                $rbacData     = json_decode($rbacConfig, true);
                $data['rbac'] = [
                    'enabled'             => $rbacData['enabled'] ?? false,
                    'anonymousGroup'      => $rbacData['anonymousGroup'] ?? 'public',
                    'defaultNewUserGroup' => $rbacData['defaultNewUserGroup'] ?? 'viewer',
                    'defaultObjectOwner'  => $rbacData['defaultObjectOwner'] ?? '',
                    'adminOverride'       => $rbacData['adminOverride'] ?? true,
                ];
            }

            // Multitenancy Settings.
            $multitenancyConfig = $this->config->getAppValue($this->appName, 'multitenancy', '');
            if (empty($multitenancyConfig) === true) {
                $data['multitenancy'] = [
                    'enabled'                            => false,
                    'defaultUserTenant'                  => '',
                    'defaultObjectTenant'                => '',
                    'publishedObjectsBypassMultiTenancy' => false,
                    'adminOverride'                      => true,
                ];
            } else {
                $multitenancyData     = json_decode($multitenancyConfig, true);
                $data['multitenancy'] = [
                    'enabled'                            => $multitenancyData['enabled'] ?? false,
                    'defaultUserTenant'                  => $multitenancyData['defaultUserTenant'] ?? '',
                    'defaultObjectTenant'                => $multitenancyData['defaultObjectTenant'] ?? '',
                    'publishedObjectsBypassMultiTenancy' => $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false,
                    'adminOverride'                      => $multitenancyData['adminOverride'] ?? true,
                ];
            }

            // Get available Nextcloud groups.
            $data['availableGroups'] = $this->getAvailableGroups();

            // Get available organisations as tenants.
            $data['availableTenants'] = $this->getAvailableOrganisations();

            // Get available users.
            $data['availableUsers'] = $this->getAvailableUsers();

            // Retention Settings with defaults.
            $retentionConfig = $this->config->getAppValue($this->appName, 'retention', '');
            if (empty($retentionConfig) === true) {
                $data['retention'] = [
                    'objectArchiveRetention' => 31536000000,
                // 1 year default
                    'objectDeleteRetention'  => 63072000000,
                // 2 years default
                    'searchTrailRetention'   => 2592000000,
                // 1 month default
                    'createLogRetention'     => 2592000000,
                // 1 month default
                    'readLogRetention'       => 86400000,
                // 24 hours default
                    'updateLogRetention'     => 604800000,
                // 1 week default
                    'deleteLogRetention'     => 2592000000,
                // 1 month default
                    'auditTrailsEnabled'     => true,
                // Audit trails enabled by default.
                    'searchTrailsEnabled'    => true,
                // Search trails enabled by default.
                ];
            } else {
                $retentionData     = json_decode($retentionConfig, true);
                $data['retention'] = [
                    'objectArchiveRetention' => $retentionData['objectArchiveRetention'] ?? 31536000000,
                    'objectDeleteRetention'  => $retentionData['objectDeleteRetention'] ?? 63072000000,
                    'searchTrailRetention'   => $retentionData['searchTrailRetention'] ?? 2592000000,
                    'createLogRetention'     => $retentionData['createLogRetention'] ?? 2592000000,
                    'readLogRetention'       => $retentionData['readLogRetention'] ?? 86400000,
                    'updateLogRetention'     => $retentionData['updateLogRetention'] ?? 604800000,
                    'deleteLogRetention'     => $retentionData['deleteLogRetention'] ?? 2592000000,
                    'auditTrailsEnabled'     => $retentionData['auditTrailsEnabled'] ?? true,
                    'searchTrailsEnabled'    => $retentionData['searchTrailsEnabled'] ?? true,
                ];
            }//end if

            // SOLR Search Configuration.
            $solrConfig = $this->config->getAppValue($this->appName, 'solr', '');

            if (empty($solrConfig) === true) {
                $data['solr'] = [
                    'enabled'           => false,
                    'host'              => 'solr',
                    'port'              => 8983,
                    'path'              => '/solr',
                    'core'              => 'openregister',
                    'configSet'         => '_default',
                    'scheme'            => 'http',
                    'username'          => 'solr',
                    'password'          => 'SolrRocks',
                    'timeout'           => 30,
                    'autoCommit'        => true,
                    'commitWithin'      => 1000,
                    'enableLogging'     => true,
                    'zookeeperHosts'    => 'zookeeper:2181',
                    'zookeeperUsername' => '',
                    'zookeeperPassword' => '',
                    'collection'        => 'openregister',
                    'useCloud'          => true,
                    'objectCollection'  => null,
                    'fileCollection'    => null,
                ];
            } else {
                $solrData     = json_decode($solrConfig, true);
                $data['solr'] = [
                    'enabled'           => $solrData['enabled'] ?? false,
                    'host'              => $solrData['host'] ?? 'solr',
                    'port'              => $solrData['port'] ?? 8983,
                    'path'              => $solrData['path'] ?? '/solr',
                    'core'              => $solrData['core'] ?? 'openregister',
                    'configSet'         => $solrData['configSet'] ?? '_default',
                    'scheme'            => $solrData['scheme'] ?? 'http',
                    'username'          => $solrData['username'] ?? 'solr',
                    'password'          => $solrData['password'] ?? 'SolrRocks',
                    'timeout'           => $solrData['timeout'] ?? 30,
                    'autoCommit'        => $solrData['autoCommit'] ?? true,
                    'commitWithin'      => $solrData['commitWithin'] ?? 1000,
                    'enableLogging'     => $solrData['enableLogging'] ?? true,
                    'zookeeperHosts'    => $solrData['zookeeperHosts'] ?? 'zookeeper:2181',
                    'zookeeperUsername' => $solrData['zookeeperUsername'] ?? '',
                    'zookeeperPassword' => $solrData['zookeeperPassword'] ?? '',
                    'collection'        => $solrData['collection'] ?? 'openregister',
                    'useCloud'          => $solrData['useCloud'] ?? true,
                    'objectCollection'  => $solrData['objectCollection'] ?? null,
                    'fileCollection'    => $solrData['fileCollection'] ?? null,
                ];
            }//end if

            return $data;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve settings: '.$e->getMessage());
        }//end try

    }//end getSettings()


    /**
     * Get available Nextcloud groups.
     *
     * @return string[] Array of group_id => group_name
     *
     * @psalm-return array<string, string>
     */
    private function getAvailableGroups(): array
    {
        $groups = [];

        // Add special "public" group for anonymous users.
        $groups['public'] = 'Public (No restrictions)';

        // Get all Nextcloud groups.
        $nextcloudGroups = $this->groupManager->search('');
        foreach ($nextcloudGroups as $group) {
            $groups[$group->getGID()] = $group->getDisplayName();
        }

        return $groups;

    }//end getAvailableGroups()


    /**
     * Get available organisations as tenants.
     *
     * @return array Array of organisation_uuid => organisation_name
     */
    private function getAvailableOrganisations(): array
    {
        try {
            $organisations = $this->organisationMapper->findAllWithUserCount();
            $tenants       = [];

            foreach ($organisations as $organisation) {
                $tenants[$organisation->getUuid()] = $organisation->getName();
            }

            return $tenants;
        } catch (Exception $e) {
            // Return empty array if organisations are not available.
            return [];
        }

    }//end getAvailableOrganisations()


    /**
     * Get available users.
     *
     * @return string[] Array of user_id => user_display_name
     *
     * @psalm-return array<string, string>
     */
    private function getAvailableUsers(): array
    {
        $users = [];

        // Get all Nextcloud users (limit to prevent performance issues).
        $nextcloudUsers = $this->userManager->search('', 100);
        foreach ($nextcloudUsers as $user) {
            $users[$user->getUID()] = (($user->getDisplayName() !== null) === true && ($user->getDisplayName() !== '') === true) ? $user->getDisplayName() : $user->getUID();
        }

        return $users;

    }//end getAvailableUsers()


    /**
     * Update the settings configuration.
     *
     * @param array $data The settings data to update.
     *
     * @return array The updated settings configuration.
     * @throws \RuntimeException If settings update fails.
     */
    public function updateSettings(array $data): array
    {
        try {
            // Handle RBAC settings.
            if (($data['rbac'] ?? null) !== null) {
                $rbacData = $data['rbac'];
                // Always store RBAC config with enabled state.
                $rbacConfig = [
                    'enabled'             => $rbacData['enabled'] ?? false,
                    'anonymousGroup'      => $rbacData['anonymousGroup'] ?? 'public',
                    'defaultNewUserGroup' => $rbacData['defaultNewUserGroup'] ?? 'viewer',
                    'defaultObjectOwner'  => $rbacData['defaultObjectOwner'] ?? '',
                    'adminOverride'       => $rbacData['adminOverride'] ?? true,
                ];
                $this->config->setAppValue($this->appName, 'rbac', json_encode($rbacConfig));
            }

            // Handle Multitenancy settings.
            if (($data['multitenancy'] ?? null) !== null) {
                $multitenancyData = $data['multitenancy'];
                // Always store Multitenancy config with enabled state.
                $multitenancyConfig = [
                    'enabled'                            => $multitenancyData['enabled'] ?? false,
                    'defaultUserTenant'                  => $multitenancyData['defaultUserTenant'] ?? '',
                    'defaultObjectTenant'                => $multitenancyData['defaultObjectTenant'] ?? '',
                    'publishedObjectsBypassMultiTenancy' => $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false,
                    'adminOverride'                      => $multitenancyData['adminOverride'] ?? true,
                ];
                $this->config->setAppValue($this->appName, 'multitenancy', json_encode($multitenancyConfig));
            }

            // Handle Retention settings.
            if (($data['retention'] ?? null) !== null) {
                $retentionData   = $data['retention'];
                $retentionConfig = [
                    'objectArchiveRetention' => $retentionData['objectArchiveRetention'] ?? 31536000000,
                    'objectDeleteRetention'  => $retentionData['objectDeleteRetention'] ?? 63072000000,
                    'searchTrailRetention'   => $retentionData['searchTrailRetention'] ?? 2592000000,
                    'createLogRetention'     => $retentionData['createLogRetention'] ?? 2592000000,
                    'readLogRetention'       => $retentionData['readLogRetention'] ?? 86400000,
                    'updateLogRetention'     => $retentionData['updateLogRetention'] ?? 604800000,
                    'deleteLogRetention'     => $retentionData['deleteLogRetention'] ?? 2592000000,
                    'auditTrailsEnabled'     => $retentionData['auditTrailsEnabled'] ?? true,
                    'searchTrailsEnabled'    => $retentionData['searchTrailsEnabled'] ?? true,
                ];
                $this->config->setAppValue($this->appName, 'retention', json_encode($retentionConfig));
            }

            // Handle SOLR settings.
            if (($data['solr'] ?? null) !== null) {
                $solrData   = $data['solr'];
                $solrConfig = [
                    'enabled'           => $solrData['enabled'] ?? false,
                    'host'              => $solrData['host'] ?? 'solr',
                    'port'              => (int) ($solrData['port'] ?? 8983),
                    'path'              => $solrData['path'] ?? '/solr',
                    'core'              => $solrData['core'] ?? 'openregister',
                    'configSet'         => $solrData['configSet'] ?? '_default',
                    'scheme'            => $solrData['scheme'] ?? 'http',
                    'username'          => $solrData['username'] ?? 'solr',
                    'password'          => $solrData['password'] ?? 'SolrRocks',
                    'timeout'           => (int) ($solrData['timeout'] ?? 30),
                    'autoCommit'        => $solrData['autoCommit'] ?? true,
                    'commitWithin'      => (int) ($solrData['commitWithin'] ?? 1000),
                    'enableLogging'     => $solrData['enableLogging'] ?? true,
                    'zookeeperHosts'    => $solrData['zookeeperHosts'] ?? 'zookeeper:2181',
                    'zookeeperUsername' => $solrData['zookeeperUsername'] ?? '',
                    'zookeeperPassword' => $solrData['zookeeperPassword'] ?? '',
                    'collection'        => $solrData['collection'] ?? 'openregister',
                    'useCloud'          => $solrData['useCloud'] ?? true,
                    'objectCollection'  => $solrData['objectCollection'] ?? null,
                    'fileCollection'    => $solrData['fileCollection'] ?? null,
                ];
                $this->config->setAppValue($this->appName, 'solr', json_encode($solrConfig));
            }//end if

            // Return the updated settings.
            return $this->getSettings();
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update settings: '.$e->getMessage());
        }//end try

    }//end updateSettings()


    /**
     * Update the publishing options configuration.
     *
     * @param array $options The publishing options data to update.
     *
     * @return bool[] The updated publishing options configuration.
     *
     * @throws \RuntimeException If publishing options update fails.
     *
     * @psalm-return array{use_old_style_publishing_view?: bool, auto_publish_objects?: bool, auto_publish_attachments?: bool}
     */
    public function updatePublishingOptions(array $options): array
    {
        try {
            // Define valid publishing option keys for security.
            $validOptions = [
                'auto_publish_attachments',
                'auto_publish_objects',
                'use_old_style_publishing_view',
            ];

            $updatedOptions = [];

            // Update each publishing option in the configuration.
            foreach ($validOptions as $option) {
                // Check if this option is provided in the input data.
                if (isset($options[$option]) === true) {
                    // Convert boolean or string to string format for storage.
                    $value = $options[$option] === true || $options[$option] === 'true' ? 'true' : 'false';
                    // Store the value in the configuration.
                    $this->config->setAppValue($this->appName, $option, $value);
                    // Retrieve and convert back to boolean for the response.
                    $updatedOptions[$option] = $this->config->getAppValue($this->appName, $option, '') === 'true';
                }
            }

            return $updatedOptions;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update publishing options: '.$e->getMessage());
        }//end try

    }//end updatePublishingOptions()


    /**
     * Rebase all objects and logs with current retention settings.
     *
     * This method assigns default owners and organizations to objects that don't have them assigned
     * and can be extended in the future to handle retention time recalculation.
     *
     * @return (\DateTime|array|bool|null|string)[] Array containing the rebase operation results
     *
     * @throws \RuntimeException If the rebase operation fails
     *
     * @psalm-return array{startTime: \DateTime, ownershipResults: array|null, errors: list{0?: string,...}, retentionResults: array{auditTrailsUpdated?: int, searchTrailsUpdated?: int, objectsExpired?: int}, endTime: \DateTime, duration: string, success: bool}
     */
    public function rebaseObjectsAndLogs(): array
    {
        try {
            $startTime = new \DateTime();
            $results   = [
                'startTime'        => $startTime,
                'ownershipResults' => null,
                'errors'           => [],
            ];

            // Get current settings.
            $settings = $this->getSettings();

            // Assign default owners and organizations to objects that don't have them.
            if (empty($settings['rbac']['defaultObjectOwner']) === false || empty($settings['multitenancy']['defaultObjectTenant']) === false) {
                try {
                    $defaultOwner        = $settings['rbac']['defaultObjectOwner'] ?? null;
                    $defaultOrganisation = $settings['multitenancy']['defaultObjectTenant'] ?? null;

                    $results['ownershipResults'] = $this->objectEntityMapper->bulkOwnerDeclaration($defaultOwner, $defaultOrganisation);
                } catch (Exception $e) {
                    $error = 'Failed to assign default owners/organizations: '.$e->getMessage();
                    $results['errors'][] = $error;
                }
            } else {
                $results['ownershipResults'] = [
                    'message' => 'No default owner or organization configured, skipping ownership assignment.',
                ];
            }

            // Set expiry dates based on retention settings.
            $retention = $settings['retention'] ?? [];
            $results['retentionResults'] = [];

            try {
                // Set expiry dates for audit trails (simplified - using first available retention).
                $auditRetention = $retention['createLogRetention'] ?? $retention['readLogRetention'] ?? $retention['updateLogRetention'] ?? $retention['deleteLogRetention'] ?? 0;
                if ($auditRetention > 0) {
                    $auditUpdated = $this->auditTrailMapper->setExpiryDate($auditRetention);
                    $results['retentionResults']['auditTrailsUpdated'] = $auditUpdated;
                }

                // Set expiry dates for search trails.
                if (($retention['searchTrailRetention'] ?? null) !== null && $retention['searchTrailRetention'] > 0) {
                    $searchUpdated = $this->searchTrailMapper->setExpiryDate($retention['searchTrailRetention']);
                    $results['retentionResults']['searchTrailsUpdated'] = $searchUpdated;
                }

                // Set expiry dates for objects (based on deleted date + retention).
                if (($retention['objectDeleteRetention'] ?? null) !== null && $retention['objectDeleteRetention'] > 0) {
                    $objectsExpired = $this->objectEntityMapper->setExpiryDate($retention['objectDeleteRetention']);
                    $results['retentionResults']['objectsExpired'] = $objectsExpired;
                }
            } catch (Exception $e) {
                $error = 'Failed to set expiry dates: '.$e->getMessage();
                $results['errors'][] = $error;
            }//end try

            $results['endTime']  = new \DateTime();
            $results['duration'] = $results['endTime']->diff($startTime)->format('%H:%I:%S');
            $results['success']  = empty($results['errors']);

            return $results;
        } catch (Exception $e) {
            throw new \RuntimeException('Rebase operation failed: '.$e->getMessage());
        }//end try

    }//end rebaseObjectsAndLogs()


    /**
     * General rebase method that can be called from any settings section.
     *
     * This is an alias for rebaseObjectsAndLogs() to provide a consistent interface
     * for all sections that have rebase buttons.
     *
     * @return array Array containing the rebase operation results
     * @throws \RuntimeException If the rebase operation fails
     */
    public function rebase(): array
    {
        return $this->rebaseObjectsAndLogs();

    }//end rebase()


    /**
     * Get statistics for the settings dashboard.
     *
     * This method provides warning counts for objects and logs that need attention,
     * as well as total counts for all tables using optimized SQL queries.
     *
     * @return (int[]|string)[] Array containing warning counts and total counts for all tables
     *
     * @throws \RuntimeException If statistics retrieval fails
     *
     * @psalm-return array{warnings: array{objectsWithoutOwner: int, objectsWithoutOrganisation: int, auditTrailsWithoutExpiry: int, searchTrailsWithoutExpiry: int, expiredAuditTrails: int, expiredSearchTrails: int, expiredObjects: int}, totals: array<string, int>, sizes: array{totalObjectsSize: int, totalAuditTrailsSize: int, totalSearchTrailsSize: int, deletedObjectsSize: int, expiredAuditTrailsSize: int, expiredSearchTrailsSize: int, expiredObjectsSize: int}, lastUpdated: string}
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'warnings'    => [
                    'objectsWithoutOwner'        => 0,
                    'objectsWithoutOrganisation' => 0,
                    'auditTrailsWithoutExpiry'   => 0,
                    'searchTrailsWithoutExpiry'  => 0,
                    'expiredAuditTrails'         => 0,
                    'expiredSearchTrails'        => 0,
                    'expiredObjects'             => 0,
                ],
                'totals'      => [
                    'totalObjects'            => 0,
                    'totalAuditTrails'        => 0,
                    'totalSearchTrails'       => 0,
                    'totalConfigurations'     => 0,
                    'totalDataAccessProfiles' => 0,
                    'totalOrganisations'      => 0,
                    'totalRegisters'          => 0,
                    'totalSchemas'            => 0,
                    'totalSources'            => 0,
                    'deletedObjects'          => 0,
                ],
                'sizes'       => [
                    'totalObjectsSize'        => 0,
                    'totalAuditTrailsSize'    => 0,
                    'totalSearchTrailsSize'   => 0,
                    'deletedObjectsSize'      => 0,
                    'expiredAuditTrailsSize'  => 0,
                    'expiredSearchTrailsSize' => 0,
                    'expiredObjectsSize'      => 0,
                ],
                'lastUpdated' => (new \DateTime())->format('c'),
            ];

            // Get database connection for optimized queries.
            $db = $this->db;

            // **OPTIMIZED QUERIES**: Use direct SQL COUNT queries for maximum performance
            // 1. Objects table - comprehensive stats with single query
            $objectsQuery = "
                SELECT 
                    COUNT(*) as total_objects,
                    COALESCE(SUM(CAST(size AS UNSIGNED)), 0) as total_size,
                    SUM(CASE WHEN owner IS NULL OR owner = '' THEN 1 ELSE 0 END) as without_owner,
                    SUM(CASE WHEN organisation IS NULL OR organisation = '' THEN 1 ELSE 0 END) as without_organisation,
                    SUM(CASE WHEN deleted IS NOT NULL THEN 1 ELSE 0 END) as deleted_count,
                    SUM(CASE WHEN deleted IS NOT NULL THEN COALESCE(CAST(size AS UNSIGNED), 0) ELSE 0 END) as deleted_size,
                    SUM(CASE WHEN expires IS NOT NULL AND expires < NOW() THEN 1 ELSE 0 END) as expired_count,
                    SUM(CASE WHEN expires IS NOT NULL AND expires < NOW() THEN COALESCE(CAST(size AS UNSIGNED), 0) ELSE 0 END) as expired_size
                FROM `*PREFIX*openregister_objects`
            ";

            $result      = $db->executeQuery($objectsQuery);
            $objectsData = $result->fetch();
            $result->closeCursor();

            $stats['totals']['totalObjects']          = (int) ($objectsData['total_objects'] ?? 0);
            $stats['sizes']['totalObjectsSize']       = (int) ($objectsData['total_size'] ?? 0);
            $stats['warnings']['objectsWithoutOwner'] = (int) ($objectsData['without_owner'] ?? 0);
            $stats['warnings']['objectsWithoutOrganisation'] = (int) ($objectsData['without_organisation'] ?? 0);
            $stats['totals']['deletedObjects']    = (int) ($objectsData['deleted_count'] ?? 0);
            $stats['sizes']['deletedObjectsSize'] = (int) ($objectsData['deleted_size'] ?? 0);
            $stats['warnings']['expiredObjects']  = (int) ($objectsData['expired_count'] ?? 0);
            $stats['sizes']['expiredObjectsSize'] = (int) ($objectsData['expired_size'] ?? 0);

            // 2. Audit trails table - comprehensive stats
            $auditQuery = "
                SELECT 
                    COUNT(*) as total_count,
                    COALESCE(SUM(size), 0) as total_size,
                    SUM(CASE WHEN expires IS NULL OR expires = '' THEN 1 ELSE 0 END) as without_expiry,
                    SUM(CASE WHEN expires IS NOT NULL AND expires < NOW() THEN 1 ELSE 0 END) as expired_count,
                    SUM(CASE WHEN expires IS NOT NULL AND expires < NOW() THEN COALESCE(size, 0) ELSE 0 END) as expired_size
                FROM `*PREFIX*openregister_audit_trails`
            ";

            $result    = $db->executeQuery($auditQuery);
            $auditData = $result->fetch();
            $result->closeCursor();

            $stats['totals']['totalAuditTrails']           = (int) ($auditData['total_count'] ?? 0);
            $stats['sizes']['totalAuditTrailsSize']        = (int) ($auditData['total_size'] ?? 0);
            $stats['warnings']['auditTrailsWithoutExpiry'] = (int) ($auditData['without_expiry'] ?? 0);
            $stats['warnings']['expiredAuditTrails']       = (int) ($auditData['expired_count'] ?? 0);
            $stats['sizes']['expiredAuditTrailsSize']      = (int) ($auditData['expired_size'] ?? 0);

            // 3. Search trails table - comprehensive stats
            $searchQuery = "
                SELECT 
                    COUNT(*) as total_count,
                    COALESCE(SUM(size), 0) as total_size,
                    SUM(CASE WHEN expires IS NULL OR expires = '' THEN 1 ELSE 0 END) as without_expiry,
                    SUM(CASE WHEN expires IS NOT NULL AND expires < NOW() THEN 1 ELSE 0 END) as expired_count,
                    SUM(CASE WHEN expires IS NOT NULL AND expires < NOW() THEN COALESCE(size, 0) ELSE 0 END) as expired_size
                FROM `*PREFIX*openregister_search_trails`
            ";

            $result     = $db->executeQuery($searchQuery);
            $searchData = $result->fetch();
            $result->closeCursor();

            $stats['totals']['totalSearchTrails']           = (int) ($searchData['total_count'] ?? 0);
            $stats['sizes']['totalSearchTrailsSize']        = (int) ($searchData['total_size'] ?? 0);
            $stats['warnings']['searchTrailsWithoutExpiry'] = (int) ($searchData['without_expiry'] ?? 0);
            $stats['warnings']['expiredSearchTrails']       = (int) ($searchData['expired_count'] ?? 0);
            $stats['sizes']['expiredSearchTrailsSize']      = (int) ($searchData['expired_size'] ?? 0);

            // 4. All other tables - simple counts (these should be fast)
            $simpleCountTables = [
                'configurations'     => '`*PREFIX*openregister_configurations`',
                'dataAccessProfiles' => '`*PREFIX*openregister_data_access_profiles`',
                'organisations'      => '`*PREFIX*openregister_organisations`',
                'registers'          => '`*PREFIX*openregister_registers`',
                'schemas'            => '`*PREFIX*openregister_schemas`',
                'sources'            => '`*PREFIX*openregister_sources`',
            ];

            foreach ($simpleCountTables as $key => $tableName) {
                try {
                    $countQuery = "SELECT COUNT(*) as total FROM {$tableName}";
                    $result     = $db->executeQuery($countQuery);
                    $count      = $result->fetchColumn();
                    $result->closeCursor();

                    $stats['totals']['total'.ucfirst($key)] = (int) ($count ?? 0);
                } catch (Exception $e) {
                    // Table might not exist, set to 0 and continue.
                    $stats['totals']['total'.ucfirst($key)] = 0;
                }
            }

            return $stats;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve statistics: '.$e->getMessage());
        }//end try

    }//end getStats()


    /**
     * Get comprehensive cache statistics from actual cache systems (not database)
     *
     * Provides detailed insights into cache usage and performance by querying
     * the actual cache backends rather than database tables for better performance.
     *
     * @return (array|string)[] Comprehensive cache statistics from cache systems
     *
     * @psalm-return array{overview: array{totalCacheSize: 0|mixed, totalCacheEntries: 0|mixed, overallHitRate: float, averageResponseTime: float|mixed, cacheEfficiency: float}, services: array{object: array{entries: 0|mixed, hits: 0|mixed, requests: 0|mixed, memoryUsage: 0|mixed}, schema: array{entries: 0, hits: 0, requests: 0, memoryUsage: 0}, facet: array{entries: 0, hits: 0, requests: 0, memoryUsage: 0}}, names: array{cache_size: 0|mixed, hit_rate: float|mixed, hits: 0|mixed, misses: 0|mixed, warmups: 0|mixed, enabled: bool}, distributed: array, performance: array, lastUpdated: string, error?: string}
     */
    public function getCacheStats(): array
    {
        try {
            // Get basic distributed cache info.
            $distributedStats = $this->getDistributedCacheStats();
            $performanceStats = $this->getCachePerformanceMetrics();

            // Get object cache stats (only if ObjectCacheService provides them)
            // Use cached stats to avoid expensive operations on every request.
            $objectStats = $this->getCachedObjectStats();

            $stats = [
                'overview'    => [
                    'totalCacheSize'      => $objectStats['memoryUsage'] ?? 0,
                    'totalCacheEntries'   => $objectStats['entries'] ?? 0,
                    'overallHitRate'      => $this->calculateHitRate($objectStats),
                    'averageResponseTime' => $performanceStats['averageHitTime'] ?? 0.0,
                    'cacheEfficiency'     => $this->calculateHitRate($objectStats),
                ],
                'services'    => [
                    'object' => [
                        'entries'     => $objectStats['entries'] ?? 0,
                        'hits'        => $objectStats['hits'] ?? 0,
                        'requests'    => $objectStats['requests'] ?? 0,
                        'memoryUsage' => $objectStats['memoryUsage'] ?? 0,
                    ],
                    'schema' => [
                        'entries'     => 0,
                    // Not stored in database - would be performance issue.
                        'hits'        => 0,
                        'requests'    => 0,
                        'memoryUsage' => 0,
                    ],
                    'facet'  => [
                        'entries'     => 0,
                    // Not stored in database - would be performance issue.
                        'hits'        => 0,
                        'requests'    => 0,
                        'memoryUsage' => 0,
                    ],
                ],
                'names'       => [
                    'cache_size' => $objectStats['name_cache_size'] ?? 0,
                    'hit_rate'   => $objectStats['name_hit_rate'] ?? 0.0,
                    'hits'       => $objectStats['name_hits'] ?? 0,
                    'misses'     => $objectStats['name_misses'] ?? 0,
                    'warmups'    => $objectStats['name_warmups'] ?? 0,
                    'enabled'    => true,
                ],
                'distributed' => $distributedStats,
                'performance' => $performanceStats,
                'lastUpdated' => (new \DateTime())->format('c'),
            ];

            return $stats;
        } catch (Exception $e) {
            // Return safe defaults if cache stats unavailable.
            return [
                'overview'    => [
                    'totalCacheSize'      => 0,
                    'totalCacheEntries'   => 0,
                    'overallHitRate'      => 0.0,
                    'averageResponseTime' => 0.0,
                    'cacheEfficiency'     => 0.0,
                ],
                'services'    => [
                    'object' => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                    'schema' => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                    'facet'  => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                ],
                'names'       => [
                    'cache_size' => 0,
                    'hit_rate'   => 0.0,
                    'hits'       => 0,
                    'misses'     => 0,
                    'warmups'    => 0,
                    'enabled'    => false,
                ],
                'distributed' => ['type' => 'none', 'backend' => 'Unknown', 'available' => false],
                'performance' => ['averageHitTime' => 0, 'averageMissTime' => 0, 'performanceGain' => 0, 'optimalHitRate' => 85.0],
                'lastUpdated' => (new \DateTime())->format('c'),
                'error'       => 'Cache statistics unavailable: '.$e->getMessage(),
            ];
        }//end try

    }//end getCacheStats()


    /**
     * Get cached object statistics to avoid expensive operations on every request
     *
     * @return array Object cache statistics
     */
    private function getCachedObjectStats(): array
    {
        // Use a simple in-memory cache with 30-second TTL to avoid expensive ObjectCacheService calls.
        static $cachedStats = null;
        static $lastUpdate  = 0;

        $now = time();
        if ($cachedStats === null || ($now - $lastUpdate) > 30) {
            try {
                $objectCacheService = $this->objectCacheService;
                if ($objectCacheService === null && $this->container !== null) {
                    try {
                        $objectCacheService = $this->container->get(ObjectCacheService::class);
                    } catch (\Exception $e) {
                        throw new \Exception('ObjectCacheService not available');
                    }
                }

                if ($objectCacheService === null) {
                    throw new \Exception('ObjectCacheService not available');
                }

                $cachedStats = $objectCacheService->getStats();
            } catch (Exception $e) {
                // If no object cache stats available, use defaults.
                $cachedStats = [
                    'entries'         => 0,
                    'hits'            => 0,
                    'requests'        => 0,
                    'memoryUsage'     => 0,
                    'name_cache_size' => 0,
                    'name_hit_rate'   => 0.0,
                    'name_hits'       => 0,
                    'name_misses'     => 0,
                    'name_warmups'    => 0,
                ];
            }//end try

            $lastUpdate = $now;
        }//end if

        return $cachedStats;

    }//end getCachedObjectStats()


    /**
     * Calculate hit rate from cache statistics
     *
     * @param  array $stats Cache statistics array
     * @return float Hit rate percentage
     */
    private function calculateHitRate(array $stats): float
    {
        $requests = $stats['requests'] ?? 0;
        $hits     = $stats['hits'] ?? 0;

        return $requests > 0 ? ($hits / $requests) * 100 : 0.0;

    }//end calculateHitRate()


    /**
     * Get distributed cache statistics from Nextcloud's cache factory
     *
     * @return (bool|string)[] Distributed cache statistics
     *
     * @psalm-return array{type: 'distributed'|'none', backend: string, available: bool, error?: string, keyCount?: 'Unknown', size?: 'Unknown'}
     */
    private function getDistributedCacheStats(): array
    {
        try {
            $distributedCache = $this->cacheFactory->createDistributed('openregister');

            return [
                'type'      => 'distributed',
                'backend'   => get_class($distributedCache),
                'available' => true,
                'keyCount'  => 'Unknown',
            // Most cache backends don't provide this.
                'size'      => 'Unknown',
            ];
        } catch (Exception $e) {
            return [
                'type'      => 'none',
                'backend'   => 'fallback',
                'available' => false,
                'error'     => $e->getMessage(),
            ];
        }

    }//end getDistributedCacheStats()


    /**
     * Get cache performance metrics for the last period
     *
     * @return (float|string)[] Performance metrics
     *
     * @psalm-return array{averageHitTime: float, averageMissTime: float, performanceGain: float, optimalHitRate: float, currentTrend: 'improving'}
     */
    private function getCachePerformanceMetrics(): array
    {
        // This would typically come from a performance monitoring service
        // For now, return basic metrics.
        return [
            'averageHitTime'  => 2.5,
        // ms.
            'averageMissTime' => 850.0,
        // ms.
            'performanceGain' => 340.0,
        // factor improvement with cache.
            'optimalHitRate'  => 85.0,
        // target hit rate percentage.
            'currentTrend'    => 'improving',
        ];

    }//end getCachePerformanceMetrics()


    /**
     * Clear cache with granular control
     *
     * @param string      $type    Cache type: 'all', 'object', 'schema', 'facet', 'distributed', 'names'
     * @param string|null $userId  Specific user ID to clear cache for (if supported)
     * @param array       $options Additional options for cache clearing
     *
     * @return (array[]|int|mixed|null|string)[] Results of cache clearing operations
     *
     * @throws \RuntimeException If cache clearing fails
     *
     * @psalm-return array{type: string, userId: null|string, timestamp: string, results: array{names?: array, distributed?: array, facet?: array, schema?: array, object?: array}, errors: array<never, never>, totalCleared: 0|mixed}
     */
    public function clearCache(string $type='all', ?string $userId=null, array $_options=[]): array
    {
        try {
            $results = [
                'type'         => $type,
                'userId'       => $userId,
                'timestamp'    => (new \DateTime())->format('c'),
                'results'      => [],
                'errors'       => [],
                'totalCleared' => 0,
            ];

            switch ($type) {
                case 'all':
                    $results['results']['object']      = $this->clearObjectCache($userId);
                    $results['results']['schema']      = $this->clearSchemaCache($userId);
                    $results['results']['facet']       = $this->clearFacetCache($userId);
                    $results['results']['distributed'] = $this->clearDistributedCache($userId);
                    $results['results']['names']       = $this->clearNamesCache();
                    break;

                case 'object':
                    $results['results']['object'] = $this->clearObjectCache($userId);
                    break;

                case 'schema':
                    $results['results']['schema'] = $this->clearSchemaCache($userId);
                    break;

                case 'facet':
                    $results['results']['facet'] = $this->clearFacetCache($userId);
                    break;

                case 'distributed':
                    $results['results']['distributed'] = $this->clearDistributedCache($userId);
                    break;

                case 'names':
                    $results['results']['names'] = $this->clearNamesCache();
                    break;

                default:
                    throw new \InvalidArgumentException("Invalid cache type: {$type}");
            }//end switch

            // Calculate total cleared entries.
            foreach ($results['results'] as $serviceResult) {
                $results['totalCleared'] += $serviceResult['cleared'] ?? 0;
            }

            return $results;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to clear cache: '.$e->getMessage());
        }//end try

    }//end clearCache()


    /**
     * Clear object cache service
     *
     * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
     *
     * @return ((float|int)[]|bool|int|mixed|string)[] Clear operation results
     *
     * @psalm-return array{service: 'object', cleared: 0|mixed, success: bool, error?: string, before?: array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int, hit_rate: float, query_hit_rate: float, name_hit_rate: float, cache_size: int, query_cache_size: int, name_cache_size: int}|mixed, after?: array{hits: int, misses: int, preloads: int, query_hits: int, query_misses: int, name_hits: int, name_misses: int, name_warmups: int, hit_rate: float, query_hit_rate: float, name_hit_rate: float, cache_size: int, query_cache_size: int, name_cache_size: int}|mixed}
     */
    private function clearObjectCache(?string $_userId=null): array
    {
        try {
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(ObjectCacheService::class);
                } catch (\Exception $e) {
                    throw new \Exception('ObjectCacheService not available');
                }
            }

            if ($objectCacheService === null) {
                throw new \Exception('ObjectCacheService not available');
            }

            $beforeStats = $objectCacheService->getStats();
            $objectCacheService->clearCache();
            $afterStats = $objectCacheService->getStats();

            return [
                'service' => 'object',
                'cleared' => $beforeStats['entries'] - $afterStats['entries'],
                'before'  => $beforeStats,
                'after'   => $afterStats,
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'object',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end clearObjectCache()


    /**
     * Clear object names cache specifically
     *
     * @return ((int|mixed)[]|bool|int|mixed|string)[] Clear operation results
     *
     * @psalm-return array{service: 'names', cleared: 0|mixed, success: bool, error?: string, before?: array{name_cache_size: int|mixed, name_hits: int|mixed, name_misses: int|mixed}, after?: array{name_cache_size: int|mixed, name_hits: int|mixed, name_misses: int|mixed}}
     */
    private function clearNamesCache(): array
    {
        try {
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(ObjectCacheService::class);
                } catch (\Exception $e) {
                    throw new \Exception('ObjectCacheService not available');
                }
            }

            if ($objectCacheService === null) {
                throw new \Exception('ObjectCacheService not available');
            }

            $beforeStats         = $objectCacheService->getStats();
            $beforeNameCacheSize = $beforeStats['name_cache_size'] ?? 0;

            $objectCacheService->clearNameCache();

            $afterStats         = $objectCacheService->getStats();
            $afterNameCacheSize = $afterStats['name_cache_size'] ?? 0;

            return [
                'service' => 'names',
                'cleared' => $beforeNameCacheSize - $afterNameCacheSize,
                'before'  => [
                    'name_cache_size' => $beforeNameCacheSize,
                    'name_hits'       => $beforeStats['name_hits'] ?? 0,
                    'name_misses'     => $beforeStats['name_misses'] ?? 0,
                ],
                'after'   => [
                    'name_cache_size' => $afterNameCacheSize,
                    'name_hits'       => $afterStats['name_hits'] ?? 0,
                    'name_misses'     => $afterStats['name_misses'] ?? 0,
                ],
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'names',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end clearNamesCache()


    /**
     * Warmup object names cache manually
     *
     * @return ((int|mixed)[]|bool|int|mixed|string)[] Warmup operation results
     *
     * @psalm-return array{success: bool, error?: string, loaded_names: int|mixed, execution_time?: string, before?: array{name_cache_size: int|mixed, name_warmups: int|mixed}, after?: array{name_cache_size: int|mixed, name_warmups: int|mixed}}
     */
    public function warmupNamesCache(): array
    {
        try {
            $startTime          = microtime(true);
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(ObjectCacheService::class);
                } catch (\Exception $e) {
                    throw new \Exception('ObjectCacheService not available');
                }
            }

            if ($objectCacheService === null) {
                throw new \Exception('ObjectCacheService not available');
            }
            //end try

            $beforeStats = $objectCacheService->getStats();

            $loadedCount = $objectCacheService->warmupNameCache();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $afterStats    = $objectCacheService->getStats();

            return [
                'success'        => true,
                'loaded_names'   => $loadedCount,
                'execution_time' => $executionTime.'ms',
                'before'         => [
                    'name_cache_size' => $beforeStats['name_cache_size'] ?? 0,
                    'name_warmups'    => $beforeStats['name_warmups'] ?? 0,
                ],
                'after'          => [
                    'name_cache_size' => $afterStats['name_cache_size'] ?? 0,
                    'name_warmups'    => $afterStats['name_warmups'] ?? 0,
                ],
            ];
        } catch (Exception $e) {
            return [
                'success'      => false,
                'error'        => 'Cache warmup failed: '.$e->getMessage(),
                'loaded_names' => 0,
            ];
        }//end try

    }//end warmupNamesCache()


    /**
     * Clear schema cache service
     *
     * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
     *
     * @return ((int|mixed|string)[]|bool|int|mixed|string)[] Clear operation results
     *
     * @psalm-return array{service: 'schema', cleared: 0|mixed, success: bool, error?: string, before?: array{total_entries: int, entries_with_ttl: int, memory_cache_size: int<0, max>, cache_table: 'openregister_schema_cache', query_time: string, timestamp: int<1, max>, entries?: mixed}, after?: array{total_entries: int, entries_with_ttl: int, memory_cache_size: int<0, max>, cache_table: 'openregister_schema_cache', query_time: string, timestamp: int<1, max>, entries?: mixed}}
     */
    private function clearSchemaCache(?string $_userId=null): array
    {
        try {
            $beforeStats = $this->schemaCacheService->getCacheStatistics();
            $this->schemaCacheService->clearAllCaches();
            $afterStats = $this->schemaCacheService->getCacheStatistics();

            // Stats arrays may contain 'entries' key even if not in type definition.
            $beforeEntries = array_key_exists('entries', $beforeStats) ? $beforeStats['entries'] : 0;
            $afterEntries  = array_key_exists('entries', $afterStats) ? $afterStats['entries'] : 0;
            return [
                'service' => 'schema',
                'cleared' => $beforeEntries - $afterEntries,
                'before'  => $beforeStats,
                'after'   => $afterStats,
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'schema',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end clearSchemaCache()


    /**
     * Clear facet cache service
     *
     * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
     *
     * @return ((int|int[]|string)[]|bool|int|string)[] Clear operation results
     *
     * @psalm-return array{service: 'facet', cleared: int, success: bool, error?: string, before?: array{total_entries: int, by_type: array<int>, memory_cache_size: int<0, max>, cache_table: 'openregister_schema_facet_cache', query_time: string, timestamp: int<1, max>}, after?: array{total_entries: int, by_type: array<int>, memory_cache_size: int<0, max>, cache_table: 'openregister_schema_facet_cache', query_time: string, timestamp: int<1, max>}}
     */
    private function clearFacetCache(?string $_userId=null): array
    {
        try {
            $beforeStats = $this->schemaFacetCacheService->getCacheStatistics();
            $this->schemaFacetCacheService->clearAllCaches();
            $afterStats = $this->schemaFacetCacheService->getCacheStatistics();

            return [
                'service' => 'facet',
                'cleared' => ($beforeStats['total_entries'] ?? 0) - ($afterStats['total_entries'] ?? 0),
                'before'  => $beforeStats,
                'after'   => $afterStats,
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'facet',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }

    }//end clearFacetCache()


    /**
     * Clear distributed cache
     *
     * @param string|null $_userId Specific user ID (unused, kept for API compatibility)
     *
     * @return (bool|int|string)[] Clear operation results
     *
     * @psalm-return array{service: 'distributed', cleared: 'all'|0, success: bool, error?: string}
     */
    private function clearDistributedCache(?string $_userId=null): array
    {
        try {
            $distributedCache = $this->cacheFactory->createDistributed('openregister');
            $distributedCache->clear();

            return [
                'service' => 'distributed',
                'cleared' => 'all',
            // Can't count distributed cache entries.
                'success' => true,
            ];
        } catch (Exception $e) {
            return [
                'service' => 'distributed',
                'cleared' => 0,
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }

    }//end clearDistributedCache()


    /**
     * Get SOLR configuration settings
     *
     * @return array SOLR configuration array
     *
     * @throws \RuntimeException If SOLR settings retrieval fails
     */
    public function getSolrSettings(): array
    {
        try {
            $solrConfig = $this->config->getAppValue($this->appName, 'solr', '');
            if (empty($solrConfig) === true) {
                return [
                    'enabled'        => false,
                    'host'           => 'solr',
                    'port'           => 8983,
                    'path'           => '/solr',
                    'core'           => 'openregister',
                    'configSet'      => '_default',
                    'scheme'         => 'http',
                    'username'       => '',
                    'password'       => '',
                    'timeout'        => 30,
                    'autoCommit'     => true,
                    'commitWithin'   => 1000,
                    'enableLogging'  => true,
                    'zookeeperHosts' => 'zookeeper:2181',
                    'collection'     => 'openregister',
                    'useCloud'       => true,
                ];
            }

            return json_decode($solrConfig, true);
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve SOLR settings: '.$e->getMessage());
        }//end try

    }//end getSolrSettings()


    /**
     * Complete SOLR warmup: mirror schemas and index objects from the database
     *
     * This method performs comprehensive SOLR index warmup by:
     * 1. Mirroring all OpenRegister schemas to SOLR for proper field typing
     * 2. Bulk indexing objects from the database using schema-aware mapping
     * 3. Performing cache warmup queries
     * 4. Committing and optimizing the index
     *
     * @param  int $batchSize  Number of objects to process per batch (default 1000, parameter kept for API compatibility)
     * @param  int $maxObjects Maximum number of objects to index (0 = all)
     * @return array Warmup operation results with statistics and status
     * @throws \RuntimeException If SOLR warmup fails
     */


    /**
     * Complete SOLR warmup: mirror schemas and index objects from the database
     *
     * @deprecated This method is deprecated. Use GuzzleSolrService->warmupIndex() directly via controller.
     * This method is kept for backward compatibility but should not be used.
     * The controller now uses GuzzleSolrService directly to avoid circular dependencies.
     *
     * @param int    $_batchSize    Number of objects to process per batch (unused, kept for API compatibility)
     * @param int    $maxObjects    Maximum number of objects to index (unused, kept for API compatibility)
     * @param string $mode          Processing mode (unused, kept for API compatibility)
     * @param bool   $collectErrors Whether to collect errors (unused, kept for API compatibility)
     *
     * @return never Warmup operation results with statistics and status
     *
     * @throws \RuntimeException Always throws exception indicating method is deprecated
     */
    public function warmupSolrIndex(int $_batchSize=2000, int $maxObjects=0, string $mode='serial', bool $collectErrors=false)
    {
        // NOTE: This method is deprecated. Use GuzzleSolrService->warmupIndex() directly via controller.
        // This method is kept for backward compatibility but should not be used.
        // The controller now uses GuzzleSolrService directly to avoid circular dependencies.
        throw new \RuntimeException(
            'SettingsService::warmupSolrIndex() is deprecated. Use GuzzleSolrService->warmupIndex() directly via controller.'
        );

    }//end warmupSolrIndex()


    /**
     * Get comprehensive SOLR dashboard statistics
     *
     * Provides detailed metrics for the SOLR Search Management dashboard
     * including core statistics, performance metrics, and health indicators.
     *
     * @return array SOLR dashboard metrics and statistics
     * @throws \RuntimeException If SOLR statistics retrieval fails
     */
    public function getSolrDashboardStats(): array
    {
        try {
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(ObjectCacheService::class);
                } catch (\Exception $e) {
                    throw new \Exception('ObjectCacheService not available');
                }
            }

            if ($objectCacheService === null) {
                throw new \Exception('ObjectCacheService not available');
            }

            $rawStats = $objectCacheService->getSolrDashboardStats();

            // Transform the raw stats into the expected dashboard structure.
            return $this->transformSolrStatsToDashboard($rawStats);
        } catch (Exception $e) {
            // Return default dashboard structure if SOLR is not available.
            return [
                'overview'     => [
                    'available'         => false,
                    'connection_status' => 'unavailable',
                    'response_time_ms'  => 0,
                    'total_documents'   => 0,
                    'index_size'        => '0 B',
                    'last_commit'       => null,
                ],
                'cores'        => [
                    'active_core'  => 'unknown',
                    'core_status'  => 'inactive',
                    'endpoint_url' => 'N/A',
                ],
                'performance'  => [
                    'total_searches'     => 0,
                    'total_indexes'      => 0,
                    'total_deletes'      => 0,
                    'avg_search_time_ms' => 0,
                    'avg_index_time_ms'  => 0,
                    'total_search_time'  => 0,
                    'total_index_time'   => 0,
                    'operations_per_sec' => 0,
                    'error_rate'         => 0,
                ],
                'health'       => [
                    'status'            => 'unavailable',
                    'uptime'            => 'N/A',
                    'memory_usage'      => ['used' => 'N/A', 'max' => 'N/A', 'percentage' => 0],
                    'disk_usage'        => ['used' => 'N/A', 'available' => 'N/A', 'percentage' => 0],
                    'warnings'          => ['SOLR service is not available or not configured'],
                    'last_optimization' => null,
                ],
                'operations'   => [
                    'recent_activity'     => [],
                    'queue_status'        => ['pending_operations' => 0, 'processing' => false, 'last_processed' => null],
                    'commit_frequency'    => ['auto_commit' => false, 'commit_within' => 0, 'last_commit' => null],
                    'optimization_needed' => false,
                ],
                'generated_at' => date('c'),
                'error'        => $e->getMessage(),
            ];
        }//end try

    }//end getSolrDashboardStats()


    /**
     * Transform raw SOLR stats into dashboard structure
     *
     * @param array $rawStats Raw statistics from SOLR service
     *
     * @return (((bool|int|mixed|null|string)[]|bool|float|int|mixed|null|string)[]|mixed|string)[] Transformed dashboard statistics
     *
     * @psalm-return array{overview: array{available: bool, connection_status: 'unavailable'|'unknown'|mixed, response_time_ms: 0, total_documents: 0|mixed, index_size: string, last_commit: mixed|null}, cores: array{active_core: 'unknown'|mixed, core_status: 'active'|'inactive', endpoint_url: 'N/A'}, performance: array{total_searches: 0|mixed, total_indexes: 0|mixed, total_deletes: 0|mixed, avg_search_time_ms: 0|float, avg_index_time_ms: 0|float, total_search_time: 0|mixed, total_index_time: 0|mixed, operations_per_sec: 0|float, error_rate: 0|float}, health: array{status: 'unavailable'|'unknown'|mixed, uptime: 'N/A', memory_usage: array{used: 'N/A', max: 'N/A', percentage: 0}, disk_usage: array{used: 'N/A', available: 'N/A', percentage: 0}, warnings: list{0?: 'SOLR service is not available or not configured'|mixed}, last_optimization: null}, operations: array{recent_activity: array<never, never>, queue_status: array{pending_operations: 0, processing: false, last_processed: null}, commit_frequency: array{auto_commit: bool, commit_within: 0|1000, last_commit: mixed|null}, optimization_needed: false}, generated_at: string, error?: 'SOLR service unavailable'|mixed}
     */
    private function transformSolrStatsToDashboard(array $rawStats): array
    {
        // If SOLR is not available, return error structure.
        if (($rawStats['available'] ?? false) === false) {
            return [
                'overview'     => [
                    'available'         => false,
                    'connection_status' => 'unavailable',
                    'response_time_ms'  => 0,
                    'total_documents'   => 0,
                    'index_size'        => '0 B',
                    'last_commit'       => null,
                ],
                'cores'        => [
                    'active_core'  => 'unknown',
                    'core_status'  => 'inactive',
                    'endpoint_url' => 'N/A',
                ],
                'performance'  => [
                    'total_searches'     => 0,
                    'total_indexes'      => 0,
                    'total_deletes'      => 0,
                    'avg_search_time_ms' => 0,
                    'avg_index_time_ms'  => 0,
                    'total_search_time'  => 0,
                    'total_index_time'   => 0,
                    'operations_per_sec' => 0,
                    'error_rate'         => 0,
                ],
                'health'       => [
                    'status'            => 'unavailable',
                    'uptime'            => 'N/A',
                    'memory_usage'      => ['used' => 'N/A', 'max' => 'N/A', 'percentage' => 0],
                    'disk_usage'        => ['used' => 'N/A', 'available' => 'N/A', 'percentage' => 0],
                    'warnings'          => [$rawStats['error'] ?? 'SOLR service is not available or not configured'],
                    'last_optimization' => null,
                ],
                'operations'   => [
                    'recent_activity'     => [],
                    'queue_status'        => ['pending_operations' => 0, 'processing' => false, 'last_processed' => null],
                    'commit_frequency'    => ['auto_commit' => false, 'commit_within' => 0, 'last_commit' => null],
                    'optimization_needed' => false,
                ],
                'generated_at' => date('c'),
                'error'        => $rawStats['error'] ?? 'SOLR service unavailable',
            ];
        }//end if

        // Transform available SOLR stats into dashboard structure.
        $serviceStats = $rawStats['service_stats'] ?? [];
        $totalOps     = ($serviceStats['searches'] ?? 0) + ($serviceStats['indexes'] ?? 0) + ($serviceStats['deletes'] ?? 0);
        $totalTime    = ($serviceStats['search_time'] ?? 0) + ($serviceStats['index_time'] ?? 0);
        $opsPerSec    = $totalTime > 0 ? round($totalOps / ($totalTime / 1000), 2) : 0;
        $errorRate    = $totalOps > 0 ? round(($serviceStats['errors'] ?? 0) / $totalOps * 100, 2) : 0;

        return [
            'overview'     => [
                'available'         => true,
                'connection_status' => $rawStats['health'] ?? 'unknown',
                'response_time_ms'  => 0,
        // Not available in raw stats.
                'total_documents'   => $rawStats['document_count'] ?? 0,
                'index_size'        => $this->formatBytesForDashboard(($rawStats['index_size'] ?? 0) * 1024),
        // Assuming KB.
                'last_commit'       => $rawStats['last_modified'] ?? null,
            ],
            'cores'        => [
                'active_core'  => $rawStats['collection'] ?? 'unknown',
                'core_status'  => $rawStats['available'] ? 'active' : 'inactive',
                'endpoint_url' => 'N/A',
            // Endpoint URL no longer available in SettingsService (use GuzzleSolrService directly)
            ],
            'performance'  => [
                'total_searches'     => $serviceStats['searches'] ?? 0,
                'total_indexes'      => $serviceStats['indexes'] ?? 0,
                'total_deletes'      => $serviceStats['deletes'] ?? 0,
                'avg_search_time_ms' => ($serviceStats['searches'] ?? 0) > 0 ? round(($serviceStats['search_time'] ?? 0) / ($serviceStats['searches'] ?? 1), 2) : 0,
                'avg_index_time_ms'  => ($serviceStats['indexes'] ?? 0) > 0 ? round(($serviceStats['index_time'] ?? 0) / ($serviceStats['indexes'] ?? 1), 2) : 0,
                'total_search_time'  => $serviceStats['search_time'] ?? 0,
                'total_index_time'   => $serviceStats['index_time'] ?? 0,
                'operations_per_sec' => $opsPerSec,
                'error_rate'         => $errorRate,
            ],
            'health'       => [
                'status'            => $rawStats['health'] ?? 'unknown',
                'uptime'            => 'N/A',
            // Not available in raw stats.
                'memory_usage'      => ['used' => 'N/A', 'max' => 'N/A', 'percentage' => 0],
                'disk_usage'        => ['used' => 'N/A', 'available' => 'N/A', 'percentage' => 0],
                'warnings'          => [],
                'last_optimization' => null,
            ],
            'operations'   => [
                'recent_activity'     => [],
                'queue_status'        => ['pending_operations' => 0, 'processing' => false, 'last_processed' => null],
                'commit_frequency'    => ['auto_commit' => true, 'commit_within' => 1000, 'last_commit' => $rawStats['last_modified'] ?? null],
                'optimization_needed' => false,
            ],
            'generated_at' => date('c'),
        ];

    }//end transformSolrStatsToDashboard()


    /**
     * Format bytes to human readable format for dashboard
     *
     * @param int $bytes Number of bytes
     *
     * @return string Formatted byte string
     */
    private function formatBytesForDashboard(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units  = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1);

        return round($bytes / pow(1024, $factor), 2).' '.$units[$factor];

    }//end formatBytesForDashboard()


    /**
     * Get focused SOLR settings only
     *
     * @return (bool|int|mixed|null|string)[] SOLR configuration with tenant information
     *
     * @throws \RuntimeException If SOLR settings retrieval fails
     *
     * @psalm-return array{enabled: false|mixed, host: 'solr'|mixed, port: 8983|mixed, path: '/solr'|mixed, core: 'openregister'|mixed, configSet: '_default'|mixed, scheme: 'http'|mixed, username: 'solr'|mixed, password: 'SolrRocks'|mixed, timeout: 30|mixed, autoCommit: mixed|true, commitWithin: 1000|mixed, enableLogging: mixed|true, zookeeperHosts: 'zookeeper:2181'|mixed, zookeeperUsername: ''|mixed, zookeeperPassword: ''|mixed, collection: 'openregister'|mixed, useCloud: mixed|true, objectCollection: mixed|null, fileCollection: mixed|null}
     */
    public function getSolrSettingsOnly(): array
    {
        try {
            $solrConfig = $this->config->getAppValue($this->appName, 'solr', '');

            if (empty($solrConfig) === true) {
                return [
                    'enabled'           => false,
                    'host'              => 'solr',
                    'port'              => 8983,
                    'path'              => '/solr',
                    'core'              => 'openregister',
                    'configSet'         => '_default',
                    'scheme'            => 'http',
                    'username'          => 'solr',
                    'password'          => 'SolrRocks',
                    'timeout'           => 30,
                    'autoCommit'        => true,
                    'commitWithin'      => 1000,
                    'enableLogging'     => true,
                    'zookeeperHosts'    => 'zookeeper:2181',
                    'zookeeperUsername' => '',
                    'zookeeperPassword' => '',
                    'collection'        => 'openregister',
                    'useCloud'          => true,
                    'objectCollection'  => null,
                    'fileCollection'    => null,
                ];
            }//end if

            $solrData = json_decode($solrConfig, true);
            return [
                'enabled'           => $solrData['enabled'] ?? false,
                'host'              => $solrData['host'] ?? 'solr',
                'port'              => $solrData['port'] ?? 8983,
                'path'              => $solrData['path'] ?? '/solr',
                'core'              => $solrData['core'] ?? 'openregister',
                'configSet'         => $solrData['configSet'] ?? '_default',
                'scheme'            => $solrData['scheme'] ?? 'http',
                'username'          => $solrData['username'] ?? 'solr',
                'password'          => $solrData['password'] ?? 'SolrRocks',
                'timeout'           => $solrData['timeout'] ?? 30,
                'autoCommit'        => $solrData['autoCommit'] ?? true,
                'commitWithin'      => $solrData['commitWithin'] ?? 1000,
                'enableLogging'     => $solrData['enableLogging'] ?? true,
                'zookeeperHosts'    => $solrData['zookeeperHosts'] ?? 'zookeeper:2181',
                'zookeeperUsername' => $solrData['zookeeperUsername'] ?? '',
                'zookeeperPassword' => $solrData['zookeeperPassword'] ?? '',
                'collection'        => $solrData['collection'] ?? 'openregister',
                'useCloud'          => $solrData['useCloud'] ?? true,
                'objectCollection'  => $solrData['objectCollection'] ?? null,
                'fileCollection'    => $solrData['fileCollection'] ?? null,
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve SOLR settings: '.$e->getMessage());
        }//end try

    }//end getSolrSettingsOnly()


    /**
     * Update SOLR settings only
     *
     * @param array $solrData SOLR configuration data
     *
     * @return (bool|int|mixed|null|string)[] Updated SOLR configuration
     *
     * @throws \RuntimeException If SOLR settings update fails
     *
     * @psalm-return array{enabled: false|mixed, host: 'solr'|mixed, port: int, path: '/solr'|mixed, core: 'openregister'|mixed, configSet: '_default'|mixed, scheme: 'http'|mixed, username: 'solr'|mixed, password: 'SolrRocks'|mixed, timeout: int, autoCommit: mixed|true, commitWithin: int, enableLogging: mixed|true, zookeeperHosts: 'zookeeper:2181'|mixed, zookeeperUsername: ''|mixed, zookeeperPassword: ''|mixed, collection: 'openregister'|mixed, useCloud: mixed|true, objectCollection: mixed|null, fileCollection: mixed|null}
     */
    public function updateSolrSettingsOnly(array $solrData): array
    {
        try {
            $solrConfig = [
                'enabled'           => $solrData['enabled'] ?? false,
                'host'              => $solrData['host'] ?? 'solr',
                'port'              => (int) ($solrData['port'] ?? 8983),
                'path'              => $solrData['path'] ?? '/solr',
                'core'              => $solrData['core'] ?? 'openregister',
                'configSet'         => $solrData['configSet'] ?? '_default',
                'scheme'            => $solrData['scheme'] ?? 'http',
                'username'          => $solrData['username'] ?? 'solr',
                'password'          => $solrData['password'] ?? 'SolrRocks',
                'timeout'           => (int) ($solrData['timeout'] ?? 30),
                'autoCommit'        => $solrData['autoCommit'] ?? true,
                'commitWithin'      => (int) ($solrData['commitWithin'] ?? 1000),
                'enableLogging'     => $solrData['enableLogging'] ?? true,
                'zookeeperHosts'    => $solrData['zookeeperHosts'] ?? 'zookeeper:2181',
                'zookeeperUsername' => $solrData['zookeeperUsername'] ?? '',
                'zookeeperPassword' => $solrData['zookeeperPassword'] ?? '',
                'collection'        => $solrData['collection'] ?? 'openregister',
                'useCloud'          => $solrData['useCloud'] ?? true,
            // Collection assignments for objects and files.
                'objectCollection'  => $solrData['objectCollection'] ?? null,
                'fileCollection'    => $solrData['fileCollection'] ?? null,
            ];

            $this->config->setAppValue($this->appName, 'solr', json_encode($solrConfig));
            return $solrConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update SOLR settings: '.$e->getMessage());
        }//end try

    }//end updateSolrSettingsOnly()


    /**
     * Get SOLR facet configuration
     *
     * Returns the configuration for customizing SOLR facets including
     * custom titles, ordering, and descriptions.
     *
     * @return array Facet configuration array
     *
     * @throws \RuntimeException If facet configuration retrieval fails
     */
    public function getSolrFacetConfiguration(): array
    {
        try {
            $facetConfig = $this->config->getAppValue($this->appName, 'solr_facet_config', '');
            if (empty($facetConfig) === true) {
                return [
                    'facets'           => [],
                    'global_order'     => [],
                    'default_settings' => [
                        'show_count' => true,
                        'show_empty' => false,
                        'max_items'  => 10,
                    ],
                ];
            }

            return json_decode($facetConfig, true);
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve SOLR facet configuration: '.$e->getMessage());
        }

    }//end getSolrFacetConfiguration()


    /**
     * Update SOLR facet configuration
     *
     * Updates the configuration for customizing SOLR facets including
     * custom titles, ordering, and descriptions.
     *
     * Expected structure:
     * [
     *   'facets' => [
     *     'field_name' => [
     *       'title' => 'Custom Title',
     *       'description' => 'Custom description',
     *       'order' => 1,
     *       'enabled' => true,
     *       'show_count' => true,
     *       'max_items' => 10
     *     ]
     *   ],
     *   'global_order' => ['field1', 'field2', 'field3'],
     *   'default_settings' => [
     *     'show_count' => true,
     *     'show_empty' => false,
     *     'max_items' => 10
     *   ]
     * ]
     *
     * @param array $facetConfig Facet configuration data
     *
     * @return array Updated facet configuration
     *
     * @throws \RuntimeException If facet configuration update fails
     */
    public function updateSolrFacetConfiguration(array $facetConfig): array
    {
        try {
            // Validate the configuration structure.
            $validatedConfig = $this->validateFacetConfiguration($facetConfig);

            $this->config->setAppValue($this->appName, 'solr_facet_config', json_encode($validatedConfig));
            return $validatedConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update SOLR facet configuration: '.$e->getMessage());
        }

    }//end updateSolrFacetConfiguration()


    /**
     * Validate facet configuration structure
     *
     * @param array $config Configuration to validate
     *
     * @return ((bool|int|mixed|string)[]|bool|int|string)[][] Validated and normalized configuration
     *
     * @throws \InvalidArgumentException If configuration is invalid
     *
     * @psalm-return array{facets: array<string, array{title: mixed|string, description: ''|mixed, order: int, enabled: bool, show_count: bool, max_items: int}>, global_order: array<string>, default_settings: array{show_count: bool, show_empty: bool, max_items: int}}
     */
    private function validateFacetConfiguration(array $config): array
    {
        $validatedConfig = [
            'facets'           => [],
            'global_order'     => [],
            'default_settings' => [
                'show_count' => true,
                'show_empty' => false,
                'max_items'  => 10,
            ],
        ];

        // Validate facets configuration.
        if (($config['facets'] ?? null) !== null && is_array($config['facets']) === true) {
            foreach ($config['facets'] as $fieldName => $facetConfig) {
                if (is_string($fieldName) === false || empty($fieldName) === true) {
                    continue;
                }

                $validatedFacet = [
                    'title'       => $facetConfig['title'] ?? $fieldName,
                    'description' => $facetConfig['description'] ?? '',
                    'order'       => (int) ($facetConfig['order'] ?? 0),
                    'enabled'     => (bool) ($facetConfig['enabled'] ?? true),
                    'show_count'  => (bool) ($facetConfig['show_count'] ?? true),
                    'max_items'   => (int) ($facetConfig['max_items'] ?? 10),
                ];

                $validatedConfig['facets'][$fieldName] = $validatedFacet;
            }
        }

        // Validate global order.
        if (($config['global_order'] ?? null) !== null && is_array($config['global_order']) === true) {
            $validatedConfig['global_order'] = array_filter($config['global_order'], 'is_string');
        }

        // Validate default settings.
        if (($config['default_settings'] ?? null) !== null && is_array($config['default_settings']) === true) {
            $defaults = $config['default_settings'];
            $validatedConfig['default_settings'] = [
                'show_count' => (bool) ($defaults['show_count'] ?? true),
                'show_empty' => (bool) ($defaults['show_empty'] ?? false),
                'max_items'  => (int) ($defaults['max_items'] ?? 10),
            ];
        }

        return $validatedConfig;

    }//end validateFacetConfiguration()


    /**
     * Get focused RBAC settings only
     *
     * @return array[] RBAC configuration with available groups and users
     *
     * @throws \RuntimeException If RBAC settings retrieval fails
     *
     * @psalm-return array{rbac: array{enabled: false|mixed, anonymousGroup: 'public'|mixed, defaultNewUserGroup: 'viewer'|mixed, defaultObjectOwner: ''|mixed, adminOverride: mixed|true}, availableGroups: array, availableUsers: array}
     */
    public function getRbacSettingsOnly(): array
    {
        try {
            $rbacConfig = $this->config->getAppValue($this->appName, 'rbac', '');

            $rbacData = [];
            if (empty($rbacConfig) === true) {
                $rbacData = [
                    'enabled'             => false,
                    'anonymousGroup'      => 'public',
                    'defaultNewUserGroup' => 'viewer',
                    'defaultObjectOwner'  => '',
                    'adminOverride'       => true,
                ];
            } else {
                $storedData = json_decode($rbacConfig, true);
                $rbacData   = [
                    'enabled'             => $storedData['enabled'] ?? false,
                    'anonymousGroup'      => $storedData['anonymousGroup'] ?? 'public',
                    'defaultNewUserGroup' => $storedData['defaultNewUserGroup'] ?? 'viewer',
                    'defaultObjectOwner'  => $storedData['defaultObjectOwner'] ?? '',
                    'adminOverride'       => $storedData['adminOverride'] ?? true,
                ];
            }

            return [
                'rbac'            => $rbacData,
                'availableGroups' => $this->getAvailableGroups(),
                'availableUsers'  => $this->getAvailableUsers(),
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve RBAC settings: '.$e->getMessage());
        }//end try

    }//end getRbacSettingsOnly()


    /**
     * Update RBAC settings only
     *
     * @param array $rbacData RBAC configuration data
     *
     * @return array[] Updated RBAC configuration
     *
     * @throws \RuntimeException If RBAC settings update fails
     *
     * @psalm-return array{rbac: array{enabled: false|mixed, anonymousGroup: 'public'|mixed, defaultNewUserGroup: 'viewer'|mixed, defaultObjectOwner: ''|mixed, adminOverride: mixed|true}, availableGroups: array, availableUsers: array}
     */
    public function updateRbacSettingsOnly(array $rbacData): array
    {
        try {
            $rbacConfig = [
                'enabled'             => $rbacData['enabled'] ?? false,
                'anonymousGroup'      => $rbacData['anonymousGroup'] ?? 'public',
                'defaultNewUserGroup' => $rbacData['defaultNewUserGroup'] ?? 'viewer',
                'defaultObjectOwner'  => $rbacData['defaultObjectOwner'] ?? '',
                'adminOverride'       => $rbacData['adminOverride'] ?? true,
            ];

            $this->config->setAppValue($this->appName, 'rbac', json_encode($rbacConfig));

            return [
                'rbac'            => $rbacConfig,
                'availableGroups' => $this->getAvailableGroups(),
                'availableUsers'  => $this->getAvailableUsers(),
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update RBAC settings: '.$e->getMessage());
        }

    }//end updateRbacSettingsOnly()


    /**
     * Get Organisation settings only
     *
     * @return (mixed|null|true)[][] Organisation configuration
     *
     * @throws \RuntimeException If Organisation settings retrieval fails
     *
     * @psalm-return array{organisation: array{default_organisation: mixed|null, auto_create_default_organisation: mixed|true}}
     */
    public function getOrganisationSettingsOnly(): array
    {
        try {
            $organisationConfig = $this->config->getAppValue($this->appName, 'organisation', '');

            $organisationData = [];
            if (empty($organisationConfig) === true) {
                $organisationData = [
                    'default_organisation'             => null,
                    'auto_create_default_organisation' => true,
                ];
            } else {
                $storedData       = json_decode($organisationConfig, true);
                $organisationData = [
                    'default_organisation'             => $storedData['default_organisation'] ?? null,
                    'auto_create_default_organisation' => $storedData['auto_create_default_organisation'] ?? true,
                ];
            }

            return [
                'organisation' => $organisationData,
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve Organisation settings: '.$e->getMessage());
        }//end try

    }//end getOrganisationSettingsOnly()


    /**
     * Update Organisation settings only
     *
     * @param array $organisationData Organisation configuration data
     *
     * @return (mixed|null|true)[][] Updated Organisation configuration
     *
     * @throws \RuntimeException If Organisation settings update fails
     *
     * @psalm-return array{organisation: array{default_organisation: mixed|null, auto_create_default_organisation: mixed|true}}
     */
    public function updateOrganisationSettingsOnly(array $organisationData): array
    {
        try {
            $organisationConfig = [
                'default_organisation'             => $organisationData['default_organisation'] ?? null,
                'auto_create_default_organisation' => $organisationData['auto_create_default_organisation'] ?? true,
            ];

            $this->config->setAppValue($this->appName, 'organisation', json_encode($organisationConfig));

            return [
                'organisation' => $organisationConfig,
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update Organisation settings: '.$e->getMessage());
        }

    }//end updateOrganisationSettingsOnly()


    /**
     * Get default organisation UUID from settings
     *
     * @return string|null Default organisation UUID or null if not set
     */
    public function getDefaultOrganisationUuid(): ?string
    {
        try {
            $settings = $this->getOrganisationSettingsOnly();
            return $settings['organisation']['default_organisation'] ?? null;
        } catch (Exception $e) {
            $this->logger->warning('Failed to get default organisation UUID: '.$e->getMessage());
            return null;
        }

    }//end getDefaultOrganisationUuid()


    /**
     * Get tenant ID from multitenancy settings
     *
     * @return string|null Tenant ID (default user tenant) or null if not set
     */
    public function getTenantId(): ?string
    {
        try {
            $multitenancySettings = $this->getMultitenancySettingsOnly();
            return $multitenancySettings['multitenancy']['defaultUserTenant'] ?? null;
        } catch (Exception $e) {
            $this->logger->warning('Failed to get tenant ID: '.$e->getMessage());
            return null;
        }

    }//end getTenantId()


    /**
     * Get organisation ID (alias for getDefaultOrganisationUuid)
     *
     * @return string|null Organisation ID or null if not set
     */
    public function getOrganisationId(): ?string
    {
        return $this->getDefaultOrganisationUuid();

    }//end getOrganisationId()


    /**
     * Set default organisation UUID in settings
     *
     * @param  string|null $uuid Default organisation UUID
     * @return void
     */
    public function setDefaultOrganisationUuid(?string $uuid): void
    {
        try {
            $settings = $this->getOrganisationSettingsOnly();
            $settings['organisation']['default_organisation'] = $uuid;
            $this->updateOrganisationSettingsOnly($settings['organisation']);
        } catch (Exception $e) {
            $this->logger->error('Failed to set default organisation UUID: '.$e->getMessage());
        }

    }//end setDefaultOrganisationUuid()


    /**
     * Get focused Multitenancy settings only
     *
     * @return array Multitenancy configuration with available tenants
     * @throws \RuntimeException If Multitenancy settings retrieval fails
     */


    /**
     * Get multitenancy settings only (detailed implementation)
     *
     * @return array[] Multitenancy configuration settings
     *
     * @psalm-return array{multitenancy: array{enabled: false|mixed, defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed, publishedObjectsBypassMultiTenancy: false|mixed, adminOverride: mixed|true}, availableTenants: array}
     */
    public function getMultitenancySettingsOnly(): array
    {
        try {
            $multitenancyConfig = $this->config->getAppValue($this->appName, 'multitenancy', '');

            $multitenancyData = [];
            if (empty($multitenancyConfig) === true) {
                $multitenancyData = [
                    'enabled'                            => false,
                    'defaultUserTenant'                  => '',
                    'defaultObjectTenant'                => '',
                    'publishedObjectsBypassMultiTenancy' => false,
                    'adminOverride'                      => true,
                ];
            } else {
                $storedData       = json_decode($multitenancyConfig, true);
                $multitenancyData = [
                    'enabled'                            => $storedData['enabled'] ?? false,
                    'defaultUserTenant'                  => $storedData['defaultUserTenant'] ?? '',
                    'defaultObjectTenant'                => $storedData['defaultObjectTenant'] ?? '',
                    'publishedObjectsBypassMultiTenancy' => $storedData['publishedObjectsBypassMultiTenancy'] ?? false,
                    'adminOverride'                      => $storedData['adminOverride'] ?? true,
                ];
            }

            return [
                'multitenancy'     => $multitenancyData,
                'availableTenants' => $this->getAvailableOrganisations(),
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve Multitenancy settings: '.$e->getMessage());
        }//end try

    }//end getMultitenancySettingsOnly()


    /**
     * Update Multitenancy settings only
     *
     * @param array $multitenancyData Multitenancy configuration data
     *
     * @return array[] Updated Multitenancy configuration
     *
     * @throws \RuntimeException If Multitenancy settings update fails
     *
     * @psalm-return array{multitenancy: array{enabled: false|mixed, defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed, publishedObjectsBypassMultiTenancy: false|mixed, adminOverride: mixed|true}, availableTenants: array}
     */
    public function updateMultitenancySettingsOnly(array $multitenancyData): array
    {
        try {
            $multitenancyConfig = [
                'enabled'                            => $multitenancyData['enabled'] ?? false,
                'defaultUserTenant'                  => $multitenancyData['defaultUserTenant'] ?? '',
                'defaultObjectTenant'                => $multitenancyData['defaultObjectTenant'] ?? '',
                'publishedObjectsBypassMultiTenancy' => $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false,
                'adminOverride'                      => $multitenancyData['adminOverride'] ?? true,
            ];

            $this->config->setAppValue($this->appName, 'multitenancy', json_encode($multitenancyConfig));

            return [
                'multitenancy'     => $multitenancyConfig,
                'availableTenants' => $this->getAvailableOrganisations(),
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update Multitenancy settings: '.$e->getMessage());
        }

    }//end updateMultitenancySettingsOnly()


    /**
     * Get LLM settings only
     *
     * @return array LLM configuration
     * @throws \RuntimeException If LLM settings retrieval fails
     */
    public function getLLMSettingsOnly(): array
    {
        try {
            $llmConfig = $this->config->getAppValue($this->appName, 'llm', '');

            if (empty($llmConfig) === true) {
                // Return default configuration.
                return [
                    'enabled'           => false,
                    'embeddingProvider' => null,
                    'chatProvider'      => null,
                    'openaiConfig'      => [
                        'apiKey'         => '',
                        'model'          => null,
                        'chatModel'      => null,
                        'organizationId' => '',
                    ],
                    'ollamaConfig'      => [
                        'url'       => 'http://localhost:11434',
                        'model'     => null,
                        'chatModel' => null,
                    ],
                    'fireworksConfig'   => [
                        'apiKey'         => '',
                        'embeddingModel' => null,
                        'chatModel'      => null,
                        'baseUrl'        => 'https://api.fireworks.ai/inference/v1',
                    ],
                    'vectorConfig'      => [
                        'backend'   => 'php',
                        'solrField' => '_embedding_',
                    ],
                ];
            }//end if

            $decoded = json_decode($llmConfig, true);

            // Ensure enabled field exists (for backward compatibility).
            if (isset($decoded['enabled']) === false) {
                $decoded['enabled'] = false;
            }

            // Ensure vector config exists (for backward compatibility).
            if (isset($decoded['vectorConfig']) === false) {
                $decoded['vectorConfig'] = [
                    'backend'   => 'php',
                    'solrField' => '_embedding_',
                ];
            } else {
                // Ensure all vector config fields exist.
                if (isset($decoded['vectorConfig']['backend']) === false) {
                    $decoded['vectorConfig']['backend'] = 'php';
                }

                if (isset($decoded['vectorConfig']['solrField']) === false) {
                    $decoded['vectorConfig']['solrField'] = '_embedding_';
                }

                // Remove deprecated solrCollection if it exists.
                unset($decoded['vectorConfig']['solrCollection']);
            }

            return $decoded;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve LLM settings: '.$e->getMessage());
        }//end try

    }//end getLLMSettingsOnly()


    /**
     * Update LLM settings only
     *
     * @param array $llmData LLM configuration data
     *
     * @return ((mixed|null|string)[]|false|mixed|null)[] Updated LLM configuration
     *
     * @throws \RuntimeException
     *
     * @psalm-return array{enabled: false|mixed, embeddingProvider: mixed|null, chatProvider: mixed|null, openaiConfig: array{apiKey: ''|mixed, model: mixed|null, chatModel: mixed|null, organizationId: ''|mixed}, ollamaConfig: array{url: 'http://localhost:11434'|mixed, model: mixed|null, chatModel: mixed|null}, fireworksConfig: array{apiKey: ''|mixed, embeddingModel: mixed|null, chatModel: mixed|null, baseUrl: 'https://api.fireworks.ai/inference/v1'|mixed}, vectorConfig: array{backend: 'php'|mixed, solrField: '_embedding_'|mixed}}
     */
    public function updateLLMSettingsOnly(array $llmData): array
    {
        try {
            // Get existing config for PATCH support.
            $existingConfig = $this->getLLMSettingsOnly();

            // Merge with existing config (PATCH behavior).
            $llmConfig = [
                'enabled'           => $llmData['enabled'] ?? $existingConfig['enabled'] ?? false,
                'embeddingProvider' => $llmData['embeddingProvider'] ?? $existingConfig['embeddingProvider'] ?? null,
                'chatProvider'      => $llmData['chatProvider'] ?? $existingConfig['chatProvider'] ?? null,
                'openaiConfig'      => [
                    'apiKey'         => $llmData['openaiConfig']['apiKey'] ?? $existingConfig['openaiConfig']['apiKey'] ?? '',
                    'model'          => $llmData['openaiConfig']['model'] ?? $existingConfig['openaiConfig']['model'] ?? null,
                    'chatModel'      => $llmData['openaiConfig']['chatModel'] ?? $existingConfig['openaiConfig']['chatModel'] ?? null,
                    'organizationId' => $llmData['openaiConfig']['organizationId'] ?? $existingConfig['openaiConfig']['organizationId'] ?? '',
                ],
                'ollamaConfig'      => [
                    'url'       => $llmData['ollamaConfig']['url'] ?? $existingConfig['ollamaConfig']['url'] ?? 'http://localhost:11434',
                    'model'     => $llmData['ollamaConfig']['model'] ?? $existingConfig['ollamaConfig']['model'] ?? null,
                    'chatModel' => $llmData['ollamaConfig']['chatModel'] ?? $existingConfig['ollamaConfig']['chatModel'] ?? null,
                ],
                'fireworksConfig'   => [
                    'apiKey'         => $llmData['fireworksConfig']['apiKey'] ?? $existingConfig['fireworksConfig']['apiKey'] ?? '',
                    'embeddingModel' => $llmData['fireworksConfig']['embeddingModel'] ?? $existingConfig['fireworksConfig']['embeddingModel'] ?? null,
                    'chatModel'      => $llmData['fireworksConfig']['chatModel'] ?? $existingConfig['fireworksConfig']['chatModel'] ?? null,
                    'baseUrl'        => $llmData['fireworksConfig']['baseUrl'] ?? $existingConfig['fireworksConfig']['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1',
                ],
                'vectorConfig'      => [
                    'backend'   => $llmData['vectorConfig']['backend'] ?? $existingConfig['vectorConfig']['backend'] ?? 'php',
                    'solrField' => $llmData['vectorConfig']['solrField'] ?? $existingConfig['vectorConfig']['solrField'] ?? '_embedding_',
                ],
            ];

            $this->config->setAppValue($this->appName, 'llm', json_encode($llmConfig));
            return $llmConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update LLM settings: '.$e->getMessage());
        }//end try

    }//end updateLLMSettingsOnly()


    /**
     * Get File Management settings only
     *
     * @return array File management configuration
     * @throws \RuntimeException If File Management settings retrieval fails
     */
    public function getFileSettingsOnly(): array
    {
        try {
            $fileConfig = $this->config->getAppValue($this->appName, 'fileManagement', '');

            if (empty($fileConfig) === true) {
                // Return default configuration.
                return [
                    'vectorizationEnabled' => false,
                    'provider'             => null,
                    'chunkingStrategy'     => 'RECURSIVE_CHARACTER',
                    'chunkSize'            => 1000,
                    'chunkOverlap'         => 200,
                // LLPhant-friendly defaults: native PHP support + common library-based formats.
                    'enabledFileTypes'     => ['txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'],
                    'ocrEnabled'           => false,
                    'maxFileSizeMB'        => 100,
                // Text extraction settings (for FileConfiguration component).
                    'extractionScope'      => 'objects',
                // none, all, folders, objects.
                    'textExtractor'        => 'llphant',
                // llphant, dolphin.
                    'extractionMode'       => 'background',
                // background, immediate, manual.
                    'maxFileSize'          => 100,
                    'batchSize'            => 10,
                    'dolphinApiEndpoint'   => '',
                    'dolphinApiKey'        => '',
                ];
            }//end if

            return json_decode($fileConfig, true);
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve File Management settings: '.$e->getMessage());
        }//end try

    }//end getFileSettingsOnly()


    /**
     * Update File Management settings only
     *
     * @param array $fileData File management configuration data
     *
     * @return (false|int|mixed|null|string|string[])[] Updated file management configuration
     *
     * @throws \RuntimeException
     *
     * @psalm-return array{vectorizationEnabled: false|mixed, provider: mixed|null, chunkingStrategy: 'RECURSIVE_CHARACTER'|mixed, chunkSize: 1000|mixed, chunkOverlap: 200|mixed, enabledFileTypes: list{'txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'}|mixed, ocrEnabled: false|mixed, maxFileSizeMB: 100|mixed, extractionScope: 'objects'|mixed, textExtractor: 'llphant'|mixed, extractionMode: 'background'|mixed, maxFileSize: 100|mixed, batchSize: 10|mixed, dolphinApiEndpoint: ''|mixed, dolphinApiKey: ''|mixed}
     */
    public function updateFileSettingsOnly(array $fileData): array
    {
        try {
            $fileConfig = [
                'vectorizationEnabled' => $fileData['vectorizationEnabled'] ?? false,
                'provider'             => $fileData['provider'] ?? null,
                'chunkingStrategy'     => $fileData['chunkingStrategy'] ?? 'RECURSIVE_CHARACTER',
                'chunkSize'            => $fileData['chunkSize'] ?? 1000,
                'chunkOverlap'         => $fileData['chunkOverlap'] ?? 200,
                'enabledFileTypes'     => $fileData['enabledFileTypes'] ?? ['txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'],
                'ocrEnabled'           => $fileData['ocrEnabled'] ?? false,
                'maxFileSizeMB'        => $fileData['maxFileSizeMB'] ?? 100,
            // Text extraction settings (from FileConfiguration component).
                'extractionScope'      => $fileData['extractionScope'] ?? 'objects',
            // none, all, folders, objects.
                'textExtractor'        => $fileData['textExtractor'] ?? 'llphant',
            // llphant, dolphin.
                'extractionMode'       => $fileData['extractionMode'] ?? 'background',
            // background, immediate, manual.
                'maxFileSize'          => $fileData['maxFileSize'] ?? 100,
                'batchSize'            => $fileData['batchSize'] ?? 10,
                'dolphinApiEndpoint'   => $fileData['dolphinApiEndpoint'] ?? '',
                'dolphinApiKey'        => $fileData['dolphinApiKey'] ?? '',
            ];

            $this->config->setAppValue($this->appName, 'fileManagement', json_encode($fileConfig));
            return $fileConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update File Management settings: '.$e->getMessage());
        }//end try

    }//end updateFileSettingsOnly()


    /**
     * Update Object Management settings only
     *
     * @param  array $objectData Object management configuration data
     * @return array Updated object management configuration
     * @throws \RuntimeException
     */


    /**
     * Get focused Object settings only (vectorization config)
     *
     * @return (array|bool|int|mixed)[] Object vectorization configuration
     *
     * @throws \RuntimeException If Object settings retrieval fails
     *
     * @psalm-return array{vectorizationEnabled: false|mixed, vectorizeOnCreate: mixed|true, vectorizeOnUpdate: false|mixed, vectorizeAllViews: mixed|true, enabledViews: array<never, never>|mixed, includeMetadata: mixed|true, includeRelations: mixed|true, maxNestingDepth: 10|mixed, batchSize: 25|mixed, autoRetry: mixed|true}
     */
    public function getObjectSettingsOnly(): array
    {
        try {
            $objectConfig = $this->config->getAppValue($this->appName, 'objectManagement', '');

            if (empty($objectConfig) === true) {
                return [
                    'vectorizationEnabled' => false,
                    'vectorizeOnCreate'    => true,
                    'vectorizeOnUpdate'    => false,
                    'vectorizeAllViews'    => true,
                    'enabledViews'         => [],
                    'includeMetadata'      => true,
                    'includeRelations'     => true,
                    'maxNestingDepth'      => 10,
                    'batchSize'            => 25,
                    'autoRetry'            => true,
                ];
            }

            $objectData = json_decode($objectConfig, true);
            return [
                'vectorizationEnabled' => $objectData['vectorizationEnabled'] ?? false,
                'vectorizeOnCreate'    => $objectData['vectorizeOnCreate'] ?? true,
                'vectorizeOnUpdate'    => $objectData['vectorizeOnUpdate'] ?? false,
                'vectorizeAllViews'    => $objectData['vectorizeAllViews'] ?? ($objectData['vectorizeAllSchemas'] ?? true),
                'enabledViews'         => $objectData['enabledViews'] ?? ($objectData['enabledSchemas'] ?? []),
                'includeMetadata'      => $objectData['includeMetadata'] ?? true,
                'includeRelations'     => $objectData['includeRelations'] ?? true,
                'maxNestingDepth'      => $objectData['maxNestingDepth'] ?? 10,
                'batchSize'            => $objectData['batchSize'] ?? 25,
                'autoRetry'            => $objectData['autoRetry'] ?? true,
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to get Object Management settings: '.$e->getMessage());
        }//end try

    }//end getObjectSettingsOnly()


    /**
     * Update object management settings
     *
     * @param array $objectData Object data containing settings to update
     *
     * @return (array|bool|int|mixed)[] Updated object configuration
     *
     * @throws \RuntimeException If update fails
     *
     * @psalm-return array{vectorizationEnabled: false|mixed, vectorizeOnCreate: mixed|true, vectorizeOnUpdate: false|mixed, vectorizeAllViews: mixed|true, enabledViews: array<never, never>|mixed, includeMetadata: mixed|true, includeRelations: mixed|true, maxNestingDepth: 10|mixed, batchSize: 25|mixed, autoRetry: mixed|true}
     */
    public function updateObjectSettingsOnly(array $objectData): array
    {
        try {
            $objectConfig = [
                'vectorizationEnabled' => $objectData['vectorizationEnabled'] ?? false,
                'vectorizeOnCreate'    => $objectData['vectorizeOnCreate'] ?? true,
                'vectorizeOnUpdate'    => $objectData['vectorizeOnUpdate'] ?? false,
                'vectorizeAllViews'    => $objectData['vectorizeAllViews'] ?? true,
                'enabledViews'         => $objectData['enabledViews'] ?? [],
                'includeMetadata'      => $objectData['includeMetadata'] ?? true,
                'includeRelations'     => $objectData['includeRelations'] ?? true,
                'maxNestingDepth'      => $objectData['maxNestingDepth'] ?? 10,
                'batchSize'            => $objectData['batchSize'] ?? 25,
                'autoRetry'            => $objectData['autoRetry'] ?? true,
            ];

            $this->config->setAppValue($this->appName, 'objectManagement', json_encode($objectConfig));
            return $objectConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update Object Management settings: '.$e->getMessage());
        }

    }//end updateObjectSettingsOnly()


    /**
     * Get focused Retention settings only
     *
     * @return (bool|int|mixed)[] Retention configuration
     *
     * @throws \RuntimeException If Retention settings retrieval fails
     *
     * @psalm-return array{objectArchiveRetention: 31536000000|mixed, objectDeleteRetention: 63072000000|mixed, searchTrailRetention: 2592000000|mixed, createLogRetention: 2592000000|mixed, readLogRetention: 86400000|mixed, updateLogRetention: 604800000|mixed, deleteLogRetention: 2592000000|mixed, auditTrailsEnabled: bool, searchTrailsEnabled: bool}
     */
    public function getRetentionSettingsOnly(): array
    {
        try {
            $retentionConfig = $this->config->getAppValue($this->appName, 'retention', '');

            if (empty($retentionConfig) === true) {
                return [
                    'objectArchiveRetention' => 31536000000,
                // 1 year default
                    'objectDeleteRetention'  => 63072000000,
                // 2 years default
                    'searchTrailRetention'   => 2592000000,
                // 1 month default
                    'createLogRetention'     => 2592000000,
                // 1 month default
                    'readLogRetention'       => 86400000,
                // 24 hours default
                    'updateLogRetention'     => 604800000,
                // 1 week default
                    'deleteLogRetention'     => 2592000000,
                // 1 month default
                    'auditTrailsEnabled'     => true,
                // Audit trails enabled by default.
                    'searchTrailsEnabled'    => true,
                // Search trails enabled by default.
                ];
            }//end if

            $retentionData = json_decode($retentionConfig, true);
            return [
                'objectArchiveRetention' => $retentionData['objectArchiveRetention'] ?? 31536000000,
                'objectDeleteRetention'  => $retentionData['objectDeleteRetention'] ?? 63072000000,
                'searchTrailRetention'   => $retentionData['searchTrailRetention'] ?? 2592000000,
                'createLogRetention'     => $retentionData['createLogRetention'] ?? 2592000000,
                'readLogRetention'       => $retentionData['readLogRetention'] ?? 86400000,
                'updateLogRetention'     => $retentionData['updateLogRetention'] ?? 604800000,
                'deleteLogRetention'     => $retentionData['deleteLogRetention'] ?? 2592000000,
                'auditTrailsEnabled'     => $this->convertToBoolean($retentionData['auditTrailsEnabled'] ?? true),
                'searchTrailsEnabled'    => $this->convertToBoolean($retentionData['searchTrailsEnabled'] ?? true),
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve Retention settings: '.$e->getMessage());
        }//end try

    }//end getRetentionSettingsOnly()


    /**
     * Update Retention settings only
     *
     * @param array $retentionData Retention configuration data
     *
     * @return (int|mixed|true)[] Updated Retention configuration
     *
     * @throws \RuntimeException If Retention settings update fails
     *
     * @psalm-return array{objectArchiveRetention: 31536000000|mixed, objectDeleteRetention: 63072000000|mixed, searchTrailRetention: 2592000000|mixed, createLogRetention: 2592000000|mixed, readLogRetention: 86400000|mixed, updateLogRetention: 604800000|mixed, deleteLogRetention: 2592000000|mixed, auditTrailsEnabled: mixed|true, searchTrailsEnabled: mixed|true}
     */
    public function updateRetentionSettingsOnly(array $retentionData): array
    {
        try {
            $retentionConfig = [
                'objectArchiveRetention' => $retentionData['objectArchiveRetention'] ?? 31536000000,
                'objectDeleteRetention'  => $retentionData['objectDeleteRetention'] ?? 63072000000,
                'searchTrailRetention'   => $retentionData['searchTrailRetention'] ?? 2592000000,
                'createLogRetention'     => $retentionData['createLogRetention'] ?? 2592000000,
                'readLogRetention'       => $retentionData['readLogRetention'] ?? 86400000,
                'updateLogRetention'     => $retentionData['updateLogRetention'] ?? 604800000,
                'deleteLogRetention'     => $retentionData['deleteLogRetention'] ?? 2592000000,
                'auditTrailsEnabled'     => $retentionData['auditTrailsEnabled'] ?? true,
                'searchTrailsEnabled'    => $retentionData['searchTrailsEnabled'] ?? true,
            ];

            $this->config->setAppValue($this->appName, 'retention', json_encode($retentionConfig));
            return $retentionConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update Retention settings: '.$e->getMessage());
        }

    }//end updateRetentionSettingsOnly()


    /**
     * Get version information only
     *
     * @return string[] Version information
     *
     * @throws \RuntimeException If version information retrieval fails
     *
     * @psalm-return array{appName: 'Open Register', appVersion: '0.2.3'}
     */
    public function getVersionInfoOnly(): array
    {
        try {
            return [
                'appName'    => 'Open Register',
                'appVersion' => '0.2.3',
            ];
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve version information: '.$e->getMessage());
        }

    }//end getVersionInfoOnly()


    /**
     * Convert various representations to boolean
     *
     * @param mixed $value The value to convert to boolean
     *
     * @return bool The boolean representation
     */
    private function convertToBoolean($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_string($value) === true) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        if (is_numeric($value) === true) {
            return (int) $value !== 0;
        }

        return (bool) $value;

    }//end convertToBoolean()


}//end class
