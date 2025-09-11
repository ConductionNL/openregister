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
use OCA\OpenRegister\Service\SolrServiceFactory;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCP\ICacheFactory;

/**
 * Service for handling settings-related operations.
 *
 * Provides functionality for retrieving, saving, and loading settings,
 * as well as managing configuration for different object types.
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
     * @param IAppConfig              $config                 App configuration interface.
     * @param IConfig                 $systemConfig           System configuration interface.
     * @param IRequest                $request                Request interface.
     * @param ContainerInterface      $container              Container for dependency injection.
     * @param IAppManager             $appManager             App manager interface.
     * @param IGroupManager           $groupManager           Group manager interface.
     * @param IUserManager            $userManager            User manager interface.
     * @param OrganisationMapper      $organisationMapper     Organisation mapper for database operations.
     * @param AuditTrailMapper        $auditTrailMapper       Audit trail mapper for database operations.
     * @param SearchTrailMapper       $searchTrailMapper      Search trail mapper for database operations.
     * @param ObjectEntityMapper      $objectEntityMapper     Object entity mapper for database operations.
     * @param SchemaCacheService      $schemaCacheService     Schema cache service for cache management.
     * @param SchemaFacetCacheService $schemaFacetCacheService Schema facet cache service for cache management.
     * @param ICacheFactory           $cacheFactory           Cache factory for distributed cache access.
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
        private readonly ICacheFactory $cacheFactory
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
                    'enabled'             => false,
                    'defaultUserTenant'   => '',
                    'defaultObjectTenant' => '',
                ];
            } else {
                $multitenancyData     = json_decode($multitenancyConfig, true);
                $data['multitenancy'] = [
                    'enabled'             => $multitenancyData['enabled'] ?? false,
                    'defaultUserTenant'   => $multitenancyData['defaultUserTenant'] ?? '',
                    'defaultObjectTenant' => $multitenancyData['defaultObjectTenant'] ?? '',
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
                ];
            }//end if

            // SOLR Search Configuration
            $solrConfig = $this->config->getValueString($this->appName, 'solr', '');
            $tenantId = $this->generateTenantId();
            
            if (empty($solrConfig)) {
                $data['solr'] = [
                    'enabled'        => false,
                    'host'           => 'solr',
                    'port'           => 8983,
                    'path'           => '/solr',
                    'core'           => 'openregister',
                    'scheme'         => 'http',
                    'username'       => '',
                    'password'       => '',
                    'timeout'        => 30,
                    'autoCommit'     => true,
                    'commitWithin'   => 1000,
                    'enableLogging'  => true,
                    'tenantId'       => $tenantId,
                ];
            } else {
                $solrData     = json_decode($solrConfig, true);
                $data['solr'] = [
                    'enabled'        => $solrData['enabled'] ?? false,
                    'host'           => $solrData['host'] ?? 'localhost',
                    'port'           => $solrData['port'] ?? 8983,
                    'path'           => $solrData['path'] ?? '/solr',
                    'core'           => $solrData['core'] ?? 'openregister',
                    'scheme'         => $solrData['scheme'] ?? 'http',
                    'username'       => $solrData['username'] ?? '',
                    'password'       => $solrData['password'] ?? '',
                    'timeout'        => $solrData['timeout'] ?? 30,
                    'autoCommit'     => $solrData['autoCommit'] ?? true,
                    'commitWithin'   => $solrData['commitWithin'] ?? 1000,
                    'enableLogging'  => $solrData['enableLogging'] ?? true,
                    'tenantId'       => $solrData['tenantId'] ?? $tenantId,
                ];
            }//end if

            return $data;
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
                    'enabled'             => $multitenancyData['enabled'] ?? false,
                    'defaultUserTenant'   => $multitenancyData['defaultUserTenant'] ?? '',
                    'defaultObjectTenant' => $multitenancyData['defaultObjectTenant'] ?? '',
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
                ];
                $this->config->setValueString($this->appName, 'retention', json_encode($retentionConfig));
            }

            // Handle SOLR settings
            if (isset($data['solr'])) {
                $solrData   = $data['solr'];
                $solrConfig = [
                    'enabled'        => $solrData['enabled'] ?? false,
                    'host'           => $solrData['host'] ?? 'localhost',
                    'port'           => (int) ($solrData['port'] ?? 8983),
                    'path'           => $solrData['path'] ?? '/solr',
                    'core'           => $solrData['core'] ?? 'openregister',
                    'scheme'         => $solrData['scheme'] ?? 'http',
                    'username'       => $solrData['username'] ?? '',
                    'password'       => $solrData['password'] ?? '',
                    'timeout'        => (int) ($solrData['timeout'] ?? 30),
                    'autoCommit'     => $solrData['autoCommit'] ?? true,
                    'commitWithin'   => (int) ($solrData['commitWithin'] ?? 1000),
                    'enableLogging'  => $solrData['enableLogging'] ?? true,
                    'tenantId'       => $solrData['tenantId'] ?? $this->generateTenantId(),
                ];
                $this->config->setValueString($this->appName, 'solr', json_encode($solrConfig));
            }

            // Return the updated settings
            return $this->getSettings();
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
                } catch (\Exception $e) {
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
            } catch (\Exception $e) {
                $error = 'Failed to set expiry dates: '.$e->getMessage();
                $results['errors'][] = $error;
            }//end try

            $results['endTime']  = new \DateTime();
            $results['duration'] = $results['endTime']->diff($startTime)->format('%H:%I:%S');
            $results['success']  = empty($results['errors']);

            return $results;
        } catch (\Exception $e) {
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
                    'totalObjects'                 => 0,
                    'totalAuditTrails'            => 0,
                    'totalSearchTrails'           => 0,
                    'totalConfigurations'         => 0,
                    'totalDataAccessProfiles'     => 0,
                    'totalOrganisations'          => 0,
                    'totalRegisters'              => 0,
                    'totalSchemas'                => 0,
                    'totalSources'                => 0,
                    'deletedObjects'              => 0,
                ],
                'sizes'       => [
                    'totalObjectsSize'            => 0,
                    'totalAuditTrailsSize'        => 0,
                    'totalSearchTrailsSize'       => 0,
                    'deletedObjectsSize'          => 0,
                    'expiredAuditTrailsSize'      => 0,
                    'expiredSearchTrailsSize'     => 0,
                    'expiredObjectsSize'          => 0,
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
            
            $result = $db->executeQuery($objectsQuery);
            $objectsData = $result->fetch();
            $result->closeCursor();
            
            $stats['totals']['totalObjects'] = (int) ($objectsData['total_objects'] ?? 0);
            $stats['sizes']['totalObjectsSize'] = (int) ($objectsData['total_size'] ?? 0);
            $stats['warnings']['objectsWithoutOwner'] = (int) ($objectsData['without_owner'] ?? 0);
            $stats['warnings']['objectsWithoutOrganisation'] = (int) ($objectsData['without_organisation'] ?? 0);
            $stats['totals']['deletedObjects'] = (int) ($objectsData['deleted_count'] ?? 0);
            $stats['sizes']['deletedObjectsSize'] = (int) ($objectsData['deleted_size'] ?? 0);
            $stats['warnings']['expiredObjects'] = (int) ($objectsData['expired_count'] ?? 0);
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
            
            $result = $db->executeQuery($auditQuery);
            $auditData = $result->fetch();
            $result->closeCursor();
            
            $stats['totals']['totalAuditTrails'] = (int) ($auditData['total_count'] ?? 0);
            $stats['sizes']['totalAuditTrailsSize'] = (int) ($auditData['total_size'] ?? 0);
            $stats['warnings']['auditTrailsWithoutExpiry'] = (int) ($auditData['without_expiry'] ?? 0);
            $stats['warnings']['expiredAuditTrails'] = (int) ($auditData['expired_count'] ?? 0);
            $stats['sizes']['expiredAuditTrailsSize'] = (int) ($auditData['expired_size'] ?? 0);

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
            
            $result = $db->executeQuery($searchQuery);
            $searchData = $result->fetch();
            $result->closeCursor();
            
            $stats['totals']['totalSearchTrails'] = (int) ($searchData['total_count'] ?? 0);
            $stats['sizes']['totalSearchTrailsSize'] = (int) ($searchData['total_size'] ?? 0);
            $stats['warnings']['searchTrailsWithoutExpiry'] = (int) ($searchData['without_expiry'] ?? 0);
            $stats['warnings']['expiredSearchTrails'] = (int) ($searchData['expired_count'] ?? 0);
            $stats['sizes']['expiredSearchTrailsSize'] = (int) ($searchData['expired_size'] ?? 0);

            // 4. All other tables - simple counts (these should be fast)
            $simpleCountTables = [
                'configurations' => '`*PREFIX*openregister_configurations`',
                'dataAccessProfiles' => '`*PREFIX*openregister_data_access_profiles`',
                'organisations' => '`*PREFIX*openregister_organisations`',
                'registers' => '`*PREFIX*openregister_registers`',
                'schemas' => '`*PREFIX*openregister_schemas`',
                'sources' => '`*PREFIX*openregister_sources`',
            ];

            foreach ($simpleCountTables as $key => $tableName) {
                try {
                    $countQuery = "SELECT COUNT(*) as total FROM {$tableName}";
                    $result = $db->executeQuery($countQuery);
                    $count = $result->fetchColumn();
                    $result->closeCursor();
                    
                    $stats['totals']['total' . ucfirst($key)] = (int) ($count ?? 0);
                } catch (\Exception $e) {
                    // Table might not exist, set to 0 and continue
                    $stats['totals']['total' . ucfirst($key)] = 0;
                }
            }

            return $stats;
        } catch (\Exception $e) {
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
            $objectStats = [];
            try {
                $objectCacheService = $this->container->get(ObjectCacheService::class);
                $objectStats = $objectCacheService->getStats();
            } catch (\Exception $e) {
                // If no object cache stats available, use defaults
                $objectStats = [
                    'entries' => 0,
                    'hits' => 0,
                    'requests' => 0,
                    'memoryUsage' => 0,
                    'name_cache_size' => 0,
                    'name_hit_rate' => 0.0,
                    'name_hits' => 0,
                    'name_misses' => 0,
                    'name_warmups' => 0,
                ];
            }
            
            $stats = [
                'overview' => [
                    'totalCacheSize' => $objectStats['memoryUsage'] ?? 0,
                    'totalCacheEntries' => $objectStats['entries'] ?? 0,
                    'overallHitRate' => $this->calculateHitRate($objectStats),
                    'averageResponseTime' => $performanceStats['averageHitTime'] ?? 0.0,
                    'cacheEfficiency' => $this->calculateHitRate($objectStats),
                ],
                'services' => [
                    'object' => [
                        'entries' => $objectStats['entries'] ?? 0,
                        'hits' => $objectStats['hits'] ?? 0,
                        'requests' => $objectStats['requests'] ?? 0,
                        'memoryUsage' => $objectStats['memoryUsage'] ?? 0,
                    ],
                    'schema' => [
                        'entries' => 0, // Not stored in database - would be performance issue
                        'hits' => 0,
                        'requests' => 0,
                        'memoryUsage' => 0,
                    ],
                    'facet' => [
                        'entries' => 0, // Not stored in database - would be performance issue
                        'hits' => 0,
                        'requests' => 0,
                        'memoryUsage' => 0,
                    ],
                ],
                'names' => [
                    'cache_size' => $objectStats['name_cache_size'] ?? 0,
                    'hit_rate' => $objectStats['name_hit_rate'] ?? 0.0,
                    'hits' => $objectStats['name_hits'] ?? 0,
                    'misses' => $objectStats['name_misses'] ?? 0,
                    'warmups' => $objectStats['name_warmups'] ?? 0,
                    'enabled' => true,
                ],
                'distributed' => $distributedStats,
                'performance' => $performanceStats,
                'lastUpdated' => (new \DateTime())->format('c'),
            ];

            return $stats;
        } catch (\Exception $e) {
            // Return safe defaults if cache stats unavailable
            return [
                'overview' => [
                    'totalCacheSize' => 0,
                    'totalCacheEntries' => 0,
                    'overallHitRate' => 0.0,
                    'averageResponseTime' => 0.0,
                    'cacheEfficiency' => 0.0,
                ],
                'services' => [
                    'object' => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                    'schema' => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                    'facet' => ['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0],
                ],
                'names' => [
                    'cache_size' => 0, 'hit_rate' => 0.0, 'hits' => 0, 'misses' => 0,
                    'warmups' => 0, 'enabled' => false,
                ],
                'distributed' => ['type' => 'none', 'backend' => 'Unknown', 'available' => false],
                'performance' => ['averageHitTime' => 0, 'averageMissTime' => 0, 'performanceGain' => 0, 'optimalHitRate' => 85.0],
                'lastUpdated' => (new \DateTime())->format('c'),
                'error' => 'Cache statistics unavailable: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Calculate hit rate from cache statistics
     *
     * @param array $stats Cache statistics array
     * @return float Hit rate percentage
     */
    private function calculateHitRate(array $stats): float
    {
        $requests = $stats['requests'] ?? 0;
        $hits = $stats['hits'] ?? 0;
        
        return $requests > 0 ? ($hits / $requests) * 100 : 0.0;
    }

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
                'type' => 'distributed',
                'backend' => get_class($distributedCache),
                'available' => true,
                'keyCount' => 'Unknown', // Most cache backends don't provide this
                'size' => 'Unknown',
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'none',
                'backend' => 'fallback',
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

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
            'averageHitTime' => 2.5, // ms
            'averageMissTime' => 850.0, // ms
            'performanceGain' => 340.0, // factor improvement with cache
            'optimalHitRate' => 85.0, // target hit rate percentage
            'currentTrend' => 'improving',
        ];
    }

    /**
     * Clear cache with granular control
     *
     * @param string      $type     Cache type: 'all', 'object', 'schema', 'facet', 'distributed', 'names'
     * @param string|null $userId   Specific user ID to clear cache for (if supported)
     * @param array       $options  Additional options for cache clearing
     *
     * @return array Results of cache clearing operations
     * @throws \RuntimeException If cache clearing fails
     */
    public function clearCache(string $type = 'all', ?string $userId = null, array $options = []): array
    {
        try {
            $results = [
                'type' => $type,
                'userId' => $userId,
                'timestamp' => (new \DateTime())->format('c'),
                'results' => [],
                'errors' => [],
                'totalCleared' => 0,
            ];

            switch ($type) {
                case 'all':
                    $results['results']['object'] = $this->clearObjectCache($userId);
                    $results['results']['schema'] = $this->clearSchemaCache($userId);
                    $results['results']['facet'] = $this->clearFacetCache($userId);
                    $results['results']['distributed'] = $this->clearDistributedCache($userId);
                    $results['results']['names'] = $this->clearNamesCache();
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
            }

            // Calculate total cleared entries
            foreach ($results['results'] as $serviceResult) {
                $results['totalCleared'] += $serviceResult['cleared'] ?? 0;
            }

            return $results;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to clear cache: '.$e->getMessage());
        }
    }

    /**
     * Clear object cache service
     *
     * @param string|null $userId Specific user ID
     *
     * @return array Clear operation results
     */
    private function clearObjectCache(?string $userId = null): array
    {
        try {
            $objectCacheService = $this->container->get(ObjectCacheService::class);
            $beforeStats = $objectCacheService->getStats();
            $objectCacheService->clearCache();
            $afterStats = $objectCacheService->getStats();

            return [
                'service' => 'object',
                'cleared' => $beforeStats['entries'] - $afterStats['entries'],
                'before' => $beforeStats,
                'after' => $afterStats,
                'success' => true,
            ];
        } catch (\Exception $e) {
            return [
                'service' => 'object',
                'cleared' => 0,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clear object names cache specifically
     *
     * @return array Clear operation results
     */
    private function clearNamesCache(): array
    {
        try {
            $objectCacheService = $this->container->get(ObjectCacheService::class);
            $beforeStats = $objectCacheService->getStats();
            $beforeNameCacheSize = $beforeStats['name_cache_size'] ?? 0;
            
            $objectCacheService->clearNameCache();
            
            $afterStats = $objectCacheService->getStats();
            $afterNameCacheSize = $afterStats['name_cache_size'] ?? 0;

            return [
                'service' => 'names',
                'cleared' => $beforeNameCacheSize - $afterNameCacheSize,
                'before' => [
                    'name_cache_size' => $beforeNameCacheSize,
                    'name_hits' => $beforeStats['name_hits'] ?? 0,
                    'name_misses' => $beforeStats['name_misses'] ?? 0,
                ],
                'after' => [
                    'name_cache_size' => $afterNameCacheSize,
                    'name_hits' => $afterStats['name_hits'] ?? 0,
                    'name_misses' => $afterStats['name_misses'] ?? 0,
                ],
                'success' => true,
            ];
        } catch (\Exception $e) {
            return [
                'service' => 'names',
                'cleared' => 0,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Warmup object names cache manually
     *
     * @return array Warmup operation results
     */
    public function warmupNamesCache(): array
    {
        try {
            $startTime = microtime(true);
            $objectCacheService = $this->container->get(ObjectCacheService::class);
            $beforeStats = $objectCacheService->getStats();
            
            $loadedCount = $objectCacheService->warmupNameCache();
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $afterStats = $objectCacheService->getStats();

            return [
                'success' => true,
                'loaded_names' => $loadedCount,
                'execution_time' => $executionTime . 'ms',
                'before' => [
                    'name_cache_size' => $beforeStats['name_cache_size'] ?? 0,
                    'name_warmups' => $beforeStats['name_warmups'] ?? 0,
                ],
                'after' => [
                    'name_cache_size' => $afterStats['name_cache_size'] ?? 0,
                    'name_warmups' => $afterStats['name_warmups'] ?? 0,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Cache warmup failed: ' . $e->getMessage(),
                'loaded_names' => 0,
            ];
        }
    }

    /**
     * Clear schema cache service
     *
     * @param string|null $userId Specific user ID
     *
     * @return array Clear operation results
     */
    private function clearSchemaCache(?string $userId = null): array
    {
        try {
            $beforeStats = $this->schemaCacheService->getCacheStatistics();
            $this->schemaCacheService->clearAllCaches();
            $afterStats = $this->schemaCacheService->getCacheStatistics();

            return [
                'service' => 'schema',
                'cleared' => $beforeStats['entries'] - $afterStats['entries'],
                'before' => $beforeStats,
                'after' => $afterStats,
                'success' => true,
            ];
        } catch (\Exception $e) {
            return [
                'service' => 'schema',
                'cleared' => 0,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clear facet cache service
     *
     * @param string|null $userId Specific user ID
     *
     * @return array Clear operation results
     */
    private function clearFacetCache(?string $userId = null): array
    {
        try {
            $beforeStats = $this->schemaFacetCacheService->getCacheStatistics();
            $this->schemaFacetCacheService->clearAllCaches();
            $afterStats = $this->schemaFacetCacheService->getCacheStatistics();

            return [
                'service' => 'facet',
                'cleared' => $beforeStats['entries'] - $afterStats['entries'],
                'before' => $beforeStats,
                'after' => $afterStats,
                'success' => true,
            ];
        } catch (\Exception $e) {
            return [
                'service' => 'facet',
                'cleared' => 0,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clear distributed cache
     *
     * @param string|null $userId Specific user ID
     *
     * @return array Clear operation results
     */
    private function clearDistributedCache(?string $userId = null): array
    {
        try {
            $distributedCache = $this->cacheFactory->createDistributed('openregister');
            $distributedCache->clear();

            return [
                'service' => 'distributed',
                'cleared' => 'all', // Can't count distributed cache entries
                'success' => true,
            ];
        } catch (\Exception $e) {
            return [
                'service' => 'distributed',
                'cleared' => 0,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }


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
                    'scheme'         => 'http',
                    'username'       => '',
                    'password'       => '',
                    'timeout'        => 30,
                    'autoCommit'     => true,
                    'commitWithin'   => 1000,
                    'enableLogging'  => true,
                ];
            }

            return json_decode($solrConfig, true);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve SOLR settings: '.$e->getMessage());
        }

    }//end getSolrSettings()


    /**
     * Test SOLR connection with current settings
     *
     * @return array Connection test results with status and details
     */
    public function testSolrConnection(): array
    {
        try {
            $solrSettings = $this->getSolrSettings();
            
            if (!$solrSettings['enabled']) {
                return [
                    'success' => false,
                    'message' => 'SOLR is disabled in settings',
                    'details' => []
                ];
            }

            // Build SOLR URL
            $baseUrl = sprintf(
                '%s://%s:%d%s/%s',
                $solrSettings['scheme'],
                $solrSettings['host'],
                $solrSettings['port'],
                $solrSettings['path'],
                $solrSettings['core']
            );

            // Test ping endpoint
            $pingUrl = $baseUrl . '/admin/ping';
            
            // Create HTTP context with timeout
            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => $solrSettings['timeout'],
                    'header'  => [
                        'Accept: application/json',
                        'Content-Type: application/json'
                    ]
                ]
            ]);

            // Add authentication if configured
            if (!empty($solrSettings['username']) && !empty($solrSettings['password'])) {
                $auth = base64_encode($solrSettings['username'] . ':' . $solrSettings['password']);
                $context['http']['header'][] = 'Authorization: Basic ' . $auth;
            }

            // Attempt connection
            $startTime = microtime(true);
            $response = @file_get_contents($pingUrl, false, $context);
            $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            
            if ($response === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to SOLR server',
                    'details' => [
                        'url' => $pingUrl,
                        'timeout' => $solrSettings['timeout'],
                        'error' => error_get_last()['message'] ?? 'Unknown connection error'
                    ]
                ];
            }

            // Parse response
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from SOLR',
                    'details' => [
                        'response' => substr($response, 0, 500),
                        'json_error' => json_last_error_msg()
                    ]
                ];
            }

            // Check if ping was successful
            $isHealthy = isset($data['status']) && $data['status'] === 'OK';
            
            return [
                'success' => $isHealthy,
                'message' => $isHealthy ? 'SOLR connection successful' : 'SOLR ping failed',
                'details' => [
                    'url' => $pingUrl,
                    'response_time_ms' => round($responseTime, 2),
                    'status' => $data['status'] ?? 'unknown',
                    'core_status' => $data['core'] ?? null,
                    'solr_version' => $data['responseHeader']['zkConnected'] ?? null
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'SOLR connection test failed with exception',
                'details' => [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }

    }//end testSolrConnection()


    /**
     * Warmup SOLR index with current object data using bulk indexing
     *
     * This method triggers a comprehensive warmup of the SOLR search index using
     * bulk indexing for better performance with large datasets.
     *
     * @param int $batchSize Number of objects to process per batch (default 1000)
     * @param int $maxObjects Maximum number of objects to index (0 = all)
     * @return array Warmup operation results with statistics and status
     * @throws \RuntimeException If SOLR warmup fails
     */
    public function warmupSolrIndex(int $batchSize = 1000, int $maxObjects = 0): array
    {
        try {
            $solrSettings = $this->getSolrSettings();
            
            if (!$solrSettings['enabled']) {
                return [
                    'success' => false,
                    'message' => 'SOLR is disabled in settings',
                    'stats' => [
                        'totalProcessed' => 0,
                        'totalIndexed' => 0,
                        'totalErrors' => 0,
                        'duration' => 0
                    ]
                ];
            }

            // Get SolrService for bulk indexing
            $solrService = SolrServiceFactory::createSolrService($this->container);
            
            if ($solrService === null) {
                return [
                    'success' => false,
                    'message' => 'SOLR service not available',
                    'stats' => [
                        'totalProcessed' => 0,
                        'totalIndexed' => 0,
                        'totalErrors' => 0,
                        'duration' => 0
                    ]
                ];
            }
            
            $startTime = microtime(true);
            
            // Starting SOLR bulk index warmup
            
            // Perform SOLR bulk index warmup
            $warmupResult = $solrService->bulkIndexFromDatabase($batchSize, $maxObjects);
            
            $totalDuration = microtime(true) - $startTime;
            
            if ($warmupResult['success']) {
                $statistics = $warmupResult['statistics'] ?? [];
                return [
                    'success' => true,
                    'message' => 'SOLR index warmup completed successfully',
                    'stats' => [
                        'totalProcessed' => $statistics['total_processed'] ?? 0,
                        'totalIndexed' => ($statistics['total_processed'] ?? 0) - ($statistics['total_errors'] ?? 0),
                        'totalErrors' => $statistics['total_errors'] ?? 0,
                        'duration' => round($totalDuration, 2),
                        'objectsPerSecond' => $statistics['objects_per_second'] ?? 0,
                        'successRate' => $statistics['success_rate'] ?? 0,
                        'batchSize' => $statistics['batch_size'] ?? $batchSize
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $warmupResult['message'] ?? 'SOLR warmup failed',
                    'stats' => [
                        'totalProcessed' => 0,
                        'totalIndexed' => 0,
                        'totalErrors' => 0,
                        'duration' => round($totalDuration, 2)
                    ]
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'SOLR warmup failed with exception: ' . $e->getMessage(),
                'stats' => [
                    'totalProcessed' => 0,
                    'totalIndexed' => 0,
                    'totalErrors' => 0,
                    'duration' => 0,
                    'error' => $e->getMessage()
                ]
            ];
        }

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
            return $objectCacheService->getSolrDashboardStats();
        } catch (\Exception $e) {
            // Return default dashboard structure if SOLR is not available
            return [
                'overview' => [
                    'available' => false,
                    'connection_status' => 'unavailable',
                    'response_time_ms' => 0,
                    'total_documents' => 0,
                    'index_size' => '0 B',
                    'last_commit' => null,
                ],
                'cores' => [
                    'active_core' => 'unknown',
                    'core_status' => 'inactive',
                    'tenant_id' => 'unknown',
                    'endpoint_url' => 'N/A',
                ],
                'performance' => [
                    'total_searches' => 0,
                    'total_indexes' => 0,
                    'total_deletes' => 0,
                    'avg_search_time_ms' => 0,
                    'avg_index_time_ms' => 0,
                    'total_search_time' => 0,
                    'total_index_time' => 0,
                    'operations_per_sec' => 0,
                    'error_rate' => 0,
                ],
                'health' => [
                    'status' => 'unavailable',
                    'uptime' => 'N/A',
                    'memory_usage' => ['used' => 'N/A', 'max' => 'N/A', 'percentage' => 0],
                    'disk_usage' => ['used' => 'N/A', 'available' => 'N/A', 'percentage' => 0],
                    'warnings' => ['SOLR service is not available or not configured'],
                    'last_optimization' => null,
                ],
                'operations' => [
                    'recent_activity' => [],
                    'queue_status' => ['pending_operations' => 0, 'processing' => false, 'last_processed' => null],
                    'commit_frequency' => ['auto_commit' => false, 'commit_within' => 0, 'last_commit' => null],
                    'optimization_needed' => false,
                ],
                'generated_at' => date('c'),
                'error' => $e->getMessage()
            ];
        }
    }//end getSolrDashboardStats()

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
                        'success' => $result['success'] ?? false,
                        'operation' => 'commit',
                        'message' => $result['success'] ? 'Index committed successfully' : 'Commit failed',
                        'details' => $result,
                        'timestamp' => date('c')
                    ];
                    
                case 'optimize':
                    $result = $objectCacheService->optimizeSolr();
                    return [
                        'success' => $result['success'] ?? false,
                        'operation' => 'optimize',
                        'message' => $result['success'] ? 'Index optimized successfully' : 'Optimization failed',
                        'details' => $result,
                        'timestamp' => date('c')
                    ];
                    
                case 'clear':
                    $result = $objectCacheService->clearSolrIndexForDashboard();
                    return [
                        'success' => $result['success'] ?? false,
                        'operation' => 'clear',
                        'message' => $result['success'] ? 'Index cleared successfully' : 'Clear operation failed',
                        'details' => $result,
                        'timestamp' => date('c')
                    ];
                    
                case 'warmup':
                    return $this->warmupSolrIndex();
                    
                default:
                    return [
                        'success' => false,
                        'operation' => $operation,
                        'message' => 'Unknown operation: ' . $operation,
                        'timestamp' => date('c')
                    ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'operation' => $operation,
                'message' => 'Operation failed: ' . $e->getMessage(),
                'timestamp' => date('c'),
                'error' => $e->getMessage()
            ];
        }
    }//end manageSolr()

    /**
     * Test SOLR connection and get comprehensive status information
     *
     * Performs connection tests and retrieves detailed SOLR status information
     * including connectivity, availability, and basic performance statistics.
     *
     * @return array Connection test results with detailed status information
     */
    public function testSolrConnectionForDashboard(): array
    {
        try {
            $objectCacheService = $this->container->get(ObjectCacheService::class);
            
            $connectionTest = $objectCacheService->testSolrConnection();
            $stats = $objectCacheService->getSolrStats();
            
            return [
                'connection' => $connectionTest,
                'availability' => $stats['available'] ?? false,
                'stats' => $stats,
                'timestamp' => date('c')
            ];
            
        } catch (\Exception $e) {
            return [
                'connection' => [
                    'success' => false,
                    'message' => 'SOLR service unavailable: ' . $e->getMessage(),
                    'details' => []
                ],
                'availability' => false,
                'stats' => [],
                'timestamp' => date('c'),
                'error' => $e->getMessage()
            ];
        }
    }//end testSolrConnection()


    /**
     * Generate unique tenant ID for this Nextcloud instance
     *
     * Creates a consistent identifier based on instance configuration
     * to ensure proper multi-tenancy in shared SOLR environments.
     *
     * @return string Tenant identifier (format: nc_12345678)
     */
    private function generateTenantId(): string
    {
        // Get the system configuration for instance identification
        $instanceId = $this->systemConfig->getSystemValue('instanceid', 'default');
        $overwriteHost = $this->systemConfig->getSystemValue('overwrite.cli.url', '');
        
        // Prefer using the configured host URL for tenant identification
        if (!empty($overwriteHost)) {
            return 'nc_' . hash('crc32', $overwriteHost);
        }
        
        // Fallback to instance ID (first 8 characters for readability)
        return 'nc_' . substr($instanceId, 0, 8);

    }//end generateTenantId()


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
            $tenantId = $this->generateTenantId();
            
            if (empty($solrConfig)) {
                return [
                    'enabled'        => false,
                    'host'           => 'solr',
                    'port'           => 8983,
                    'path'           => '/solr',
                    'core'           => 'openregister',
                    'scheme'         => 'http',
                    'username'       => '',
                    'password'       => '',
                    'timeout'        => 30,
                    'autoCommit'     => true,
                    'commitWithin'   => 1000,
                    'enableLogging'  => true,
                    'tenantId'       => $tenantId,
                ];
            }

            $solrData = json_decode($solrConfig, true);
            return [
                'enabled'        => $solrData['enabled'] ?? false,
                'host'           => $solrData['host'] ?? 'solr',
                'port'           => $solrData['port'] ?? 8983,
                'path'           => $solrData['path'] ?? '/solr',
                'core'           => $solrData['core'] ?? 'openregister',
                'scheme'         => $solrData['scheme'] ?? 'http',
                'username'       => $solrData['username'] ?? '',
                'password'       => $solrData['password'] ?? '',
                'timeout'        => $solrData['timeout'] ?? 30,
                'autoCommit'     => $solrData['autoCommit'] ?? true,
                'commitWithin'   => $solrData['commitWithin'] ?? 1000,
                'enableLogging'  => $solrData['enableLogging'] ?? true,
                'tenantId'       => $solrData['tenantId'] ?? $tenantId,
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve SOLR settings: '.$e->getMessage());
        }
    }

    /**
     * Update SOLR settings only
     *
     * @param array $solrData SOLR configuration data
     * @return array Updated SOLR configuration
     * @throws \RuntimeException If SOLR settings update fails
     */
    public function updateSolrSettingsOnly(array $solrData): array
    {
        try {
            $tenantId = $this->generateTenantId();
            $solrConfig = [
                'enabled'        => $solrData['enabled'] ?? false,
                'host'           => $solrData['host'] ?? 'solr',
                'port'           => (int) ($solrData['port'] ?? 8983),
                'path'           => $solrData['path'] ?? '/solr',
                'core'           => $solrData['core'] ?? 'openregister',
                'scheme'         => $solrData['scheme'] ?? 'http',
                'username'       => $solrData['username'] ?? '',
                'password'       => $solrData['password'] ?? '',
                'timeout'        => (int) ($solrData['timeout'] ?? 30),
                'autoCommit'     => $solrData['autoCommit'] ?? true,
                'commitWithin'   => (int) ($solrData['commitWithin'] ?? 1000),
                'enableLogging'  => $solrData['enableLogging'] ?? true,
                'tenantId'       => $solrData['tenantId'] ?? $tenantId,
            ];
            
            $this->config->setValueString($this->appName, 'solr', json_encode($solrConfig));
            return $solrConfig;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update SOLR settings: '.$e->getMessage());
        }
    }

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
                $rbacData = [
                    'enabled'             => $storedData['enabled'] ?? false,
                    'anonymousGroup'      => $storedData['anonymousGroup'] ?? 'public',
                    'defaultNewUserGroup' => $storedData['defaultNewUserGroup'] ?? 'viewer',
                    'defaultObjectOwner'  => $storedData['defaultObjectOwner'] ?? '',
                    'adminOverride'       => $storedData['adminOverride'] ?? true,
                ];
            }
            
            return [
                'rbac' => $rbacData,
                'availableGroups' => $this->getAvailableGroups(),
                'availableUsers' => $this->getAvailableUsers(),
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve RBAC settings: '.$e->getMessage());
        }
    }

    /**
     * Update RBAC settings only
     *
     * @param array $rbacData RBAC configuration data
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
                'rbac' => $rbacConfig,
                'availableGroups' => $this->getAvailableGroups(),
                'availableUsers' => $this->getAvailableUsers(),
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update RBAC settings: '.$e->getMessage());
        }
    }

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
    }

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
                    'enabled'             => false,
                    'defaultUserTenant'   => '',
                    'defaultObjectTenant' => '',
                ];
            } else {
                $storedData = json_decode($multitenancyConfig, true);
                $multitenancyData = [
                    'enabled'             => $storedData['enabled'] ?? false,
                    'defaultUserTenant'   => $storedData['defaultUserTenant'] ?? '',
                    'defaultObjectTenant' => $storedData['defaultObjectTenant'] ?? '',
                ];
            }
            
            return [
                'multitenancy' => $multitenancyData,
                'availableTenants' => $this->getAvailableOrganisations(),
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve Multitenancy settings: '.$e->getMessage());
        }
    }

    /**
     * Update Multitenancy settings only
     *
     * @param array $multitenancyData Multitenancy configuration data
     * @return array Updated Multitenancy configuration
     * @throws \RuntimeException If Multitenancy settings update fails
     */
    public function updateMultitenancySettingsOnly(array $multitenancyData): array
    {
        try {
            $multitenancyConfig = [
                'enabled'             => $multitenancyData['enabled'] ?? false,
                'defaultUserTenant'   => $multitenancyData['defaultUserTenant'] ?? '',
                'defaultObjectTenant' => $multitenancyData['defaultObjectTenant'] ?? '',
            ];
            
            $this->config->setValueString($this->appName, 'multitenancy', json_encode($multitenancyConfig));
            
            return [
                'multitenancy' => $multitenancyConfig,
                'availableTenants' => $this->getAvailableOrganisations(),
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update Multitenancy settings: '.$e->getMessage());
        }
    }

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
                    'objectArchiveRetention' => 31536000000, // 1 year default
                    'objectDeleteRetention'  => 63072000000, // 2 years default
                    'searchTrailRetention'   => 2592000000,  // 1 month default
                    'createLogRetention'     => 2592000000,  // 1 month default
                    'readLogRetention'       => 86400000,    // 24 hours default
                    'updateLogRetention'     => 604800000,   // 1 week default
                    'deleteLogRetention'     => 2592000000,  // 1 month default
                ];
            }

            $retentionData = json_decode($retentionConfig, true);
            return [
                'objectArchiveRetention' => $retentionData['objectArchiveRetention'] ?? 31536000000,
                'objectDeleteRetention'  => $retentionData['objectDeleteRetention'] ?? 63072000000,
                'searchTrailRetention'   => $retentionData['searchTrailRetention'] ?? 2592000000,
                'createLogRetention'     => $retentionData['createLogRetention'] ?? 2592000000,
                'readLogRetention'       => $retentionData['readLogRetention'] ?? 86400000,
                'updateLogRetention'     => $retentionData['updateLogRetention'] ?? 604800000,
                'deleteLogRetention'     => $retentionData['deleteLogRetention'] ?? 2592000000,
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve Retention settings: '.$e->getMessage());
        }
    }

    /**
     * Update Retention settings only
     *
     * @param array $retentionData Retention configuration data
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
            ];
            
            $this->config->setValueString($this->appName, 'retention', json_encode($retentionConfig));
            return $retentionConfig;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update Retention settings: '.$e->getMessage());
        }
    }

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
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve version information: '.$e->getMessage());
        }
    }


}//end class
