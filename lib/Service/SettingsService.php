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
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use OCP\AppFramework\Http\JSONResponse;
use OC_App;
use OCA\OpenRegister\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\ObjectService;

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
     * @param IAppConfig         $config             App configuration interface.
     * @param IRequest           $request            Request interface.
     * @param ContainerInterface $container          Container for dependency injection.
     * @param IAppManager        $appManager         App manager interface.
     * @param IGroupManager      $groupManager       Group manager interface.
     * @param IUserManager       $userManager        User manager interface.
     * @param OrganisationMapper $organisationMapper Organisation mapper for database operations.
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for database operations.
     * @param SearchTrailMapper  $searchTrailMapper  Search trail mapper for database operations.
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper for database operations.
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly OrganisationMapper $organisationMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SearchTrailMapper $searchTrailMapper,
        private readonly ObjectEntityMapper $objectEntityMapper
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
     * as well as total counts for all objects, audit trails, and search trails.
     *
     * @return array Array containing warning counts and total counts
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
                ],
                'totals'      => [
                    'totalObjects'      => 0,
                    'totalAuditTrails'  => 0,
                    'totalSearchTrails' => 0,
                ],
                'lastUpdated' => (new \DateTime())->format('c'),
            ];

            // Get ObjectService from container to use countSearchObjects
            $objectService = $this->container->get(ObjectService::class);

            // Count objects without owner (bypass RBAC and multi-tenancy)
            $stats['warnings']['objectsWithoutOwner'] = $objectService->countSearchObjects(
                    [
                        'owner' => ['IS NULL', ''],
                    ],
                    false,
                    false
                    );

            // Count objects without organisation (bypass RBAC and multi-tenancy)
            $stats['warnings']['objectsWithoutOrganisation'] = $objectService->countSearchObjects(
                    [
                        'organisation' => ['IS NULL', ''],
                    ],
                    false,
                    false
                    );

            // Count total objects (bypass RBAC and multi-tenancy)
            $stats['totals']['totalObjects'] = $objectService->countSearchObjects([], false, false);

            // Get total size of all objects (bypass RBAC and multi-tenancy)
            $stats['totals']['totalSize'] = $this->objectEntityMapper->sizeSearchObjects([], null, false, false);

            // Count deleted objects (bypass RBAC and multi-tenancy)
            $stats['totals']['deletedObjects'] = $objectService->countSearchObjects(
                    [
                        '_includeDeleted' => true,
                        'deleted'         => ['IS NOT NULL'],
                    ],
                    false,
                    false
                    );

            // Get size of deleted objects (bypass RBAC and multi-tenancy)
            $stats['totals']['deletedSize'] = $this->objectEntityMapper->sizeSearchObjects(
                    [
                        '_includeDeleted' => true,
                        'deleted'         => ['IS NOT NULL'],
                    ],
                    null,
                    false,
                    false
                    );

            // Count audit trails without expiry date
            $stats['warnings']['auditTrailsWithoutExpiry'] = $this->auditTrailMapper->count(
                    [
                        'expires' => ['IS NULL', ''],
                    ]
                    );

            // Count search trails without expiry date
            $stats['warnings']['searchTrailsWithoutExpiry'] = $this->searchTrailMapper->count(
                    [
                        'expires' => ['IS NULL', ''],
                    ]
                    );

            // Count total audit trails
            $stats['totals']['totalAuditTrails'] = $this->auditTrailMapper->count();

            // Get total size of audit trails
            $stats['totals']['totalAuditTrailsSize'] = $this->auditTrailMapper->sizeAuditTrails([]);

            // Count total search trails
            $stats['totals']['totalSearchTrails'] = $this->searchTrailMapper->count([]);

            // Get estimated total size of search trails
            $stats['totals']['totalSearchTrailsSize'] = $this->searchTrailMapper->sizeSearchTrails([]);

            // Count expired items (items past their expiry date) and get their sizes
            // For expired audit trails, we need a custom query to check if expires < NOW()
            $db = $this->container->get('OCP\IDBConnection');

            $qb = $db->getQueryBuilder();
            $qb->select($qb->func()->count('*'), $qb->createFunction('COALESCE(SUM(size), 0) as total_size'))
                ->from('openregister_audit_trails')
                ->where($qb->expr()->isNotNull('expires'))
                ->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));
            $result    = $qb->executeQuery();
            $auditData = $result->fetch();
            $stats['warnings']['expiredAuditTrails']     = (int) ($auditData['COUNT(*)'] ?? 0);
            $stats['warnings']['expiredAuditTrailsSize'] = (int) ($auditData['total_size'] ?? 0);
            $result->closeCursor();

            // Count expired search trails
            $qb = $db->getQueryBuilder();
            $qb->select($qb->func()->count('*'), $qb->createFunction('COALESCE(SUM(size), 0) as total_size'))
                ->from('openregister_search_trails')
                ->where($qb->expr()->isNotNull('expires'))
                ->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));
            $result     = $qb->executeQuery();
            $searchData = $result->fetch();
            $stats['warnings']['expiredSearchTrails']     = (int) ($searchData['COUNT(*)'] ?? 0);
            $stats['warnings']['expiredSearchTrailsSize'] = (int) ($searchData['total_size'] ?? 0);
            $result->closeCursor();

            // Count expired objects
            $qb = $db->getQueryBuilder();
            $qb->select($qb->func()->count('*'), $qb->createFunction('COALESCE(SUM(CAST(size AS UNSIGNED)), 0) as total_size'))
                ->from('openregister_objects')
                ->where($qb->expr()->isNotNull('expires'))
                ->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));
            $result     = $qb->executeQuery();
            $objectData = $result->fetch();
            $stats['warnings']['expiredObjects']     = (int) ($objectData['COUNT(*)'] ?? 0);
            $stats['warnings']['expiredObjectsSize'] = (int) ($objectData['total_size'] ?? 0);
            $result->closeCursor();

            return $stats;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve statistics: '.$e->getMessage());
        }//end try

    }//end getStats()


}//end class
