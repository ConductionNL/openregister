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
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use OCP\AppFramework\Http\JSONResponse;
use OC_App;
use OCA\OpenRegister\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
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
     * SettingsService constructor.
     *
     * @param IAppConfig              $config                  App configuration interface.
     * @param IConfig                 $systemConfig            System configuration interface.
     * @param IRequest                $request                 Request interface.
     * @param ContainerInterface      $container               Container for dependency injection.
     * @param IAppManager             $appManager              App manager interface.
     * @param IGroupManager           $groupManager            Group manager interface.
     * @param IUserManager            $userManager             User manager interface.
     * @param OrganisationMapper      $organisationMapper      Organisation mapper for database operations.
     * @param AuditTrailMapper        $auditTrailMapper        Audit trail mapper for database operations.
     * @param SearchTrailMapper       $searchTrailMapper       Search trail mapper for database operations.
     * @param ObjectEntityMapper      $objectEntityMapper      Object entity mapper for database operations.
     * @param SchemaCacheService      $schemaCacheService      Schema cache service for cache management.
     * @param SchemaFacetCacheService $schemaFacetCacheService Schema facet cache service for cache management.
     * @param ICacheFactory           $cacheFactory            Cache factory for distributed cache access.
     * @param LoggerInterface         $logger                  Logger for error and warning logging.
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IConfig $systemConfig,
        private readonly IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly OrganisationMapper $organisationMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SearchTrailMapper $searchTrailMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaCacheService $schemaCacheService,
        private readonly SchemaFacetCacheService $schemaFacetCacheService,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
    ) {
        // Indulge in setting the application name for identification and configuration purposes.
        $this->appName = 'openregister';

    }//end __construct()


    /**
     * Checks if OpenRegister is installed and meets version requirements.
     *
     * @param string|null $minVersion Minimum required version (e.g. '1.0.0').
     *
     * @return bool True if OpenRegister is installed and meets version requirements.
     */
    public function isOpenRegisterInstalled(?string $minVersion=self::MIN_OPENREGISTER_VERSION): bool
    {
        if ($this->appManager->isInstalled(self::OPENREGISTER_APP_ID) === false) {
            return false;
        }

        if ($minVersion === null) {
            return true;
        }

        $currentVersion = $this->appManager->getAppVersion(self::OPENREGISTER_APP_ID);
        return version_compare($currentVersion, $minVersion, '>=');

    }//end isOpenRegisterInstalled()


    /**
     * Checks if OpenRegister is enabled.
     *
     * @return bool True if OpenRegister is enabled.
     */
    public function isOpenRegisterEnabled(): bool
    {
        return $this->appManager->isEnabled(self::OPENREGISTER_APP_ID) === true;

    }//end isOpenRegisterEnabled()


    /**
     * Check if RBAC is enabled
     *
     * @return bool True if RBAC is enabled, false otherwise
     */
    public function isRbacEnabled(): bool
    {
        $rbacConfig = $this->config->getValueString($this->appName, 'rbac', '');
        if (empty($rbacConfig)) {
            return false;
        }

        $rbacData = json_decode($rbacConfig, true);
        return $rbacData['enabled'] ?? false;

    }//end isRbacEnabled()


    /**
     * Check if multi-tenancy is enabled
     *
     * @return bool True if multi-tenancy is enabled, false otherwise
     */
    public function isMultiTenancyEnabled(): bool
    {
        $multitenancyConfig = $this->config->getValueString($this->appName, 'multitenancy', '');
        if (empty($multitenancyConfig)) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['enabled'] ?? false;

    }//end isMultiTenancyEnabled()


    /**
     * Check if published objects should bypass multi-tenancy restrictions
     *
     * @return bool True if published objects should bypass multi-tenancy, false otherwise
     */
    public function shouldPublishedObjectsBypassMultiTenancy(): bool
    {
        $multitenancyConfig = $this->config->getValueString($this->appName, 'multitenancy', '');
        if (empty($multitenancyConfig)) {
            return false;
            // Default to false for security
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false;

    }//end shouldPublishedObjectsBypassMultiTenancy()


    /**
     * Retrieve the current settings including RBAC and Multitenancy.
     *
     * @return array The current settings configuration.
     * @throws \RuntimeException If settings retrieval fails.
     */
    public function getSettings(): array
    {
        try {
            $data = [];

            // Version information
            $data['version'] = [
                'appName'    => 'Open Register',
                'appVersion' => '0.2.3',
            ];

            // RBAC Settings
            $rbacConfig = $this->config->getValueString($this->appName, 'rbac', '');
            if (empty($rbacConfig)) {
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

            // Multitenancy Settings
            $multitenancyConfig = $this->config->getValueString($this->appName, 'multitenancy', '');
            if (empty($multitenancyConfig)) {
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

            // Get available Nextcloud groups
            $data['availableGroups'] = $this->getAvailableGroups();

            // Get available organisations as tenants
            $data['availableTenants'] = $this->getAvailableOrganisations();

            // Get available users
            $data['availableUsers'] = $this->getAvailableUsers();

            // Retention Settings with defaults
            $retentionConfig = $this->config->getValueString($this->appName, 'retention', '');
            if (empty($retentionConfig)) {
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
                // Audit trails enabled by default
                    'searchTrailsEnabled'    => true,
                // Search trails enabled by default
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

            // SOLR Search Configuration
            $solrConfig = $this->config->getValueString($this->appName, 'solr', '');

            if (empty($solrConfig)) {
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
     * @return array Array of group_id => group_name
     */
    private function getAvailableGroups(): array
    {
        $groups = [];

        // Add special "public" group for anonymous users
        $groups['public'] = 'Public (No restrictions)';

        // Get all Nextcloud groups
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
            // Return empty array if organisations are not available
            return [];
        }

    }//end getAvailableOrganisations()


    /**
     * Get available users.
     *
     * @return array Array of user_id => user_display_name
     */
    private function getAvailableUsers(): array
    {
        $users = [];

        // Get all Nextcloud users (limit to prevent performance issues)
        $nextcloudUsers = $this->userManager->search('', 100);
        foreach ($nextcloudUsers as $user) {
            $users[$user->getUID()] = $user->getDisplayName() ?: $user->getUID();
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
            // Handle RBAC settings
            if (isset($data['rbac'])) {
                $rbacData = $data['rbac'];
                // Always store RBAC config with enabled state
                $rbacConfig = [
                    'enabled'             => $rbacData['enabled'] ?? false,
                    'anonymousGroup'      => $rbacData['anonymousGroup'] ?? 'public',
                    'defaultNewUserGroup' => $rbacData['defaultNewUserGroup'] ?? 'viewer',
                    'defaultObjectOwner'  => $rbacData['defaultObjectOwner'] ?? '',
                    'adminOverride'       => $rbacData['adminOverride'] ?? true,
                ];
                $this->config->setValueString($this->appName, 'rbac', json_encode($rbacConfig));
            }

            // Handle Multitenancy settings
            if (isset($data['multitenancy'])) {
                $multitenancyData = $data['multitenancy'];
                // Always store Multitenancy config with enabled state
                $multitenancyConfig = [
                    'enabled'                            => $multitenancyData['enabled'] ?? false,
                    'defaultUserTenant'                  => $multitenancyData['defaultUserTenant'] ?? '',
                    'defaultObjectTenant'                => $multitenancyData['defaultObjectTenant'] ?? '',
                    'publishedObjectsBypassMultiTenancy' => $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false,
                    'adminOverride'                      => $multitenancyData['adminOverride'] ?? true,
                ];
                $this->config->setValueString($this->appName, 'multitenancy', json_encode($multitenancyConfig));
            }

            // Handle Retention settings
            if (isset($data['retention'])) {
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
                $this->config->setValueString($this->appName, 'retention', json_encode($retentionConfig));
            }

            // Handle SOLR settings
            if (isset($data['solr'])) {
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
                $this->config->setValueString($this->appName, 'solr', json_encode($solrConfig));
            }//end if

            // Return the updated settings
            return $this->getSettings();
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update settings: '.$e->getMessage());
        }//end try

    }//end updateSettings()


    /**
     * Get the current publishing options.
     *
     * @return array The current publishing options configuration.
     * @throws \RuntimeException If publishing options retrieval fails.
     */
    public function getPublishingOptions(): array
    {
        try {
            // Retrieve publishing options from configuration with defaults to false.
            $publishingOptions = [
                // Convert string 'true'/'false' to boolean for auto publish attachments setting.
                'auto_publish_attachments'      => $this->config->getValueString($this->appName, 'auto_publish_attachments', 'false') === 'true',
                // Convert string 'true'/'false' to boolean for auto publish objects setting.
                'auto_publish_objects'          => $this->config->getValueString($this->appName, 'auto_publish_objects', 'false') === 'true',
                // Convert string 'true'/'false' to boolean for old style publishing view setting.
                'use_old_style_publishing_view' => $this->config->getValueString($this->appName, 'use_old_style_publishing_view', 'false') === 'true',
            ];

            return $publishingOptions;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to retrieve publishing options: '.$e->getMessage());
        }

    }//end getPublishingOptions()


    /**
     * Update the publishing options configuration.
     *
     * @param array $options The publishing options data to update.
     *
     * @return array The updated publishing options configuration.
     * @throws \RuntimeException If publishing options update fails.
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
                    $this->config->setValueString($this->appName, $option, $value);
                    // Retrieve and convert back to boolean for the response.
                    $updatedOptions[$option] = $this->config->getValueString($this->appName, $option) === 'true';
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
     * @return array Array containing the rebase operation results
     * @throws \RuntimeException If the rebase operation fails
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

            // Get current settings
            $settings = $this->getSettings();

            // Assign default owners and organizations to objects that don't have them
            if (!empty($settings['rbac']['defaultObjectOwner']) || !empty($settings['multitenancy']['defaultObjectTenant'])) {
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

            // Set expiry dates based on retention settings
            $retention = $settings['retention'] ?? [];
            $results['retentionResults'] = [];

            try {
                // Set expiry dates for audit trails (simplified - using first available retention)
                $auditRetention = $retention['createLogRetention'] ?? $retention['readLogRetention'] ?? $retention['updateLogRetention'] ?? $retention['deleteLogRetention'] ?? 0;
                if ($auditRetention > 0) {
                    $auditUpdated = $this->auditTrailMapper->setExpiryDate($auditRetention);
                    $results['retentionResults']['auditTrailsUpdated'] = $auditUpdated;
                }

                // Set expiry dates for search trails
                if (isset($retention['searchTrailRetention']) && $retention['searchTrailRetention'] > 0) {
                    $searchUpdated = $this->searchTrailMapper->setExpiryDate($retention['searchTrailRetention']);
                    $results['retentionResults']['searchTrailsUpdated'] = $searchUpdated;
                }

                // Set expiry dates for objects (based on deleted date + retention)
                if (isset($retention['objectDeleteRetention']) && $retention['objectDeleteRetention'] > 0) {
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
     * @return array Array containing warning counts and total counts for all tables
     * @throws \RuntimeException If statistics retrieval fails
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

            // Get database connection for optimized queries
            $db = $this->container->get('OCP\IDBConnection');

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
                    // Table might not exist, set to 0 and continue
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
     * @return array Comprehensive cache statistics from cache systems
     */
    public function getCacheStats(): array
    {
        try {
            // Get basic distributed cache info
            $distributedStats = $this->getDistributedCacheStats();
            $performanceStats = $this->getCachePerformanceMetrics();

            // Get object cache stats (only if ObjectCacheService provides them)
            // Use cached stats to avoid expensive operations on every request
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
                    // Not stored in database - would be performance issue
                        'hits'        => 0,
                        'requests'    => 0,
                        'memoryUsage' => 0,
                    ],
                    'facet'  => [
                        'entries'     => 0,
                    // Not stored in database - would be performance issue
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
            // Return safe defaults if cache stats unavailable
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
        // Use a simple in-memory cache with 30-second TTL to avoid expensive ObjectCacheService calls
        static $cachedStats = null;
        static $lastUpdate  = 0;

        $now = time();
        if ($cachedStats === null || ($now - $lastUpdate) > 30) {
            try {
                $objectCacheService = $this->container->get(ObjectCacheService::class);
                $cachedStats        = $objectCacheService->getStats();
            } catch (Exception $e) {
                // If no object cache stats available, use defaults
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
            }

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
     * @return array Distributed cache statistics
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
            // Most cache backends don't provide this
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
     * @return array Performance metrics
     */
    private function getCachePerformanceMetrics(): array
    {
        // This would typically come from a performance monitoring service
        // For now, return basic metrics
        return [
            'averageHitTime'  => 2.5,
        // ms
            'averageMissTime' => 850.0,
        // ms
            'performanceGain' => 340.0,
        // factor improvement with cache
            'optimalHitRate'  => 85.0,
        // target hit rate percentage
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
     * @return array Results of cache clearing operations
     * @throws \RuntimeException If cache clearing fails
     */
    public function clearCache(string $type='all', ?string $userId=null, array $options=[]): array
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

            // Calculate total cleared entries
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
     * @param string|null $userId Specific user ID
     *
     * @return array Clear operation results
     */
    private function clearObjectCache(?string $userId=null): array
    {
        try {
            $objectCacheService = $this->container->get(ObjectCacheService::class);
            $beforeStats        = $objectCacheService->getStats();
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
     * @return array Clear operation results
     */
    private function clearNamesCache(): array
    {
        try {
            $objectCacheService  = $this->container->get(ObjectCacheService::class);
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
     * @return array Warmup operation results
     */
    public function warmupNamesCache(): array
    {
        try {
            $startTime          = microtime(true);
            $objectCacheService = $this->container->get(ObjectCacheService::class);
            $beforeStats        = $objectCacheService->getStats();

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
     * @param string|null $userId Specific user ID
     *
     * @return array Clear operation results
     */
    private function clearSchemaCache(?string $userId=null): array
    {
        try {
            $beforeStats = $this->schemaCacheService->getCacheStatistics();
            $this->schemaCacheService->clearAllCaches();
            $afterStats = $this->schemaCacheService->getCacheStatistics();

            return [
                'service' => 'schema',
                'cleared' => $beforeStats['entries'] - $afterStats['entries'],
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
        }

    }//end clearSchemaCache()


    /**
     * Clear facet cache service
     *
     * @param string|null $userId Specific user ID
     *
     * @return array Clear operation results
     */
    private function clearFacetCache(?string $userId=null): array
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
     * @param string|null $userId Specific user ID (unused, kept for API compatibility)
     *
     * @return array Clear operation results
     *
     * @psalm-suppress UnusedParam
     */
    private function clearDistributedCache(?string $userId=null): array
    {
        try {
            $distributedCache = $this->cacheFactory->createDistributed('openregister');
            $distributedCache->clear();

            return [
                'service' => 'distributed',
                'cleared' => 'all',
            // Can't count distributed cache entries
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
            $solrConfig = $this->config->getValueString($this->appName, 'solr', '');
            if (empty($solrConfig)) {
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
     * Test SOLR connection with current settings (includes Zookeeper test for SolrCloud)
     *
     * @return array Connection test results with status and details
     */
    public function testSolrConnection(): array
    {
        try {
            // Delegate to GuzzleSolrService for consistent configuration and URL handling
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            return $guzzleSolrService->testConnection();
        } catch (Exception $e) {
            return [
                'success'    => false,
                'message'    => 'Connection test failed: '.$e->getMessage(),
                'details'    => [
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => $e->getTraceAsString(),
                ],
                'components' => [],
            ];
        }

    }//end testSolrConnection()


    /**
     * Test Zookeeper connectivity for SolrCloud
     *
     * @param  array $solrSettings SOLR configuration
     * @return array Zookeeper test results
     */
    private function testZookeeperConnection(array $solrSettings): array
    {
        try {
            $zookeeperHosts = $solrSettings['zookeeperHosts'] ?? 'zookeeper:2181';
            $hosts          = explode(',', $zookeeperHosts);

            $successfulHosts = [];
            $failedHosts     = [];

            foreach ($hosts as $host) {
                $host = trim($host);
                if (empty($host)) {
                    continue;
                }

                // Test Zookeeper connection using SOLR's Zookeeper API
                $url = sprintf(
                    '%s://%s:%d%s/admin/collections?action=CLUSTERSTATUS&wt=json',
                    $solrSettings['scheme'],
                    $solrSettings['host'],
                    $solrSettings['port'],
                    $solrSettings['path']
                );

                $context = stream_context_create(
                        [
                            'http' => [
                                'timeout' => 5,
                                'method'  => 'GET',
                            ],
                        ]
                        );

                $response = @file_get_contents($url, false, $context);

                if ($response !== false) {
                    $data = json_decode($response, true);
                    if (isset($data['cluster'])) {
                        $successfulHosts[] = $host;
                    } else {
                        $failedHosts[] = $host;
                    }
                } else {
                    $failedHosts[] = $host;
                }
            }//end foreach

            return [
                'success' => !empty($successfulHosts),
                'message' => !empty($successfulHosts) ? 'Zookeeper accessible via '.implode(', ', $successfulHosts) : 'Zookeeper not accessible via any host',
                'details' => [
                    'zookeeper_hosts'  => $zookeeperHosts,
                    'successful_hosts' => $successfulHosts,
                    'failed_hosts'     => $failedHosts,
                    'test_method'      => 'SOLR Collections API',
                ],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Zookeeper test failed: '.$e->getMessage(),
                'details' => [
                    'error'           => $e->getMessage(),
                    'zookeeper_hosts' => $solrSettings['zookeeperHosts'] ?? 'zookeeper:2181',
                ],
            ];
        }//end try

    }//end testZookeeperConnection()


    /**
     * Test SOLR connectivity
     *
     * @param  array $solrSettings SOLR configuration
     * @return array SOLR test results
     */
    private function testSolrConnectivity(array $solrSettings): array
    {
        try {
            // Build SOLR URL - handle Kubernetes service names properly
            $host = $solrSettings['host'];

            // Check if it's a Kubernetes service name (contains .svc.cluster.local)
            if (strpos($host, '.svc.cluster.local') !== false) {
                // Kubernetes service - don't append port, it's handled by the service
                $baseUrl = sprintf(
                    '%s://%s%s',
                    $solrSettings['scheme'],
                    $host,
                    $solrSettings['path']
                );
            } else {
                // Regular hostname - append port (default to 8983 if not provided)
                $port    = !empty($solrSettings['port']) ? $solrSettings['port'] : 8983;
                $baseUrl = sprintf(
                    '%s://%s:%d%s',
                    $solrSettings['scheme'],
                    $host,
                    $port,
                    $solrSettings['path']
                );
            }

            // Test basic SOLR connectivity with admin endpoints
            // Try multiple common SOLR admin endpoints for maximum compatibility
            $testEndpoints = [
                '/admin/ping?wt=json',
                '/solr/admin/ping?wt=json',
                '/admin/info/system?wt=json',
            ];

            $testUrl   = null;
            $testType  = 'admin_ping';
            $lastError = null;

            // Create HTTP context with timeout
            $context = stream_context_create(
                    [
                        'http' => [
                            'timeout' => 10,
                            'method'  => 'GET',
                            'header'  => [
                                'Accept: application/json',
                                'Content-Type: application/json',
                            ],
                        ],
                    ]
                    );

            // Try each endpoint until one works
            $response     = false;
            $responseTime = 0;

            foreach ($testEndpoints as $endpoint) {
                $testUrl      = $baseUrl.$endpoint;
                $startTime    = microtime(true);
                $response     = @file_get_contents($testUrl, false, $context);
                $responseTime = (microtime(true) - $startTime) * 1000;

                if ($response !== false) {
                    // Found a working endpoint
                    break;
                } else {
                    $lastError = "Failed to connect to: ".$testUrl;
                }
            }

            if ($response === false) {
                return [
                    'success' => false,
                    'message' => 'SOLR server not responding on any admin endpoint',
                    'details' => [
                        'tested_endpoints' => array_map(
                                function ($endpoint) use ($baseUrl) {
                                    return $baseUrl.$endpoint;
                                },
                                $testEndpoints
                                ),
                        'last_error'       => $lastError,
                        'test_type'        => $testType,
                        'response_time_ms' => round($responseTime, 2),
                    ],
                ];
            }

            $data = json_decode($response, true);

            // Validate admin response - be flexible about response format
            // Check for successful response - different endpoints have different formats
            $isValidResponse = false;

            if (isset($data['status']) && $data['status'] === 'OK') {
                // Standard ping response
                $isValidResponse = true;
            } else if (isset($data['responseHeader']['status']) && $data['responseHeader']['status'] === 0) {
                // System info response
                $isValidResponse = true;
            } else if (is_array($data) && !empty($data)) {
                // Any valid JSON response indicates SOLR is responding
                $isValidResponse = true;
            }

            if (!$isValidResponse) {
                return [
                    'success' => false,
                    'message' => 'SOLR admin endpoint returned invalid response',
                    'details' => [
                        'url'              => $testUrl,
                        'test_type'        => $testType,
                        'response'         => $data,
                        'response_time_ms' => round($responseTime, 2),
                    ],
                ];
            }

            return [
                'success' => true,
                'message' => 'SOLR server responding correctly',
                'details' => [
                    'url'              => $testUrl,
                    'test_type'        => $testType,
                    'response_time_ms' => round($responseTime, 2),
                    'solr_status'      => $data['status'] ?? 'OK',
                    'use_cloud'        => $solrSettings['useCloud'] ?? false,
                    'server_info'      => $data['responseHeader'] ?? [],
                    'working_endpoint' => str_replace($baseUrl, '', $testUrl),
                ],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'SOLR connectivity test failed: '.$e->getMessage(),
                'details' => [
                    'error'     => $e->getMessage(),
                    'url'       => $baseUrl ?? 'unknown',
                    'test_type' => $testType ?? 'unknown',
                ],
            ];
        }//end try

    }//end testSolrConnectivity()


    /**
     * Test SOLR collection/core availability
     *
     * @param  array $solrSettings SOLR configuration
     * @return array Collection test results
     */
    private function testSolrCollection(array $solrSettings): array
    {
        try {
            $collectionName = $solrSettings['collection'] ?? $solrSettings['core'] ?? 'openregister';

            // Build SOLR URL - handle Kubernetes service names properly
            $host = $solrSettings['host'];

            // Check if it's a Kubernetes service name (contains .svc.cluster.local)
            if (strpos($host, '.svc.cluster.local') !== false) {
                // Kubernetes service - don't append port, it's handled by the service
                $baseUrl = sprintf(
                    '%s://%s%s',
                    $solrSettings['scheme'],
                    $host,
                    $solrSettings['path']
                );
            } else {
                // Regular hostname - append port (default to 8983 if not provided)
                $port    = !empty($solrSettings['port']) ? $solrSettings['port'] : 8983;
                $baseUrl = sprintf(
                    '%s://%s:%d%s',
                    $solrSettings['scheme'],
                    $host,
                    $port,
                    $solrSettings['path']
                );
            }

            // For SolrCloud, test collection existence
            if ($solrSettings['useCloud'] ?? false) {
                $url = $baseUrl.'/admin/collections?action=CLUSTERSTATUS&wt=json';

                $context = stream_context_create(
                        [
                            'http' => [
                                'timeout' => 10,
                                'method'  => 'GET',
                            ],
                        ]
                        );

                $response = @file_get_contents($url, false, $context);

                if ($response === false) {
                    return [
                        'success' => false,
                        'message' => 'Failed to check collection status',
                        'details' => ['url' => $url],
                    ];
                }

                $data        = json_decode($response, true);
                $collections = $data['cluster']['collections'] ?? [];

                if (isset($collections[$collectionName])) {
                    return [
                        'success' => true,
                        'message' => "Collection '{$collectionName}' exists and is available",
                        'details' => [
                            'collection' => $collectionName,
                            'status'     => $collections[$collectionName]['status'] ?? 'unknown',
                            'shards'     => count($collections[$collectionName]['shards'] ?? []),
                        ],
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => "Collection '{$collectionName}' not found",
                        'details' => [
                            'collection'            => $collectionName,
                            'available_collections' => array_keys($collections),
                        ],
                    ];
                }
            } else {
                // For standalone SOLR, test core existence
                $url = $baseUrl.'/admin/cores?action=STATUS&core='.urlencode($collectionName).'&wt=json';

                $context = stream_context_create(
                        [
                            'http' => [
                                'timeout' => 10,
                                'method'  => 'GET',
                            ],
                        ]
                        );

                $response = @file_get_contents($url, false, $context);

                if ($response === false) {
                    return [
                        'success' => false,
                        'message' => 'Failed to check core status',
                        'details' => ['url' => $url],
                    ];
                }

                $data = json_decode($response, true);

                if (isset($data['status'][$collectionName])) {
                    return [
                        'success' => true,
                        'message' => "Core '{$collectionName}' exists and is available",
                        'details' => [
                            'core'   => $collectionName,
                            'status' => $data['status'][$collectionName]['status'] ?? 'unknown',
                        ],
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => "Core '{$collectionName}' not found",
                        'details' => [
                            'core'            => $collectionName,
                            'available_cores' => array_keys($data['status'] ?? []),
                        ],
                    ];
                }
            }//end if
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Collection test failed: '.$e->getMessage(),
                'details' => [
                    'error'      => $e->getMessage(),
                    'collection' => $solrSettings['collection'] ?? $solrSettings['core'] ?? 'openregister',
                ],
            ];
        }//end try

    }//end testSolrCollection()


    /**
     * Test SOLR collection query functionality
     *
     * @param  array $solrSettings SOLR configuration
     * @return array Query test results
     */
    private function testSolrQuery(array $solrSettings): array
    {
        try {
            $collectionName = $solrSettings['collection'] ?? $solrSettings['core'] ?? 'openregister';

            // Build SOLR URL - handle Kubernetes service names properly
            $host = $solrSettings['host'];

            // Check if it's a Kubernetes service name (contains .svc.cluster.local)
            if (strpos($host, '.svc.cluster.local') !== false) {
                // Kubernetes service - don't append port, it's handled by the service
                $baseUrl = sprintf(
                    '%s://%s%s',
                    $solrSettings['scheme'],
                    $host,
                    $solrSettings['path']
                );
            } else {
                // Regular hostname - append port (default to 8983 if not provided)
                $port    = !empty($solrSettings['port']) ? $solrSettings['port'] : 8983;
                $baseUrl = sprintf(
                    '%s://%s:%d%s',
                    $solrSettings['scheme'],
                    $host,
                    $port,
                    $solrSettings['path']
                );
            }

            // Test collection select query
            $testUrl = $baseUrl.'/'.$collectionName.'/select?q=*:*&rows=0&wt=json';

            $context = stream_context_create(
                    [
                        'http' => [
                            'timeout' => 10,
                            'method'  => 'GET',
                        ],
                    ]
                    );

            $startTime    = microtime(true);
            $response     = @file_get_contents($testUrl, false, $context);
            $responseTime = (microtime(true) - $startTime) * 1000;

            if ($response === false) {
                return [
                    'success' => false,
                    'message' => 'Collection query failed',
                    'details' => [
                        'url'              => $testUrl,
                        'collection'       => $collectionName,
                        'response_time_ms' => round($responseTime, 2),
                    ],
                ];
            }

            $data = json_decode($response, true);

            // Check for successful query response
            if (!isset($data['responseHeader']['status']) || $data['responseHeader']['status'] !== 0) {
                return [
                    'success' => false,
                    'message' => 'Collection query returned error',
                    'details' => [
                        'url'              => $testUrl,
                        'collection'       => $collectionName,
                        'response'         => $data,
                        'response_time_ms' => round($responseTime, 2),
                    ],
                ];
            }

            return [
                'success' => true,
                'message' => 'Collection query successful',
                'details' => [
                    'url'              => $testUrl,
                    'collection'       => $collectionName,
                    'response_time_ms' => round($responseTime, 2),
                    'num_found'        => $data['response']['numFound'] ?? 0,
                    'query_time'       => $data['responseHeader']['QTime'] ?? 0,
                ],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Query test failed: '.$e->getMessage(),
                'details' => [
                    'error'      => $e->getMessage(),
                    'collection' => $solrSettings['collection'] ?? $solrSettings['core'] ?? 'openregister',
                ],
            ];
        }//end try

    }//end testSolrQuery()


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
    public function warmupSolrIndex(int $batchSize=2000, int $maxObjects=0, string $mode='serial', bool $collectErrors=false): array
    {
        try {
            $solrSettings = $this->getSolrSettings();

            if (!$solrSettings['enabled']) {
                return [
                    'success' => false,
                    'message' => 'SOLR is disabled in settings',
                    'stats'   => [
                        'totalProcessed' => 0,
                        'totalIndexed'   => 0,
                        'totalErrors'    => 0,
                        'duration'       => 0,
                    ],
                ];
            }

            // Get SolrService for bulk indexing via direct DI
            $solrService = $this->container->get(GuzzleSolrService::class);

            if ($solrService === null) {
                return [
                    'success' => false,
                    'message' => 'SOLR service not available',
                    'stats'   => [
                        'totalProcessed' => 0,
                        'totalIndexed'   => 0,
                        'totalErrors'    => 0,
                        'duration'       => 0,
                    ],
                ];
            }

            $startTime = microtime(true);

            // Get all schemas for schema mirroring
            $schemas = [];
            try {
                $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
                $schemas      = $schemaMapper->findAll();
            } catch (Exception $e) {
                // Continue without schema mirroring if schema mapper is not available
                $this->logger->warning('Schema mapper not available for warmup', ['error' => $e->getMessage()]);
            }

            // **COMPLETE WARMUP**: Mirror schemas + index objects + cache warmup
            $warmupResult = $solrService->warmupIndex($schemas, $maxObjects, $mode, $collectErrors);

            $totalDuration = microtime(true) - $startTime;

            if ($warmupResult['success']) {
                $operations       = $warmupResult['operations'] ?? [];
                $indexed          = $operations['objects_indexed'] ?? 0;
                $schemasProcessed = $operations['schemas_processed'] ?? 0;
                $fieldsCreated    = $operations['fields_created'] ?? 0;
                $objectsPerSecond = $totalDuration > 0 ? round($indexed / $totalDuration, 2) : 0;

                return [
                    'success' => true,
                    'message' => 'SOLR complete warmup finished successfully',
                    'stats'   => [
                        'totalProcessed'    => $indexed,
                        'totalIndexed'      => $indexed,
                        'totalErrors'       => $operations['indexing_errors'] ?? 0,
                        'totalObjectsFound' => $warmupResult['total_objects_found'] ?? 0,
                        'batchesProcessed'  => $warmupResult['batches_processed'] ?? 0,
                        'maxObjectsLimit'   => $warmupResult['max_objects_limit'] ?? $maxObjects,
                        'duration'          => round($totalDuration, 2),
                        'objectsPerSecond'  => $objectsPerSecond,
                        'successRate'       => $indexed > 0 ? round((($indexed - ($operations['indexing_errors'] ?? 0)) / $indexed) * 100, 2) : 100.0,
                        'schemasProcessed'  => $schemasProcessed,
                        'fieldsCreated'     => $fieldsCreated,
                        'operations'        => $operations,
                    ],
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $warmupResult['error'] ?? 'SOLR complete warmup failed',
                    'stats'   => [
                        'totalProcessed' => 0,
                        'totalIndexed'   => 0,
                        'totalErrors'    => 1,
                        'duration'       => round($totalDuration, 2),
                        'operations'     => $warmupResult['operations'] ?? [],
                    ],
                ];
            }//end if
        } catch (Exception $e) {
            $this->logger->error(
                    'SOLR warmup failed with exception',
                    [
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                    ]
                    );

            // **ERROR COLLECTION MODE**: Return errors in response if collectErrors is true
            if ($collectErrors) {
                return [
                    'success' => false,
                    'message' => 'SOLR warmup failed with errors (collected mode)',
                    'stats'   => [
                        'totalProcessed'        => 0,
                        'totalIndexed'          => 0,
                        'totalErrors'           => 1,
                        'duration'              => microtime(true) - ($startTime ?? microtime(true)),
                        'error_collection_mode' => true,
                    ],
                    'errors'  => [
                        [
                            'type'      => 'warmup_exception',
                            'message'   => $e->getMessage(),
                            'class'     => get_class($e),
                            'file'      => $e->getFile(),
                            'line'      => $e->getLine(),
                            'timestamp' => date('c'),
                        ],
                    ],
                ];
            }//end if

            // **ERROR VISIBILITY**: Re-throw exception to expose errors in controller (default behavior)
            throw new \RuntimeException(
                'SOLR warmup failed: '.$e->getMessage(),
                0,
                $e
            );
        }//end try

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
            $objectCacheService = $this->container->get(ObjectCacheService::class);
            $rawStats           = $objectCacheService->getSolrDashboardStats();

            // Transform the raw stats into the expected dashboard structure
            return $this->transformSolrStatsToDashboard($rawStats);
        } catch (Exception $e) {
            // Return default dashboard structure if SOLR is not available
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
     * @param  array $rawStats Raw statistics from SOLR service
     * @return array Transformed dashboard statistics
     */
    private function transformSolrStatsToDashboard(array $rawStats): array
    {
        // If SOLR is not available, return error structure
        if (!($rawStats['available'] ?? false)) {
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

        // Transform available SOLR stats into dashboard structure
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
        // Not available in raw stats
                'total_documents'   => $rawStats['document_count'] ?? 0,
                'index_size'        => $this->formatBytesForDashboard(($rawStats['index_size'] ?? 0) * 1024),
        // Assuming KB
                'last_commit'       => $rawStats['last_modified'] ?? null,
            ],
            'cores'        => [
                'active_core'  => $rawStats['collection'] ?? 'unknown',
                'core_status'  => $rawStats['available'] ? 'active' : 'inactive',
                'endpoint_url' => $this->container->get(GuzzleSolrService::class)->getEndpointUrl($rawStats['collection'] ?? null),
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
            // Not available in raw stats
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
     * @param  int $bytes Number of bytes
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
     * Perform SOLR management operations
     *
     * Executes various SOLR index management operations including commit, optimize,
     * clear, and warmup with proper error handling and result reporting.
     *
     * @param string $operation Operation to perform (commit, optimize, clear, warmup)
     *
     * @return array Operation results with success status and details
     * @throws \InvalidArgumentException If operation is not supported
     */
    public function manageSolr(string $operation): array
    {
        try {
            $objectCacheService = $this->container->get(ObjectCacheService::class);

            switch ($operation) {
                case 'commit':
                    $result = $objectCacheService->commitSolr();
                    return [
                        'success'   => $result['success'] ?? false,
                        'operation' => 'commit',
                        'message'   => $result['success'] ? 'Index committed successfully' : 'Commit failed',
                        'details'   => $result,
                        'timestamp' => date('c'),
                    ];

                case 'optimize':
                    $result = $objectCacheService->optimizeSolr();
                    return [
                        'success'   => $result['success'] ?? false,
                        'operation' => 'optimize',
                        'message'   => $result['success'] ? 'Index optimized successfully' : 'Optimization failed',
                        'details'   => $result,
                        'timestamp' => date('c'),
                    ];

                case 'clear':
                    $result = $objectCacheService->clearSolrIndexForDashboard();
                    return [
                        'success'   => $result['success'] ?? false,
                        'operation' => 'clear',
                        'message'   => $result['success'] ? 'Index cleared successfully' : 'Clear operation failed',
                        'details'   => $result,
                        'timestamp' => date('c'),
                    ];

                case 'warmup':
                    return $this->warmupSolrIndex();

                default:
                    return [
                        'success'   => false,
                        'operation' => $operation,
                        'message'   => 'Unknown operation: '.$operation,
                        'timestamp' => date('c'),
                    ];
            }//end switch
        } catch (Exception $e) {
            return [
                'success'   => false,
                'operation' => $operation,
                'message'   => 'Operation failed: '.$e->getMessage(),
                'timestamp' => date('c'),
                'error'     => $e->getMessage(),
            ];
        }//end try

    }//end manageSolr()


    /**
     * Test SOLR connection and get comprehensive status information
     *
     * @deprecated Use GuzzleSolrService::testConnectionForDashboard() directly
     * @return     array Connection test results with detailed status information
     */
    public function testSolrConnectionForDashboard(): array
    {
        $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
        return $guzzleSolrService->testConnectionForDashboard();

    }//end testSolrConnectionForDashboard()


    /**
     * Get focused SOLR settings only
     *
     * @return array SOLR configuration with tenant information
     * @throws \RuntimeException If SOLR settings retrieval fails
     */
    public function getSolrSettingsOnly(): array
    {
        try {
            $solrConfig = $this->config->getValueString($this->appName, 'solr', '');

            if (empty($solrConfig)) {
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
     * @param  array $solrData SOLR configuration data
     * @return array Updated SOLR configuration
     * @throws \RuntimeException If SOLR settings update fails
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
                // Collection assignments for objects and files
                'objectCollection'  => $solrData['objectCollection'] ?? null,
                'fileCollection'    => $solrData['fileCollection'] ?? null,
            ];

            $this->config->setValueString($this->appName, 'solr', json_encode($solrConfig));
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
            $facetConfig = $this->config->getValueString($this->appName, 'solr_facet_config', '');
            if (empty($facetConfig)) {
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
            // Validate the configuration structure
            $validatedConfig = $this->validateFacetConfiguration($facetConfig);

            $this->config->setValueString($this->appName, 'solr_facet_config', json_encode($validatedConfig));
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
     * @return array Validated and normalized configuration
     *
     * @throws \InvalidArgumentException If configuration is invalid
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

        // Validate facets configuration
        if (isset($config['facets']) && is_array($config['facets'])) {
            foreach ($config['facets'] as $fieldName => $facetConfig) {
                if (!is_string($fieldName) || empty($fieldName)) {
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

        // Validate global order
        if (isset($config['global_order']) && is_array($config['global_order'])) {
            $validatedConfig['global_order'] = array_filter($config['global_order'], 'is_string');
        }

        // Validate default settings
        if (isset($config['default_settings']) && is_array($config['default_settings'])) {
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
     * @return array RBAC configuration with available groups and users
     * @throws \RuntimeException If RBAC settings retrieval fails
     */
    public function getRbacSettingsOnly(): array
    {
        try {
            $rbacConfig = $this->config->getValueString($this->appName, 'rbac', '');

            $rbacData = [];
            if (empty($rbacConfig)) {
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
     * @param  array $rbacData RBAC configuration data
     * @return array Updated RBAC configuration
     * @throws \RuntimeException If RBAC settings update fails
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

            $this->config->setValueString($this->appName, 'rbac', json_encode($rbacConfig));

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
     * @return array Organisation configuration
     * @throws \RuntimeException If Organisation settings retrieval fails
     */
    public function getOrganisationSettingsOnly(): array
    {
        try {
            $organisationConfig = $this->config->getValueString($this->appName, 'organisation', '');

            $organisationData = [];
            if (empty($organisationConfig)) {
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
     * @param  array $organisationData Organisation configuration data
     * @return array Updated Organisation configuration
     * @throws \RuntimeException If Organisation settings update fails
     */
    public function updateOrganisationSettingsOnly(array $organisationData): array
    {
        try {
            $organisationConfig = [
                'default_organisation'             => $organisationData['default_organisation'] ?? null,
                'auto_create_default_organisation' => $organisationData['auto_create_default_organisation'] ?? true,
            ];

            $this->config->setValueString($this->appName, 'organisation', json_encode($organisationConfig));

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
     * Get multitenancy settings (alias for getMultitenancySettingsOnly)
     *
     * @return array Multitenancy configuration settings
     */
    public function getMultitenancySettings(): array
    {
        return $this->getMultitenancySettingsOnly();

    }//end getMultitenancySettings()


    /**
     * Get multitenancy settings only (detailed implementation)
     *
     * @return array Multitenancy configuration settings
     */
    public function getMultitenancySettingsOnly(): array
    {
        try {
            $multitenancyConfig = $this->config->getValueString($this->appName, 'multitenancy', '');

            $multitenancyData = [];
            if (empty($multitenancyConfig)) {
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
     * @param  array $multitenancyData Multitenancy configuration data
     * @return array Updated Multitenancy configuration
     * @throws \RuntimeException If Multitenancy settings update fails
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

            $this->config->setValueString($this->appName, 'multitenancy', json_encode($multitenancyConfig));

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
            $llmConfig = $this->config->getValueString($this->appName, 'llm', '');

            if (empty($llmConfig) === true) {
                // Return default configuration
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

            // Ensure enabled field exists (for backward compatibility)
            if (isset($decoded['enabled']) === false) {
                $decoded['enabled'] = false;
            }

            // Ensure vector config exists (for backward compatibility)
            if (isset($decoded['vectorConfig']) === false) {
                $decoded['vectorConfig'] = [
                    'backend'   => 'php',
                    'solrField' => '_embedding_',
                ];
            } else {
                // Ensure all vector config fields exist
                if (isset($decoded['vectorConfig']['backend']) === false) {
                    $decoded['vectorConfig']['backend'] = 'php';
                }

                if (isset($decoded['vectorConfig']['solrField']) === false) {
                    $decoded['vectorConfig']['solrField'] = '_embedding_';
                }

                // Remove deprecated solrCollection if it exists
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
     * @param  array $llmData LLM configuration data
     * @return array Updated LLM configuration
     * @throws \RuntimeException
     */
    public function updateLLMSettingsOnly(array $llmData): array
    {
        try {
            // Get existing config for PATCH support
            $existingConfig = $this->getLLMSettingsOnly();

            // Merge with existing config (PATCH behavior)
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

            $this->config->setValueString($this->appName, 'llm', json_encode($llmConfig));
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
            $fileConfig = $this->config->getValueString($this->appName, 'fileManagement', '');

            if (empty($fileConfig) === true) {
                // Return default configuration
                return [
                    'vectorizationEnabled' => false,
                    'provider'             => null,
                    'chunkingStrategy'     => 'RECURSIVE_CHARACTER',
                    'chunkSize'            => 1000,
                    'chunkOverlap'         => 200,
                    // LLPhant-friendly defaults: native PHP support + common library-based formats
                    'enabledFileTypes'     => ['txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'],
                    'ocrEnabled'           => false,
                    'maxFileSizeMB'        => 100,
                    // Text extraction settings (for FileConfiguration component)
                    'extractionScope'      => 'objects',
                // none, all, folders, objects
                    'textExtractor'        => 'llphant',
                // llphant, dolphin
                    'extractionMode'       => 'background',
                // background, immediate, manual
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
     * @param  array $fileData File management configuration data
     * @return array Updated file management configuration
     * @throws \RuntimeException
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
                // Text extraction settings (from FileConfiguration component)
                'extractionScope'      => $fileData['extractionScope'] ?? 'objects',
            // none, all, folders, objects
                'textExtractor'        => $fileData['textExtractor'] ?? 'llphant',
            // llphant, dolphin
                'extractionMode'       => $fileData['extractionMode'] ?? 'background',
            // background, immediate, manual
                'maxFileSize'          => $fileData['maxFileSize'] ?? 100,
                'batchSize'            => $fileData['batchSize'] ?? 10,
                'dolphinApiEndpoint'   => $fileData['dolphinApiEndpoint'] ?? '',
                'dolphinApiKey'        => $fileData['dolphinApiKey'] ?? '',
            ];

            $this->config->setValueString($this->appName, 'fileManagement', json_encode($fileConfig));
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
     * @return array Object vectorization configuration
     * @throws \RuntimeException If Object settings retrieval fails
     */
    public function getObjectSettingsOnly(): array
    {
        try {
            $objectConfig = $this->config->getValueString($this->appName, 'objectManagement', '');

            if (empty($objectConfig)) {
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

            $this->config->setValueString($this->appName, 'objectManagement', json_encode($objectConfig));
            return $objectConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update Object Management settings: '.$e->getMessage());
        }

    }//end updateObjectSettingsOnly()


    /**
     * Get focused Retention settings only
     *
     * @return array Retention configuration
     * @throws \RuntimeException If Retention settings retrieval fails
     */
    public function getRetentionSettingsOnly(): array
    {
        try {
            $retentionConfig = $this->config->getValueString($this->appName, 'retention', '');

            if (empty($retentionConfig)) {
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
                // Audit trails enabled by default
                    'searchTrailsEnabled'    => true,
                // Search trails enabled by default
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
     * @param  array $retentionData Retention configuration data
     * @return array Updated Retention configuration
     * @throws \RuntimeException If Retention settings update fails
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

            $this->config->setValueString($this->appName, 'retention', json_encode($retentionConfig));
            return $retentionConfig;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update Retention settings: '.$e->getMessage());
        }

    }//end updateRetentionSettingsOnly()


    /**
     * Get version information only
     *
     * @return array Version information
     * @throws \RuntimeException If version information retrieval fails
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
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return (bool) $value;

    }//end convertToBoolean()


}//end class
