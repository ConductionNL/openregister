<?php

/**
 * OpenRegister Configuration Settings Handler
 *
 * This file contains the handler class for managing general configuration settings.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use RuntimeException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Db\OrganisationMapper;
use Psr\Log\LoggerInterface;

/**
 * Handler for configuration settings operations.
 *
 * This handler is responsible for managing RBAC, multitenancy,
 * retention, organisation, and object settings.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
class ConfigurationSettingsHandler
{

    /**
     * Default Fireworks API URL
     *
     * @var string
     */
    private const FIREWORKS_API_URL = 'https://api.fireworks.ai/inference/v1';

    /**
     * Configuration service
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Group manager
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * User manager
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * Organisation mapper
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $organisationMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Application name
     *
     * @var string
     */
    private string $appName;

    /**
     * Constructor for ConfigurationSettingsHandler
     *
     * @param IConfig            $config             Configuration service.
     * @param IGroupManager      $groupManager       Group manager.
     * @param IUserManager       $userManager        User manager.
     * @param OrganisationMapper $organisationMapper Organisation mapper.
     * @param LoggerInterface    $logger             Logger.
     * @param string             $appName            Application name.
     *
     * @return void
     */
    public function __construct(
        IConfig $config,
        IGroupManager $groupManager,
        IUserManager $userManager,
        OrganisationMapper $organisationMapper,
        LoggerInterface $logger,
        string $appName='openregister'
    ) {
        $this->config       = $config;
        $this->groupManager = $groupManager;
        $this->userManager  = $userManager;
        $this->organisationMapper = $organisationMapper;
        $this->logger  = $logger;
        $this->appName = $appName;
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
     * @return (bool|int|mixed|null|string)[][]
     *
     * @throws \RuntimeException If settings retrieval fails.
     *
     * @psalm-return array{
     *     version: array{appName: 'Open Register', appVersion: '0.2.3'},
     *     rbac?: array{
     *         enabled: mixed|true,
     *         anonymousGroup: 'public'|mixed,
     *         defaultNewUserGroup: 'viewer'|mixed,
     *         defaultObjectOwner: ''|mixed,
     *         adminOverride: mixed|true
     *     },
     *     multitenancy?: array{
     *         enabled: mixed|true,
     *         defaultUserTenant: ''|mixed,
     *         defaultObjectTenant: ''|mixed,
     *         publishedObjectsBypassMultiTenancy: false|mixed,
     *         adminOverride: mixed|true
     *     },
     *     availableGroups: array<string, string>,
     *     availableTenants: array<string, null|string>,
     *     availableUsers: array<string, string>,
     *     retention?: array{
     *         objectArchiveRetention: 31536000000|mixed,
     *         objectDeleteRetention: 63072000000|mixed,
     *         searchTrailRetention: 2592000000|mixed,
     *         createLogRetention: 2592000000|mixed,
     *         readLogRetention: 86400000|mixed,
     *         updateLogRetention: 604800000|mixed,
     *         deleteLogRetention: 2592000000|mixed,
     *         auditTrailsEnabled: mixed|true,
     *         searchTrailsEnabled: mixed|true
     *     },
     *     solr?: array{
     *         enabled: false|mixed,
     *         host: 'solr'|mixed,
     *         port: 8983|mixed,
     *         path: '/solr'|mixed,
     *         core: 'openregister'|mixed,
     *         configSet: '_default'|mixed,
     *         scheme: 'http'|mixed,
     *         username: 'solr'|mixed,
     *         password: 'SolrRocks'|mixed,
     *         timeout: 30|mixed,
     *         autoCommit: mixed|true,
     *         commitWithin: 1000|mixed,
     *         enableLogging: mixed|true,
     *         zookeeperHosts: 'zookeeper:2181'|mixed,
     *         zookeeperUsername: ''|mixed,
     *         zookeeperPassword: ''|mixed,
     *         collection: 'openregister'|mixed,
     *         useCloud: mixed|true,
     *         objectCollection: mixed|null,
     *         fileCollection: mixed|null
     *     }
     * }
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
                    'enabled'             => true,
                    'anonymousGroup'      => 'public',
                    'defaultNewUserGroup' => 'viewer',
                    'defaultObjectOwner'  => '',
                    'adminOverride'       => true,
                ];
            }

            if (empty($rbacConfig) === false) {
                $rbacData     = json_decode($rbacConfig, true);
                $data['rbac'] = [
                    'enabled'             => $rbacData['enabled'] ?? true,
                    'anonymousGroup'      => $rbacData['anonymousGroup'] ?? 'public',
                    'defaultNewUserGroup' => $rbacData['defaultNewUserGroup'] ?? 'viewer',
                    'defaultObjectOwner'  => $rbacData['defaultObjectOwner'] ?? '',
                    'adminOverride'       => $rbacData['adminOverride'] ?? true,
                ];
            }

            // Multitenancy Settings - ENABLED BY DEFAULT for proper data isolation.
            $multitenancyConfig = $this->config->getAppValue($this->appName, 'multitenancy', '');
            if (empty($multitenancyConfig) === true) {
                $data['multitenancy'] = [
                    'enabled'                            => true,
                    'defaultUserTenant'                  => '',
                    'defaultObjectTenant'                => '',
                    'publishedObjectsBypassMultiTenancy' => false,
                    'adminOverride'                      => true,
                ];
            }

            if (empty($multitenancyConfig) === false) {
                $multitenancyData     = json_decode($multitenancyConfig, true);
                $data['multitenancy'] = [
                    'enabled'                            => $multitenancyData['enabled'] ?? true,
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
            }//end if

            if (empty($retentionConfig) === false) {
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
            }//end if

            if (empty($solrConfig) === false) {
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
            throw new RuntimeException('Failed to retrieve settings: '.$e->getMessage());
        }//end try
    }//end getSettings()

    /**
     * Get available Nextcloud groups.
     *
     * @return string[]
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
     * @return (null|string)[] Array of organisation_uuid => organisation_name
     *
     * @psalm-return array<string, null|string>
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
            $users[$user->getUID()] = $user->getUID();
            if (($user->getDisplayName() !== null) === true && ($user->getDisplayName() !== '') === true) {
                $users[$user->getUID()] = $user->getDisplayName();
            }
        }

        return $users;
    }//end getAvailableUsers()

    /**
     * Update the settings configuration.
     *
     * @param array $data The settings data to update.
     *
     * @return (bool|int|mixed|null|string)[][]
     *
     * @throws \RuntimeException If settings update fails.
     *
     * @psalm-return array{
     *     version: array{appName: 'Open Register', appVersion: '0.2.3'},
     *     rbac?: array{
     *         enabled: mixed|true,
     *         anonymousGroup: 'public'|mixed,
     *         defaultNewUserGroup: 'viewer'|mixed,
     *         defaultObjectOwner: ''|mixed,
     *         adminOverride: mixed|true
     *     },
     *     multitenancy?: array{
     *         enabled: mixed|true,
     *         defaultUserTenant: ''|mixed,
     *         defaultObjectTenant: ''|mixed,
     *         publishedObjectsBypassMultiTenancy: false|mixed,
     *         adminOverride: mixed|true
     *     },
     *     availableGroups: array<string, string>,
     *     availableTenants: array<string, null|string>,
     *     availableUsers: array<string, string>,
     *     retention?: array{
     *         objectArchiveRetention: 31536000000|mixed,
     *         objectDeleteRetention: 63072000000|mixed,
     *         searchTrailRetention: 2592000000|mixed,
     *         createLogRetention: 2592000000|mixed,
     *         readLogRetention: 86400000|mixed,
     *         updateLogRetention: 604800000|mixed,
     *         deleteLogRetention: 2592000000|mixed,
     *         auditTrailsEnabled: mixed|true,
     *         searchTrailsEnabled: mixed|true
     *     },
     *     solr?: array{
     *         enabled: false|mixed,
     *         host: 'solr'|mixed,
     *         port: 8983|mixed,
     *         path: '/solr'|mixed,
     *         core: 'openregister'|mixed,
     *         configSet: '_default'|mixed,
     *         scheme: 'http'|mixed,
     *         username: 'solr'|mixed,
     *         password: 'SolrRocks'|mixed,
     *         timeout: 30|mixed,
     *         autoCommit: mixed|true,
     *         commitWithin: 1000|mixed,
     *         enableLogging: mixed|true,
     *         zookeeperHosts: 'zookeeper:2181'|mixed,
     *         zookeeperUsername: ''|mixed,
     *         zookeeperPassword: ''|mixed,
     *         collection: 'openregister'|mixed,
     *         useCloud: mixed|true,
     *         objectCollection: mixed|null,
     *         fileCollection: mixed|null
     *     }
     * }
     */
    public function updateSettings(array $data): array
    {
        try {
            // Handle RBAC settings.
            if (($data['rbac'] ?? null) !== null) {
                $rbacData = $data['rbac'];
                // Always store RBAC config with enabled state.
                $rbacConfig = [
                    'enabled'             => $rbacData['enabled'] ?? true,
                    'anonymousGroup'      => $rbacData['anonymousGroup'] ?? 'public',
                    'defaultNewUserGroup' => $rbacData['defaultNewUserGroup'] ?? 'viewer',
                    'defaultObjectOwner'  => $rbacData['defaultObjectOwner'] ?? '',
                    'adminOverride'       => $rbacData['adminOverride'] ?? true,
                ];
                $this->config->setAppValue($this->appName, 'rbac', json_encode($rbacConfig));
            }

            // Handle Multitenancy settings - enabled by default.
            if (($data['multitenancy'] ?? null) !== null) {
                $multitenancyData = $data['multitenancy'];
                // Always store Multitenancy config with enabled state (default: true).
                $multitenancyConfig = [
                    'enabled'                            => $multitenancyData['enabled'] ?? true,
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
            throw new RuntimeException('Failed to update settings: '.$e->getMessage());
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
     * @psalm-return array{
     *     use_old_style_publishing_view?: bool,
     *     auto_publish_objects?: bool,
     *     auto_publish_attachments?: bool
     * }
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
                    $value = 'false';
                    if ($options[$option] === true || $options[$option] === 'true') {
                        $value = 'true';
                    }

                    // Store the value in the configuration.
                    $this->config->setAppValue($this->appName, $option, $value);
                    // Retrieve and convert back to boolean for the response.
                    $updatedOptions[$option] = $this->config->getAppValue($this->appName, $option, '') === 'true';
                }
            }

            return $updatedOptions;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update publishing options: '.$e->getMessage());
        }//end try
    }//end updatePublishingOptions()

    /**
     * Get focused RBAC settings only
     *
     * @return (mixed|string|true)[][]
     *
     * @throws \RuntimeException If RBAC settings retrieval fails
     *
     * @psalm-return array{rbac: array{enabled: mixed|true,
     *     anonymousGroup: 'public'|mixed, defaultNewUserGroup: 'viewer'|mixed,
     *     defaultObjectOwner: ''|mixed, adminOverride: mixed|true},
     *     availableGroups: array<string, string>,
     *     availableUsers: array<string, string>}
     */
    public function getRbacSettingsOnly(): array
    {
        try {
            $rbacConfig = $this->config->getAppValue($this->appName, 'rbac', '');

            $rbacData = [];
            if (empty($rbacConfig) === true) {
                $rbacData = [
                    'enabled'             => true,
                    'anonymousGroup'      => 'public',
                    'defaultNewUserGroup' => 'viewer',
                    'defaultObjectOwner'  => '',
                    'adminOverride'       => true,
                ];
            }

            if (empty($rbacConfig) === false) {
                $storedData = json_decode($rbacConfig, true);
                $rbacData   = [
                    'enabled'             => $storedData['enabled'] ?? true,
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
            throw new RuntimeException('Failed to retrieve RBAC settings: '.$e->getMessage());
        }//end try
    }//end getRbacSettingsOnly()

    /**
     * Update RBAC settings only
     *
     * @param array $rbacData RBAC configuration data
     *
     * @return (mixed|string|true)[][]
     *
     * @throws \RuntimeException If RBAC settings update fails
     *
     * @psalm-return array{rbac: array{enabled: mixed|true,
     *     anonymousGroup: 'public'|mixed, defaultNewUserGroup: 'viewer'|mixed,
     *     defaultObjectOwner: ''|mixed, adminOverride: mixed|true},
     *     availableGroups: array<string, string>,
     *     availableUsers: array<string, string>}
     */
    public function updateRbacSettingsOnly(array $rbacData): array
    {
        try {
            $rbacConfig = [
                'enabled'             => $rbacData['enabled'] ?? true,
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
            throw new RuntimeException('Failed to update RBAC settings: '.$e->getMessage());
        }
    }//end updateRbacSettingsOnly()

    /**
     * Get Organisation settings only
     *
     * @return (mixed|null|true)[][] Organisation configuration
     *
     * @throws \RuntimeException If Organisation settings retrieval fails
     *
     * @psalm-return array{organisation: array{
     *     default_organisation: mixed|null,
     *     auto_create_default_organisation: mixed|true
     * }}
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
            }

            if (empty($organisationConfig) === false) {
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
            throw new RuntimeException('Failed to retrieve Organisation settings: '.$e->getMessage());
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
     * @psalm-return array{organisation: array{
     *     default_organisation: mixed|null,
     *     auto_create_default_organisation: mixed|true
     * }}
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
            throw new RuntimeException('Failed to update Organisation settings: '.$e->getMessage());
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
     * @param string|null $uuid Default organisation UUID
     *
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
     * @psalm-return array{multitenancy: array{enabled: false|mixed,
     *     defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed,
     *     publishedObjectsBypassMultiTenancy: false|mixed,
     *     adminOverride: mixed|true}, availableTenants: array}
     */
    public function getMultitenancySettingsOnly(): array
    {
        try {
            $multitenancyConfig = $this->config->getAppValue($this->appName, 'multitenancy', '');

            $multitenancyData = [];
            if (empty($multitenancyConfig) === true) {
                // Default: multitenancy enabled for proper data isolation.
                $multitenancyData = [
                    'enabled'                            => true,
                    'defaultUserTenant'                  => '',
                    'defaultObjectTenant'                => '',
                    'publishedObjectsBypassMultiTenancy' => false,
                    'adminOverride'                      => true,
                ];
            }

            if (empty($multitenancyConfig) === false) {
                $storedData       = json_decode($multitenancyConfig, true);
                $multitenancyData = [
                    'enabled'                            => $storedData['enabled'] ?? true,
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
            throw new RuntimeException('Failed to retrieve Multitenancy settings: '.$e->getMessage());
        }//end try
    }//end getMultitenancySettingsOnly()

    /**
     * Update Multitenancy settings only
     *
     * @param array $multitenancyData Multitenancy configuration data
     *
     * @throws \RuntimeException If Multitenancy settings update fails
     *
     * @return array Updated multitenancy config with settings and available tenants.
     */
    public function updateMultitenancySettingsOnly(array $multitenancyData): array
    {
        try {
            // Default: enabled=true for proper data isolation.
            $multitenancyConfig = [
                'enabled'                            => $multitenancyData['enabled'] ?? true,
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
            throw new RuntimeException('Failed to update Multitenancy settings: '.$e->getMessage());
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
            throw new RuntimeException('Failed to retrieve LLM settings: '.$e->getMessage());
        }//end try
    }//end getLLMSettingsOnly()

    /**
     * Update LLM settings only
     *
     * @param array $llmData LLM configuration data
     *
     * @throws \RuntimeException If LLM settings update fails
     *
     * @return array Updated LLM config with providers and their configurations.
     */
    public function updateLLMSettingsOnly(array $llmData): array
    {
        try {
            // Get existing config for PATCH support.
            $existingConfig = $this->getLLMSettingsOnly();

            // Merge with existing config (PATCH behavior).
            $n  = $llmData;
            $o  = $existingConfig;
            $nO = $n['openaiConfig'] ?? [];
            $oO = $o['openaiConfig'] ?? [];
            $nL = $n['ollamaConfig'] ?? [];
            $oL = $o['ollamaConfig'] ?? [];
            $nF = $n['fireworksConfig'] ?? [];
            $oF = $o['fireworksConfig'] ?? [];
            $nV = $n['vectorConfig'] ?? [];
            $oV = $o['vectorConfig'] ?? [];

            $llmConfig = [
                'enabled'           => $n['enabled'] ?? $o['enabled'] ?? false,
                'embeddingProvider' => $n['embeddingProvider'] ?? $o['embeddingProvider'] ?? null,
                'chatProvider'      => $n['chatProvider'] ?? $o['chatProvider'] ?? null,
                'openaiConfig'      => [
                    'apiKey'         => $nO['apiKey'] ?? $oO['apiKey'] ?? '',
                    'model'          => $nO['model'] ?? $oO['model'] ?? null,
                    'chatModel'      => $nO['chatModel'] ?? $oO['chatModel'] ?? null,
                    'organizationId' => $nO['organizationId'] ?? $oO['organizationId'] ?? '',
                ],
                'ollamaConfig'      => [
                    'url'       => $nL['url'] ?? $oL['url'] ?? 'http://localhost:11434',
                    'model'     => $nL['model'] ?? $oL['model'] ?? null,
                    'chatModel' => $nL['chatModel'] ?? $oL['chatModel'] ?? null,
                ],
                'fireworksConfig'   => [
                    'apiKey'         => $nF['apiKey'] ?? $oF['apiKey'] ?? '',
                    'embeddingModel' => $nF['embeddingModel'] ?? $oF['embeddingModel'] ?? null,
                    'chatModel'      => $nF['chatModel'] ?? $oF['chatModel'] ?? null,
                    'baseUrl'        => $nF['baseUrl'] ?? $oF['baseUrl'] ?? self::FIREWORKS_API_URL,
                ],
                'vectorConfig'      => [
                    'backend'   => $nV['backend'] ?? $oV['backend'] ?? 'php',
                    'solrField' => $nV['solrField'] ?? $oV['solrField'] ?? '_embedding_',
                ],
            ];

            $this->config->setAppValue($this->appName, 'llm', json_encode($llmConfig));
            return $llmConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update LLM settings: '.$e->getMessage());
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
                    'enabledFileTypes'     => [
                        'txt',
                        'md',
                        'html',
                        'json',
                        'xml',
                        'csv',
                        'pdf',
                        'docx',
                        'doc',
                        'xlsx',
                        'xls',
                    ],
                    'ocrEnabled'           => false,
                    'maxFileSizeMB'        => 100,
                // Text extraction settings (for FileConfiguration component).
                    'extractionScope'      => 'objects',
                // None, all, folders, objects.
                    'textExtractor'        => 'llphant',
                // Llphant, dolphin.
                    'extractionMode'       => 'background',
                // Background, immediate, manual.
                    'maxFileSize'          => 100,
                    'batchSize'            => 10,
                    'dolphinApiEndpoint'   => '',
                    'dolphinApiKey'        => '',
                ];
            }//end if

            return json_decode($fileConfig, true);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve File Management settings: '.$e->getMessage());
        }//end try
    }//end getFileSettingsOnly()

    /**
     * Update File Management settings only
     *
     * @param array $fileData File management configuration data
     *
     * @return (false|int|mixed|null|string[])[] Updated file management configuration
     *
     * @throws \RuntimeException
     *
     * @psalm-return array{vectorizationEnabled: false|mixed, provider: mixed|null,
     *     chunkingStrategy: 'RECURSIVE_CHARACTER'|mixed, chunkSize: 1000|mixed,
     *     chunkOverlap: 200|mixed,
     *     enabledFileTypes: list{'txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf',
     *     'docx', 'doc', 'xlsx', 'xls'}|mixed, ocrEnabled: false|mixed,
     *     maxFileSizeMB: 100|mixed, extractionScope: 'objects'|mixed,
     *     textExtractor: 'llphant'|mixed, extractionMode: 'background'|mixed,
     *     maxFileSize: 100|mixed, batchSize: 10|mixed,
     *     dolphinApiEndpoint: ''|mixed, dolphinApiKey: ''|mixed}
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
                'enabledFileTypes'     => $fileData['enabledFileTypes'] ?? [
                    'txt',
                    'md',
                    'html',
                    'json',
                    'xml',
                    'csv',
                    'pdf',
                    'docx',
                    'doc',
                    'xlsx',
                    'xls',
                ],
                'ocrEnabled'           => $fileData['ocrEnabled'] ?? false,
                'maxFileSizeMB'        => $fileData['maxFileSizeMB'] ?? 100,
            // Text extraction settings (from FileConfiguration component).
                'extractionScope'      => $fileData['extractionScope'] ?? 'objects',
            // None, all, folders, objects.
                'textExtractor'        => $fileData['textExtractor'] ?? 'llphant',
            // Llphant, dolphin.
                'extractionMode'       => $fileData['extractionMode'] ?? 'background',
            // Background, immediate, manual.
                'maxFileSize'          => $fileData['maxFileSize'] ?? 100,
                'batchSize'            => $fileData['batchSize'] ?? 10,
                'dolphinApiEndpoint'   => $fileData['dolphinApiEndpoint'] ?? '',
                'dolphinApiKey'        => $fileData['dolphinApiKey'] ?? '',
            ];

            $this->config->setAppValue($this->appName, 'fileManagement', json_encode($fileConfig));
            return $fileConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update File Management settings: '.$e->getMessage());
        }//end try
    }//end updateFileSettingsOnly()

    /**
     * Get n8n workflow configuration settings only.
     *
     * Retrieves the n8n workflow automation integration settings.
     *
     * @return array n8n configuration.
     *
     * @throws \RuntimeException If n8n settings retrieval fails.
     */
    public function getN8nSettingsOnly(): array
    {
        try {
            $n8nConfig = $this->config->getAppValue($this->appName, 'n8n', '');

            if (empty($n8nConfig) === true) {
                // Return default configuration.
                return [
                    'enabled' => false,
                    'url'     => '',
                    'apiKey'  => '',
                    'project' => 'openregister',
                ];
            }

            return json_decode($n8nConfig, true);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve n8n settings: '.$e->getMessage());
        }
    }//end getN8nSettingsOnly()

    /**
     * Update n8n workflow configuration settings only.
     *
     * Updates the n8n workflow automation integration settings.
     *
     * @param array $n8nData n8n configuration data.
     *
     * @return (false|mixed|string)[] Updated n8n configuration.
     *
     * @throws \RuntimeException If n8n settings update fails.
     *
     * @psalm-return array{enabled: false|mixed, url: ''|mixed, apiKey: ''|mixed, project: 'openregister'|mixed}
     */
    public function updateN8nSettingsOnly(array $n8nData): array
    {
        try {
            $n8nConfig = [
                'enabled' => $n8nData['enabled'] ?? false,
                'url'     => $n8nData['url'] ?? '',
                'apiKey'  => $n8nData['apiKey'] ?? '',
                'project' => $n8nData['project'] ?? 'openregister',
            ];

            $this->config->setAppValue($this->appName, 'n8n', json_encode($n8nConfig));
            return $n8nConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update n8n settings: '.$e->getMessage());
        }
    }//end updateN8nSettingsOnly()

    /**
     * Get only version information.
     *
     * Returns version and build information for the application.
     *
     * @return array Version info with name, version, description, author, licence, timestamp, and date.
     */
    public function getVersionInfoOnly(): array
    {
        try {
            $appInfo = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppInfo($this->appName);

            return [
                'version'     => $appInfo['version'] ?? 'unknown',
                'name'        => $appInfo['name'] ?? 'OpenRegister',
                'description' => $appInfo['description'] ?? '',
                'author'      => $appInfo['author'] ?? 'Conduction',
                'licence'     => $appInfo['licence'] ?? 'AGPL',
                'timestamp'   => time(),
                'date'        => date('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            return [
                'version' => 'unknown',
                'error'   => 'Failed to retrieve version info: '.$e->getMessage(),
            ];
        }
    }//end getVersionInfoOnly()
}//end class
