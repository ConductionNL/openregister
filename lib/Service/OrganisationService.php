<?php
/**
 * OpenRegister Organisation Service
 *
 * This file contains the service class for managing organisations and multi-tenancy.
 * Handles user-organisation relationships, session management, and organisational context.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\ISession;
use OCP\IGroupManager;
use OCP\IConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Exception;
use Symfony\Component\Uid\Uuid;

/**
 * OrganisationService
 *
 * Manages multi-tenancy through organisations, handling user-organisation relationships,
 * session management for active organisation, and ensuring proper organisational context.
 *
 * @package OCA\OpenRegister\Service
 */
class OrganisationService
{
    /**
     * App name for user configuration storage
     */
    private const APP_NAME = 'openregister';

    /**
     * Configuration key for active organisation UUID
     */
    private const CONFIG_ACTIVE_ORGANISATION = 'active_organisation';

    /**
     * Session key for storing user's organisations array (cache only)
     */
    private const SESSION_USER_ORGANISATIONS = 'openregister_user_organisations';

    /**
     * Session key for storing active organisation (cache only)
     */
    private const SESSION_ACTIVE_ORGANISATION = 'openregister_active_organisation';

    /**
     * Session key for storing active organisation cache timestamp
     */
    private const SESSION_ACTIVE_ORGANISATION_TIMESTAMP = 'openregister_active_organisation_timestamp';

    /**
     * Cache timeout for organisations in seconds (15 minutes)
     */
    private const CACHE_TIMEOUT = 900;

    /**
     * Static cache for default organisation (shared across all instances)
     *
     * @var Organisation|null
     */
    private static ?Organisation $defaultOrganisationCache = null;

    /**
     * Timestamp when default organisation was cached
     *
     * @var integer|null
     */
    private static ?int $defaultOrganisationCacheTimestamp = null;

    /**
     * Organisation mapper for database operations
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $organisationMapper;

    /**
     * App config for storing user preferences
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * User session for getting current user
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Session interface for storing organisation data
     *
     * @var ISession
     */
    private ISession $session;

    /**
     * Configuration interface for persistent user settings
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Group manager for accessing Nextcloud groups
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * User manager for accessing Nextcloud users
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * Logger for debugging and error tracking
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Settings service for application configuration
     *
     * @var SettingsService|null
     */
    private ?SettingsService $settingsService = null;


    /**
     * OrganisationService constructor
     *
     * @param OrganisationMapper   $organisationMapper Organisation database mapper
     * @param IUserSession         $userSession        User session service
     * @param ISession             $session            Session storage service for caching
     * @param IConfig              $config             Configuration service for persistent storage
     * @param IGroupManager        $groupManager       Group manager service
     * @param IUserManager         $userManager        User manager service
     * @param LoggerInterface      $logger             Logger service
     * @param SettingsService|null $settingsService    Settings service (optional to avoid circular dependency)
     */
    public function __construct(
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        ISession $session,
        IConfig $config,
        IGroupManager $groupManager,
        IUserManager $userManager,
        LoggerInterface $logger,
        ?SettingsService $settingsService=null
    ) {
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->session            = $session;
        $this->config          = $config;
        $this->groupManager    = $groupManager;
        $this->userManager     = $userManager;
        $this->logger          = $logger;
        $this->settingsService = $settingsService;

    }//end __construct()


    /**
     * Ensure default organisation exists, create if needed
     * Uses static application-level caching for performance optimization
     *
     * @return Organisation The default organisation
     */
    public function ensureDefaultOrganisation(): Organisation
    {
        // Check static cache first (shared across all instances).
        if (self::$defaultOrganisationCache !== null && self::$defaultOrganisationCacheTimestamp !== null) {
            $age = time() - self::$defaultOrganisationCacheTimestamp;
            if ($age < self::CACHE_TIMEOUT) {
                $this->logger->debug(
                        'Retrieved default organisation from static cache',
                        [
                            'cacheAge' => $age,
                        ]
                        );
                return self::$defaultOrganisationCache;
            }
        }

        // Cache miss or expired - fetch from database.
        $defaultOrg = $this->fetchDefaultOrganisationFromDatabase();

        // Cache the result.
        $this->cacheDefaultOrganisation($defaultOrg);

        return $defaultOrg;

    }//end ensureDefaultOrganisation()


    /**
     * Get Organisation settings only
     *
     * @return array Organisation configuration
     * @throws \RuntimeException If Organisation settings retrieval fails
     */
    public function getOrganisationSettingsOnly(): array
    {
        try {
            $organisationConfig = $this->appConfig->getValueString('openregister', 'organisation', '');

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
     * Fetch default organisation from database (cache miss fallback)
     *
     * @return Organisation The default organisation
     */
    private function fetchDefaultOrganisationFromDatabase(): Organisation
    {
        // Try to get default organisation UUID from settings.
        if ($this->settingsService !== null) {
            $defaultOrgUuid = $this->settingsService->getDefaultOrganisationUuid();
        } else {
            $defaultOrgUuid = $this->getDefaultOrganisationUuid();
        }

        try {
            // If we have a UUID in settings, fetch that organisation.
            if ($defaultOrgUuid !== null) {
                try {
                    $defaultOrg = $this->organisationMapper->findByUuid($defaultOrgUuid);
                    $this->logger->info(
                            'Found default organisation from settings',
                            [
                                'uuid' => $defaultOrgUuid,
                                'name' => $defaultOrg->getName(),
                            ]
                            );
                } catch (DoesNotExistException $e) {
                    $this->logger->warning(
                            'Default organisation UUID in settings not found, falling back to creation',
                            [
                                'uuid' => $defaultOrgUuid,
                            ]
                            );
                    // UUID in settings doesn't exist, create new default.
                    $defaultOrg = $this->createOrganisation(name: 'Default Organisation', description: 'Auto-generated default organisation', addCurrentUser: false);

                    // Update settings with new UUID.
                    if ($this->settingsService !== null) {
                        $this->settingsService->setDefaultOrganisationUuid($defaultOrg->getUuid());
                    }

                    $this->setDefaultOrganisationId($defaultOrg->getUuid());
                }//end try
            } else {
                // No UUID in settings, create a new default organisation.
                $this->logger->info(message: 'No default organisation found in settings, creating new one');
                $defaultOrg = $this->createOrganisation(name: 'Default Organisation', description: 'Auto-generated default organisation', addCurrentUser: false);

                // Store in settings.
                if ($this->settingsService !== null) {
                    $this->settingsService->setDefaultOrganisationUuid($defaultOrg->getUuid());
                }

                $this->setDefaultOrganisationId($defaultOrg->getUuid());
            }//end if

            // Ensure admin users are added to existing default organisation.
            $adminUsers = $this->getAdminGroupUsers();
            $updated    = false;

            foreach ($adminUsers as $adminUserId) {
                if ($defaultOrg->hasUser($adminUserId) === false) {
                    $defaultOrg->addUser($adminUserId);
                    $updated = true;
                }
            }

            // Ensure admin group has full RBAC permissions.
            $authorization    = $defaultOrg->getAuthorization();
            $adminGroupInAuth = $this->hasAdminGroupInAuthorization($authorization);
            if ($adminGroupInAuth === false) {
                $defaultOrg = $this->addAdminGroupToAuthorization($defaultOrg);
                $updated    = true;
            }

            if ($updated === true) {
                $defaultOrg = $this->organisationMapper->update($defaultOrg);
                $this->logger->info(
                        'Added admin users and RBAC permissions to existing default organisation',
                        [
                            'adminUsersAdded'  => $adminUsers,
                            'adminGroupInAuth' => $adminGroupInAuth,
                        ]
                        );
                // Clear cache since we updated the organisation.
                $this->clearDefaultOrganisationCache();
            }

            return $defaultOrg;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to fetch or create default organisation',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );
            throw $e;
        }//end try

    }//end fetchDefaultOrganisationFromDatabase()


    /**
     * Cache default organisation in static memory for performance
     *
     * @param Organisation $organisation The default organisation to cache
     *
     * @return void
     */
    private function cacheDefaultOrganisation(Organisation $organisation): void
    {
        self::$defaultOrganisationCache          = $organisation;
        self::$defaultOrganisationCacheTimestamp = time();

        $this->logger->debug(
                'Cached default organisation in static memory',
                [
                    'organisationUuid' => $organisation->getUuid(),
                    'organisationName' => $organisation->getName(),
                ]
                );

    }//end cacheDefaultOrganisation()


    /**
     * Get the current user
     *
     * @return IUser|null The current user or null if not logged in
     */
    private function getCurrentUser(): ?IUser
    {
        return $this->userSession->getUser();

    }//end getCurrentUser()


    /**
     * Get organisations for the current user
     *
     * @param bool $useCache Whether to use session cache (temporarily disabled)
     *
     * @return array Array of Organisation objects
     */
    public function getUserOrganisations(bool $useCache=true): array
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return [];
        }

        $userId = $user->getUID();

        // Temporarily disable caching to avoid serialization issues.
        // TODO: Implement proper object serialization/deserialization later.
        // Get from database.
        $organisations = $this->organisationMapper->findByUserId($userId);

        // If user has no organisations, add them to default.
        if ($organisations === []) {
            $defaultOrg = $this->ensureDefaultOrganisation();
            $defaultOrg->addUser($userId);
            $this->organisationMapper->update($defaultOrg);
            $organisations = [$defaultOrg];
        }

        return $organisations;

    }//end getUserOrganisations()


    /**
     * Get the active organisation for the current user
     * Uses session caching to avoid repeated database calls for RBAC performance
     *
     * @return Organisation|null The active organisation or null if none set
     */
    public function getActiveOrganisation(): ?Organisation
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return null;
        }

        $userId = $user->getUID();

        // Check session cache first for performance.
        $cacheKey     = self::SESSION_ACTIVE_ORGANISATION.'_'.$userId;
        $timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP.'_'.$userId;

        $cachedOrganisation = $this->session->get($cacheKey);
        $cacheTimestamp     = $this->session->get($timestampKey);

        // Return cached organisation if valid and not expired.
        if ($cachedOrganisation !== null && $cacheTimestamp !== null) {
            $age = time() - $cacheTimestamp;
            if ($age < self::CACHE_TIMEOUT) {
                $this->logger->debug(
                        'Retrieved active organisation from session cache',
                        [
                            'userId'           => $userId,
                            'organisationUuid' => $cachedOrganisation['uuid'] ?? 'unknown',
                            'cacheAge'         => $age,
                        ]
                        );

                // Reconstruct organisation from cached data.
                return $this->reconstructOrganisationFromCache($cachedOrganisation);
            }
        }

        // Cache miss or expired - fetch from database.
        $organisation = $this->fetchActiveOrganisationFromDatabase($userId);

        // Cache the result if we have an organisation.
        if ($organisation !== null) {
            $this->cacheActiveOrganisation(organisation: $organisation, userId: $userId);
        }

        return $organisation;

    }//end getActiveOrganisation()


    /**
     * Set the active organisation for the current user
     *
     * @param string $organisationUuid The organisation UUID to set as active
     *
     * @return true True if successfully set, false otherwise
     *
     * @throws Exception If user doesn't belong to the organisation
     */
    public function setActiveOrganisation(string $organisationUuid): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $userId = $user->getUID();

        // Verify user belongs to this organisation.
        try {
            $organisation = $this->organisationMapper->findByUuid($organisationUuid);
        } catch (DoesNotExistException $e) {
            throw new Exception('Organisation not found');
        }

        if ($organisation->hasUser($userId) === false) {
            throw new Exception('User does not belong to this organisation');
        }

        // Set in user configuration (persistent across sessions).
        $this->config->setUserValue(
            userId: $userId,
            appName: self::APP_NAME,
            key: self::CONFIG_ACTIVE_ORGANISATION,
            value: $organisationUuid
        );

        // Clear cached organisations and active organisation to force refresh.
        $orgCacheKey = self::SESSION_USER_ORGANISATIONS.'_'.$userId;
        $this->session->remove($orgCacheKey);
        $this->clearActiveOrganisationCache($userId);

        // Cache the new active organisation immediately.
        $this->cacheActiveOrganisation(organisation: $organisation, userId: $userId);

        $this->logger->info(
                'Set active organisation in user config',
                [
                    'userId'           => $userId,
                    'organisationUuid' => $organisationUuid,
                    'organisationName' => $organisation->getName(),
                ]
                );

        return true;

    }//end setActiveOrganisation()


    /**
     * Add a user to an organisation
     *
     * @param string      $organisationUuid The organisation UUID
     * @param string|null $targetUserId     Optional user ID to add. If null, current user is added.
     *
     * @return true True if successfully added
     *
     * @throws Exception If organisation not found, user not logged in, or target user does not exist
     */
    public function joinOrganisation(string $organisationUuid, ?string $targetUserId=null): bool
    {
        // Get current user (for authentication).
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            throw new Exception('No user logged in');
        }

        // Determine which user to add.
        // If targetUserId is provided, use it; otherwise use current user.
        $userId = $targetUserId ?? $currentUser->getUID();

        try {
            // Validate that target user exists if different from current user.
            if ($targetUserId !== null && $targetUserId !== $currentUser->getUID()) {
                // Check if target user exists.
                $targetUser = $this->userManager->get($targetUserId);
                if ($targetUser === null) {
                    throw new Exception('Target user not found');
                }
            }

            // Add user to organisation.
            $this->organisationMapper->addUserToOrganisation(organisationUuid: $organisationUuid, userId: $userId);

            // Clear cached organisations to force refresh for the affected user.
            $cacheKey = self::SESSION_USER_ORGANISATIONS.'_'.$userId;
            $this->session->remove($cacheKey);

            return true;
        } catch (DoesNotExistException $e) {
            throw new Exception('Organisation not found');
        }//end try

    }//end joinOrganisation()


    /**
     * Remove current user or specified user from an organisation
     *
     * @param string      $organisationUuid The organisation UUID
     * @param string|null $targetUserId     Optional user ID to remove. If null, current user is removed.
     *
     * @return true True if successfully removed
     *
     * @throws Exception If organisation not found, user not logged in, or trying to leave last organisation
     */
    public function leaveOrganisation(string $organisationUuid, ?string $targetUserId=null): bool
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            throw new Exception('No user logged in');
        }

        // Determine which user to remove.
        // If targetUserId is provided, use it; otherwise use current user.
        $userId = $targetUserId ?? $currentUser->getUID();

        // If removing current user, check if it's their last organisation.
        if ($userId === $currentUser->getUID()) {
            $userOrgs = $this->getUserOrganisations(false);
            // Don't use cache.
            // Prevent user from leaving all organisations.
            if (count($userOrgs) <= 1) {
                throw new Exception('Cannot leave last organisation');
            }
        }

        try {
            $this->organisationMapper->removeUserFromOrganisation(organisationUuid: $organisationUuid, userId: $userId);

            // If this was the active organisation, clear cache and reset.
            $activeOrg = $this->getActiveOrganisation();
            if ($activeOrg !== null && $activeOrg->getUuid() === $organisationUuid) {
                // Clear active organisation cache and config.
                $this->clearActiveOrganisationCache($userId);
                $this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION);

                // Set another organisation as active (this will auto-set the oldest remaining org).
                $this->getActiveOrganisation();
            }

            // Clear cached organisations to force refresh.
            $cacheKey = self::SESSION_USER_ORGANISATIONS.'_'.$userId;
            $this->session->remove($cacheKey);

            return true;
        } catch (DoesNotExistException $e) {
            throw new Exception('Organisation not found');
        }//end try

    }//end leaveOrganisation()


    /**
     * Generate a URL-friendly slug from a name
     *
     * @param string $name The name to slugify
     *
     * @return string The generated slug
     */
    private function generateSlug(string $name): string
    {
        // Convert to lowercase.
        $slug = strtolower($name);

        // Replace spaces and special characters with hyphens.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Remove leading/trailing hyphens.
        $slug = trim($slug, '-');

        // Limit length to 100 characters.
        $slug = substr($slug, 0, 100);

        return $slug;

    }//end generateSlug()


    /**
     * Create a new organisation
     *
     * @param string $name           Organisation name
     * @param string $description    Organisation description
     * @param bool   $addCurrentUser Whether to add current user as owner and member
     * @param string $uuid           Optional specific UUID to use
     *
     * @return Organisation The created organisation
     *
     * @throws Exception If user not logged in or organisation creation fails
     */
    public function createOrganisation(string $name, string $description='', bool $addCurrentUser=true, string $uuid=''): Organisation
    {
        $user   = $this->getCurrentUser();
        $userId = null;

        // Validate UUID if provided.
        if ($uuid !== '' && Organisation::isValidUuid($uuid) === false) {
            throw new Exception('Invalid UUID format. UUID must be a 32-character hexadecimal string.');
        }

        $organisation = new Organisation();
        $organisation->setName($name);
        $organisation->setDescription($description);

        // Auto-generate slug from name if not provided.
        $organisation->setSlug($this->generateSlug($name));

        // Set UUID if provided.
        if ($uuid !== '') {
            $organisation->setUuid($uuid);
        }

        if ($user !== null) {
            $userId = $user->getUID();
            if ($addCurrentUser === true) {
                $organisation->setOwner($userId);
                $organisation->setUsers([$userId]);
            }
        }

        // Add all admin group users to the organisation.
        $organisation = $this->addAdminUsersToOrganisation($organisation);

        // Add admin group to RBAC authorization with full permissions.
        $organisation = $this->addAdminGroupToAuthorization($organisation);

        $saved = $this->organisationMapper->save($organisation);

        // If there's no default organisation set, make this one the default.
        $defaultOrgId = $this->config->getAppValue('openregister', 'defaultOrganisation', '');
        if ($defaultOrgId === '') {
            $this->config->setAppValue('openregister', 'defaultOrganisation', $saved->getUuid());
        }

        // Clear cached organisations and active organisation cache to force refresh.
        if ($addCurrentUser === true && $userId !== null) {
            $cacheKey = self::SESSION_USER_ORGANISATIONS.'_'.$userId;
            $this->session->remove($cacheKey);
            $this->clearActiveOrganisationCache($userId);
        }

        $this->logger->info(
                'Created new organisation',
                [
                    'organisationUuid' => $saved->getUuid(),
                    'name'             => $name,
                    'owner'            => $userId,
                    'adminUsersAdded'  => $this->getAdminGroupUsers(),
                    'uuidProvided'     => $uuid !== '',
                ]
                );

        return $saved;

    }//end createOrganisation()



    /**
     * Check if current user has access to an organisation
     *
     * @param string $organisationUuid The organisation UUID to check
     *
     * @return bool True if user has access
     */
    public function hasAccessToOrganisation(string $organisationUuid): bool
    {
        try {
            $organisation = $this->organisationMapper->findByUuid($organisationUuid);
            $user         = $this->getCurrentUser();

            if ($user === null) {
                return false;
            }

            // Admin users have access to all organisations.
            if ($this->groupManager->isAdmin($user->getUID()) === true) {
                return true;
            }

            return $organisation->hasUser($user->getUID());
        } catch (DoesNotExistException $e) {
            return false;
        }

    }//end hasAccessToOrganisation()


    /**
     * Get user organisation statistics
     *
     * @return (array|int|null)[] Statistics about user's organisations
     *
     * @psalm-return array{total: int<0, max>, active: array|null, results: array}
     */
    public function getUserOrganisationStats(): array
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return ['total' => 0, 'active' => null, 'results' => []];
        }

        $organisations = $this->getUserOrganisations();
        $activeOrg     = $this->getActiveOrganisation();

        if ($activeOrg !== null) {
            $activeOrgData = $activeOrg->jsonSerialize();
        } else {
            $activeOrgData = null;
        }

        return [
            'total'   => count($organisations),
            'active'  => $activeOrgData,
            'results' => array_map(
                    function ($org) {
                        return $org->jsonSerialize();
                    },
                    $organisations
                    ),
        ];

    }//end getUserOrganisationStats()


    /**
     * Clear default organisation cache (public method for external use)
     *
     * @return void
     */
    public function clearDefaultOrganisationCache(): void
    {
        self::$defaultOrganisationCache          = null;
        self::$defaultOrganisationCacheTimestamp = null;

        $this->logger->info(message: 'Cleared default organisation static cache');

    }//end clearDefaultOrganisationCache()


    /**
     * Clear all organisation cache for current user
     *
     * @param bool $clearPersistent Whether to also clear persistent active organisation setting
     *
     * @return bool True if cache cleared
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function clearCache(bool $clearPersistent=false): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return false;
        }

        $userId = $user->getUID();

        // Clear session-based cache for organisations and active organisation.
        $this->session->remove(self::SESSION_USER_ORGANISATIONS.'_'.$userId);
        $this->clearActiveOrganisationCache($userId);

        // Clear static default organisation cache as well.
        $this->clearDefaultOrganisationCache();

        // Clear persistent configuration if requested.
        if ($clearPersistent === true) {
            $this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION);
        }

        return true;

    }//end clearCache()


    /**
     * Get all users in the admin group
     *
     * @return string[] Array of user IDs in the admin group
     *
     * @psalm-return array<string>
     */
    private function getAdminGroupUsers(): array
    {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            $this->logger->warning(message: 'Admin group not found');
            return [];
        }

        $adminUsers = $adminGroup->getUsers();
        return array_map(
                function ($user) {
                    return $user->getUID();
                },
                $adminUsers
                );

    }//end getAdminGroupUsers()


    /**
     * Add all admin group users to an organisation
     *
     * @param Organisation $organisation The organisation to add admin users to
     *
     * @return Organisation The updated organisation
     */
    private function addAdminUsersToOrganisation(Organisation $organisation): Organisation
    {
        $adminUsers = $this->getAdminGroupUsers();

        // Check if this is the default organisation.
        $defaultOrgId = $this->config->getAppValue('openregister', 'defaultOrganisation', '');
        $isDefaultOrg = ($organisation->getUuid() === $defaultOrgId);

        foreach ($adminUsers as $adminUserId) {
            $organisation->addUser($adminUserId);
        }

        // Clear default organisation cache if we modified the default organisation.
        if ($isDefaultOrg === true) {
            $this->clearDefaultOrganisationCache();
        }

        $this->logger->info(
                'Added admin users to organisation',
                [
                    'organisationUuid' => $organisation->getUuid(),
                    'organisationName' => $organisation->getName(),
                    'adminUsersAdded'  => $adminUsers,
                    'isDefault'        => $isDefaultOrg,
                    'clearedCache'     => $isDefaultOrg,
                ]
                );

        return $organisation;

    }//end addAdminUsersToOrganisation()


    /**
     * Add admin group to organisation authorization with full permissions
     *
     * @param Organisation $organisation The organisation to add admin group permissions to
     *
     * @return Organisation The updated organisation
     */
    private function addAdminGroupToAuthorization(Organisation $organisation): Organisation
    {
        $authorization = $organisation->getAuthorization();
        $adminGroupId  = 'admin';

        // Add admin group to all CRUD permissions for all entity types.
        $entityTypes = ['register', 'schema', 'object', 'view', 'agent', 'configuration', 'application'];
        foreach ($entityTypes as $entityType) {
            if (($authorization[$entityType] ?? null) !== null && is_array($authorization[$entityType]) === true) {
                foreach (['create', 'read', 'update', 'delete'] as $action) {
                    if (($authorization[$entityType][$action] ?? null) !== null && is_array($authorization[$entityType][$action]) === true) {
                        if (in_array($adminGroupId, $authorization[$entityType][$action], true) === false) {
                            $authorization[$entityType][$action][] = $adminGroupId;
                        }
                    }
                }
            }
        }

        // Add admin group to special permissions.
        $specialPermissions = ['object_publish', 'agent_use', 'dashboard_view', 'llm_use'];
        foreach ($specialPermissions as $permission) {
            if (($authorization[$permission] ?? null) !== null && is_array($authorization[$permission]) === true) {
                if (in_array($adminGroupId, $authorization[$permission], true) === false) {
                    $authorization[$permission][] = $adminGroupId;
                }
            }
        }

        $organisation->setAuthorization($authorization);

        $this->logger->info(
                'Added admin group to organisation RBAC authorization',
                [
                    'organisationUuid' => $organisation->getUuid(),
                    'organisationName' => $organisation->getName(),
                    'adminGroupId'     => $adminGroupId,
                ]
                );

        return $organisation;

    }//end addAdminGroupToAuthorization()


    /**
     * Check if admin group is already in authorization configuration
     *
     * @param array $authorization The authorization configuration to check
     *
     * @return bool True if admin group is found in any permission
     */
    private function hasAdminGroupInAuthorization(array $authorization): bool
    {
        $adminGroupId = 'admin';

        // Check all entity types.
        $entityTypes = ['register', 'schema', 'object', 'view', 'agent', 'configuration', 'application'];
        foreach ($entityTypes as $entityType) {
            if (($authorization[$entityType] ?? null) !== null && is_array($authorization[$entityType]) === true) {
                foreach (['create', 'read', 'update', 'delete'] as $action) {
                    if (($authorization[$entityType][$action] ?? null) !== null && is_array($authorization[$entityType][$action]) === true) {
                        if (in_array($adminGroupId, $authorization[$entityType][$action], true) === true) {
                            return true;
                        }
                    }
                }
            }
        }

        // Check special permissions.
        $specialPermissions = ['object_publish', 'agent_use', 'dashboard_view', 'llm_use'];
        foreach ($specialPermissions as $permission) {
            if (($authorization[$permission] ?? null) !== null && is_array($authorization[$permission]) === true) {
                if (in_array($adminGroupId, $authorization[$permission], true) === true) {
                    return true;
                }
            }
        }

        return false;

    }//end hasAdminGroupInAuthorization()


    /**
     * Fetch active organisation from database (cache miss fallback)
     *
     * @param string $userId The user ID to fetch active organisation for
     *
     * @return Organisation|null The active organisation or null if none set
     */
    private function fetchActiveOrganisationFromDatabase(string $userId): ?Organisation
    {
        // Get active organisation UUID from user configuration (persistent).
        $activeUuid = $this->config->getUserValue(
            $userId,
            self::APP_NAME,
            self::CONFIG_ACTIVE_ORGANISATION,
            ''
        );

        if ($activeUuid !== '') {
            try {
                $organisation = $this->organisationMapper->findByUuid($activeUuid);

                // Verify user still has access to this organisation.
                if ($organisation->hasUser($userId) === true) {
                    return $organisation;
                } else {
                    // User no longer has access, clear the setting and cache.
                    $this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION);
                    $this->clearActiveOrganisationCache($userId);
                    $this->logger->info(
                            'Cleared invalid active organisation',
                            [
                                'userId'           => $userId,
                                'organisationUuid' => $activeUuid,
                            ]
                            );
                }
            } catch (DoesNotExistException $e) {
                // Active organisation no longer exists, clear from config and cache.
                $this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION);
                $this->clearActiveOrganisationCache($userId);
                $this->logger->info(
                        'Cleared non-existent active organisation',
                        [
                            'userId'           => $userId,
                            'organisationUuid' => $activeUuid,
                        ]
                        );
            }//end try
        }//end if

        // No valid active organisation set, try to set the oldest one from user's organisations.
        $organisations = $this->getUserOrganisations();
        if (empty($organisations) === false) {
            // Sort by created date and take the oldest.
            usort(
                    $organisations,
                    function ($a, $b) {
                        return $a->getCreated() <=> $b->getCreated();
                    }
                    );

            $oldestOrg = $organisations[0];

            // Set in user configuration.
            $this->config->setUserValue(
                $userId,
                self::APP_NAME,
                self::CONFIG_ACTIVE_ORGANISATION,
                $oldestOrg->getUuid()
            );

            $this->logger->info(
                    'Auto-set active organisation to oldest',
                    [
                        'userId'           => $userId,
                        'organisationUuid' => $oldestOrg->getUuid(),
                        'organisationName' => $oldestOrg->getName(),
                    ]
                    );

            return $oldestOrg;
        }//end if

        return null;

    }//end fetchActiveOrganisationFromDatabase()


    /**
     * Cache active organisation in session for performance
     *
     * @param Organisation $organisation The organisation to cache
     * @param string       $userId       The user ID to cache for
     *
     * @return void
     */
    private function cacheActiveOrganisation(Organisation $organisation, string $userId): void
    {
        $cacheKey     = self::SESSION_ACTIVE_ORGANISATION.'_'.$userId;
        $timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP.'_'.$userId;

        // Store organisation data as array to avoid serialization issues.
        // Convert DateTime objects to ISO strings for proper caching.
        $orgData = [
            'id'          => $organisation->getId(),
            'uuid'        => $organisation->getUuid(),
            'name'        => $organisation->getName(),
            'description' => $organisation->getDescription(),
            'owner'       => $organisation->getOwner(),
            'users'       => $organisation->getUsers(),
            'created'     => $this->formatCreatedDate($organisation),
            'updated'     => $this->formatUpdatedDate($organisation),
        ];

        $this->session->set($cacheKey, $orgData);
        $this->session->set($timestampKey, time());

        $this->logger->debug(
                'Cached active organisation in session',
                [
                    'userId'           => $userId,
                    'organisationUuid' => $organisation->getUuid(),
                    'organisationName' => $organisation->getName(),
                ]
                );

    }//end cacheActiveOrganisation()


    /**
     * Reconstruct Organisation object from cached data
     *
     * @param array $cachedData The cached organisation data
     *
     * @return Organisation The reconstructed organisation object
     */
    private function reconstructOrganisationFromCache(array $cachedData): Organisation
    {
        $organisation = new Organisation();

        // Set all properties from cached data.
        if (($cachedData['id'] ?? null) !== null) {
            $organisation->setId($cachedData['id']);
        }

        if (($cachedData['uuid'] ?? null) !== null) {
            $organisation->setUuid($cachedData['uuid']);
        }

        if (($cachedData['name'] ?? null) !== null) {
            $organisation->setName($cachedData['name']);
        }

        if (($cachedData['description'] ?? null) !== null) {
            $organisation->setDescription($cachedData['description']);
        }

        if (($cachedData['owner'] ?? null) !== null) {
            $organisation->setOwner($cachedData['owner']);
        }

        if (($cachedData['users'] ?? null) !== null) {
            $organisation->setUsers($cachedData['users']);
        }

        if (($cachedData['created'] ?? null) !== null) {
            // Convert string back to DateTime if needed.
            if (is_string($cachedData['created']) === true) {
                $organisation->setCreated(new \DateTime($cachedData['created']));
            } else if ($cachedData['created'] instanceof \DateTime) {
                $organisation->setCreated($cachedData['created']);
            }
        }

        if (($cachedData['updated'] ?? null) !== null) {
            // Convert string back to DateTime if needed.
            if (is_string($cachedData['updated']) === true) {
                $organisation->setUpdated(new \DateTime($cachedData['updated']));
            } else if ($cachedData['updated'] instanceof \DateTime) {
                $organisation->setUpdated($cachedData['updated']);
            }
        }

        return $organisation;

    }//end reconstructOrganisationFromCache()


    /**
     * Clear active organisation cache for a specific user
     *
     * @param string $userId The user ID to clear cache for
     *
     * @return void
     */
    private function clearActiveOrganisationCache(string $userId): void
    {
        $cacheKey     = self::SESSION_ACTIVE_ORGANISATION.'_'.$userId;
        $timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP.'_'.$userId;

        $this->session->remove($cacheKey);
        $this->session->remove($timestampKey);

        $this->logger->debug(
                'Cleared active organisation cache',
                [
                    'userId' => $userId,
                ]
                );

    }//end clearActiveOrganisationCache()


    /**
     * Get the organisation UUID to use for creating new entities
     * Uses the active organisation or falls back to default
     *
     * @return null|string The organisation UUID to use
     */
    public function getOrganisationForNewEntity(): string|null
    {
        $activeOrg = $this->getActiveOrganisation();

        if ($activeOrg !== null) {
            return $activeOrg->getUuid();
        }

        // Fallback to default organisation.
        $defaultOrg = $this->ensureDefaultOrganisation();
        return $defaultOrg->getUuid();

    }//end getOrganisationForNewEntity()


    /**
     * Get the default organisation UUID from config
     *
     * @return null|string The UUID of the default organisation, or null if not set
     */
    public function getDefaultOrganisationId(): string|null
    {
        $defaultOrgId = $this->config->getAppValue('openregister', 'defaultOrganisation', '');
        if ($defaultOrgId !== '') {
            return $defaultOrgId;
        }

        return null;

    }//end getDefaultOrganisationId()


    /**
     * Format created date for JSON serialization
     *
     * @param Organisation $organisation Organisation object
     *
     * @return string|null Formatted date or null
     */
    private function formatCreatedDate(Organisation $organisation): ?string
    {
        $created = $organisation->getCreated();
        if ($created !== null) {
            return $created->format('Y-m-d H:i:s');
        }

        return null;

    }//end formatCreatedDate()


    /**
     * Format updated date for JSON serialization
     *
     * @param Organisation $organisation Organisation object
     *
     * @return string|null Formatted date or null
     */
    private function formatUpdatedDate(Organisation $organisation): ?string
    {
        $updated = $organisation->getUpdated();
        if ($updated !== null) {
            return $updated->format('Y-m-d H:i:s');
        }

        return null;

    }//end formatUpdatedDate()


    /**
     * Set the default organisation UUID in config
     *
     * @param string $uuid The UUID of the organisation to set as default
     *
     * @return void
     */
    public function setDefaultOrganisationId(string $uuid): void
    {
        $this->config->setAppValue('openregister', 'defaultOrganisation', $uuid);
        $this->clearDefaultOrganisationCache();

    }//end setDefaultOrganisationId()



    /**
     * Get UUIDs of active organisation and all its parent organisations
     *
     * This method returns an array of organisation UUIDs that the current user
     * can access based on their active organisation and the parent hierarchy.
     * Children can view resources from their parents, recursively up the hierarchy.
     *
     * Example hierarchy:
     * - VNG (root)
     * - Amsterdam (parent: VNG)
     * - Noord (parent: Amsterdam)
     *
     * When Noord is active, returns: [Noord-UUID, Amsterdam-UUID, VNG-UUID]
     *
     * This is used by MultiTenancyTrait for filtering queries to include parent resources.
     *
     * @return (mixed|null|string)[] Array of organisation UUIDs (active org + all parents)
     *
     * @psalm-return array{0?: mixed|null|string,...}
     */
    public function getUserActiveOrganisations(): array
    {
        $activeOrg = $this->getActiveOrganisation();

        if ($activeOrg === null) {
            $this->logger->debug(message: 'No active organisation found for user');
            return [];
        }

        // Start with the active organisation UUID.
        $orgUuids = [$activeOrg->getUuid()];

        // Get all parent organisations recursively.
        $parents = $this->organisationMapper->findParentChain($activeOrg->getUuid());

        // Merge active UUID with parent UUIDs.
        $orgUuids = array_merge($orgUuids, $parents);

        $this->logger->debug(
            'Retrieved active organisations (including parents)',
            [
                'activeOrg'          => $activeOrg->getUuid(),
                'activeOrgName'      => $activeOrg->getName(),
                'parents'            => $parents,
                'totalOrganisations' => count($orgUuids),
                'allUuids'           => $orgUuids,
            ]
        );

        return $orgUuids;

    }//end getUserActiveOrganisations()


}//end class
