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
     * @var int|null
     */
    private static ?int $defaultOrganisationCacheTimestamp = null;

    /**
     * Organisation mapper for database operations
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $organisationMapper;

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
     * @param OrganisationMapper $organisationMapper Organisation database mapper
     * @param IUserSession       $userSession        User session service
     * @param ISession           $session            Session storage service for caching
     * @param IConfig            $config             Configuration service for persistent storage
     * @param IGroupManager      $groupManager       Group manager service
     * @param IUserManager       $userManager        User manager service
     * @param LoggerInterface    $logger             Logger service
     * @param SettingsService|null $settingsService  Settings service (optional to avoid circular dependency)
     */
    public function __construct(
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        ISession $session,
        IConfig $config,
        IGroupManager $groupManager,
        IUserManager $userManager,
        LoggerInterface $logger,
        ?SettingsService $settingsService = null
    ) {
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->session            = $session;
        $this->config       = $config;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->logger       = $logger;
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
                $this->logger->debug('Retrieved default organisation from static cache', [
                    'cacheAge' => $age
                ]);
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
     * Fetch default organisation from database (cache miss fallback)
     * 
     * @return Organisation The default organisation
     */
    private function fetchDefaultOrganisationFromDatabase(): Organisation
    {
        // Try to get default organisation UUID from settings.
        $defaultOrgUuid = null;
        if ($this->settingsService !== null) {
            $defaultOrgUuid = $this->settingsService->getDefaultOrganisationUuid();
        }

        try {
            // If we have a UUID in settings, fetch that organisation.
            if ($defaultOrgUuid !== null) {
                try {
                    $defaultOrg = $this->organisationMapper->findByUuid($defaultOrgUuid);
                    $this->logger->info('Found default organisation from settings', [
                        'uuid' => $defaultOrgUuid,
                        'name' => $defaultOrg->getName(),
                    ]);
                } catch (DoesNotExistException $e) {
                    $this->logger->warning('Default organisation UUID in settings not found, falling back to creation', [
                        'uuid' => $defaultOrgUuid,
                    ]);
                    // UUID in settings doesn't exist, create new default.
                    $defaultOrg = $this->createOrganisation('Default Organisation', 'Auto-generated default organisation', false);
                    
                    // Update settings with new UUID.
                    if ($this->settingsService !== null) {
                        $this->settingsService->setDefaultOrganisationUuid($defaultOrg->getUuid());
                    }
                    
                    $this->setDefaultOrganisationId($defaultOrg->getUuid());
                }
            } else {
                // No UUID in settings, create a new default organisation.
                $this->logger->info('No default organisation found in settings, creating new one');
                $defaultOrg = $this->createOrganisation('Default Organisation', 'Auto-generated default organisation', false);
                
                // Store in settings.
                if ($this->settingsService !== null) {
                    $this->settingsService->setDefaultOrganisationUuid($defaultOrg->getUuid());
                }
                
                $this->setDefaultOrganisationId($defaultOrg->getUuid());
            }

            // Ensure admin users are added to existing default organisation.
            $adminUsers = $this->getAdminGroupUsers();
            $updated    = false;

            foreach ($adminUsers as $adminUserId) {
                if (!$defaultOrg->hasUser($adminUserId)) {
                    $defaultOrg->addUser($adminUserId);
                    $updated = true;
                }
            }

            if ($updated === true) {
                $defaultOrg = $this->organisationMapper->update($defaultOrg);
                $this->logger->info(
                        'Added admin users to existing default organisation',
                        [
                            'adminUsersAdded' => $adminUsers,
                        ]
                        );
                // Clear cache since we updated the organisation.
                $this->clearDefaultOrganisationCache();
            }

            return $defaultOrg;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch or create default organisation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
        self::$defaultOrganisationCache = $organisation;
        self::$defaultOrganisationCacheTimestamp = time();
        
        $this->logger->debug('Cached default organisation in static memory', [
            'organisationUuid' => $organisation->getUuid(),
            'organisationName' => $organisation->getName()
        ]);
        
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
     * @param  bool $useCache Whether to use session cache (temporarily disabled)
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
        // TODO: Implement proper object serialization/deserialization later
        // Get from database.
        $organisations = $this->organisationMapper->findByUserId($userId);

        // If user has no organisations, add them to default.
        if (empty($organisations)) {
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
        $cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
        $timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP . '_' . $userId;
        
        $cachedOrganisation = $this->session->get($cacheKey);
        $cacheTimestamp = $this->session->get($timestampKey);
        
        // Return cached organisation if valid and not expired.
        if ($cachedOrganisation !== null && $cacheTimestamp !== null) {
            $age = time() - $cacheTimestamp;
            if ($age < self::CACHE_TIMEOUT) {
                $this->logger->debug('Retrieved active organisation from session cache', [
                    'userId' => $userId,
                    'organisationUuid' => $cachedOrganisation['uuid'] ?? 'unknown',
                    'cacheAge' => $age
                ]);
                
                // Reconstruct organisation from cached data.
                return $this->reconstructOrganisationFromCache($cachedOrganisation);
            }
        }

        // Cache miss or expired - fetch from database.
        $organisation = $this->fetchActiveOrganisationFromDatabase($userId);
        
        // Cache the result if we have an organisation.
        if ($organisation !== null) {
            $this->cacheActiveOrganisation($organisation, $userId);
        }
        
        return $organisation;

    }//end getActiveOrganisation()


    /**
     * Set the active organisation for the current user
     *
     * @param string $organisationUuid The organisation UUID to set as active
     *
     * @return bool True if successfully set, false otherwise
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

        if (!$organisation->hasUser($userId)) {
            throw new Exception('User does not belong to this organisation');
        }

        // Set in user configuration (persistent across sessions).
        $this->config->setUserValue(
            $userId,
            self::APP_NAME,
            self::CONFIG_ACTIVE_ORGANISATION,
            $organisationUuid
        );

        // Clear cached organisations and active organisation to force refresh.
        $orgCacheKey = self::SESSION_USER_ORGANISATIONS.'_'.$userId;
        $this->session->remove($orgCacheKey);
        $this->clearActiveOrganisationCache($userId);
        
        // Cache the new active organisation immediately.
        $this->cacheActiveOrganisation($organisation, $userId);

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
     * @param string $organisationUuid The organisation UUID
     * @param string|null $targetUserId Optional user ID to add. If null, current user is added.
     *
     * @return bool True if successfully added
     *
     * @throws Exception If organisation not found, user not logged in, or target user does not exist
     */
    public function joinOrganisation(string $organisationUuid, ?string $targetUserId = null): bool
    {
        // Get current user (for authentication).
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            throw new Exception('No user logged in');
        }

        // Determine which user to add.
        // If targetUserId is provided, use it; otherwise use current user
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
            $this->organisationMapper->addUserToOrganisation($organisationUuid, $userId);

            // Clear cached organisations to force refresh for the affected user.
            $cacheKey = self::SESSION_USER_ORGANISATIONS.'_'.$userId;
            $this->session->remove($cacheKey);

            return true;
        } catch (DoesNotExistException $e) {
            throw new Exception('Organisation not found');
        }

    }//end joinOrganisation()


    /**
     * Remove current user or specified user from an organisation
     *
     * @param string $organisationUuid The organisation UUID
     * @param string|null $targetUserId Optional user ID to remove. If null, current user is removed.
     *
     * @return bool True if successfully removed
     *
     * @throws Exception If organisation not found, user not logged in, or trying to leave last organisation
     */
    public function leaveOrganisation(string $organisationUuid, ?string $targetUserId = null): bool
    {
        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            throw new Exception('No user logged in');
        }

        // Determine which user to remove.
        // If targetUserId is provided, use it; otherwise use current user
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
            $organisation = $this->organisationMapper->removeUserFromOrganisation($organisationUuid, $userId);

            // If this was the active organisation, clear cache and reset.
            $activeOrg = $this->getActiveOrganisation();
            if ($activeOrg && $activeOrg->getUuid() === $organisationUuid) {
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
        $user = $this->getCurrentUser();
        $userId = null;

        // Validate UUID if provided.
        if ($uuid !== '' && !Organisation::isValidUuid($uuid)) {
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

        $saved = $this->organisationMapper->save($organisation);
        
        // If there's no default organisation set, make this one the default.
        $defaultOrgId = $this->config->getAppValue('openregister', 'defaultOrganisation', '');
        if (empty($defaultOrgId)) {
            $this->config->setAppValue('openregister', 'defaultOrganisation', $saved->getUuid());
        }

        // Clear cached organisations and active organisation cache to force refresh.
        if ($addCurrentUser && $userId !== null) {
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
     * Create a new organisation with a specific UUID
     *
     * @param string $name           Organisation name
     * @param string $description    Organisation description
     * @param string $uuid           Specific UUID to use
     * @param bool   $addCurrentUser Whether to add current user as owner and member
     *
     * @return Organisation The created organisation
     *
     * @throws Exception If user not logged in, UUID is invalid, or organisation creation fails
     */
    public function createOrganisationWithUuid(string $name, string $description, string $uuid, bool $addCurrentUser=true): Organisation
    {
        return $this->createOrganisation($name, $description, $addCurrentUser, $uuid);

    }//end createOrganisationWithUuid()


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
            if ($this->groupManager->isAdmin($user->getUID())) {
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
     * @return array Statistics about user's organisations
     */
    public function getUserOrganisationStats(): array
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return ['total' => 0, 'active' => null, 'results' => []];
        }

        $organisations = $this->getUserOrganisations();
        $activeOrg     = $this->getActiveOrganisation();

        return [
            'total'   => count($organisations),
            'active'  => $activeOrg ? $activeOrg->jsonSerialize() : null,
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
        self::$defaultOrganisationCache = null;
        self::$defaultOrganisationCacheTimestamp = null;
        
        $this->logger->info('Cleared default organisation static cache');
        
    }//end clearDefaultOrganisationCache()


    /**
     * Clear all organisation cache for current user
     *
     * @param  bool $clearPersistent Whether to also clear persistent active organisation setting
     * @return bool True if cache cleared
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
     * @return array Array of user IDs in the admin group
     */
    private function getAdminGroupUsers(): array
    {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            $this->logger->warning('Admin group not found');
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
                if ($organisation->hasUser($userId)) {
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
        if (!empty($organisations)) {
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
        $cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
        $timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP . '_' . $userId;
        
        // Store organisation data as array to avoid serialization issues.
        // Convert DateTime objects to ISO strings for proper caching.
        $orgData = [
            'id' => $organisation->getId(),
            'uuid' => $organisation->getUuid(),
            'name' => $organisation->getName(),
            'description' => $organisation->getDescription(),
            'owner' => $organisation->getOwner(),
            'users' => $organisation->getUsers(),
            'created' => $organisation->getCreated() ? $organisation->getCreated()->format('Y-m-d H:i:s') : null,
            'updated' => $organisation->getUpdated() ? $organisation->getUpdated()->format('Y-m-d H:i:s') : null
        ];
        
        $this->session->set($cacheKey, $orgData);
        $this->session->set($timestampKey, time());
        
        $this->logger->debug('Cached active organisation in session', [
            'userId' => $userId,
            'organisationUuid' => $organisation->getUuid(),
            'organisationName' => $organisation->getName()
        ]);
        
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
        if (isset($cachedData['id'])) {
            $organisation->setId($cachedData['id']);
        }
        if (isset($cachedData['uuid'])) {
            $organisation->setUuid($cachedData['uuid']);
        }
        if (isset($cachedData['name'])) {
            $organisation->setName($cachedData['name']);
        }
        if (isset($cachedData['description'])) {
            $organisation->setDescription($cachedData['description']);
        }
        if (isset($cachedData['owner'])) {
            $organisation->setOwner($cachedData['owner']);
        }
        if (isset($cachedData['users'])) {
            $organisation->setUsers($cachedData['users']);
        }
        if (isset($cachedData['created']) && $cachedData['created'] !== null) {
            // Convert string back to DateTime if needed.
            if (is_string($cachedData['created'])) {
                $organisation->setCreated(new \DateTime($cachedData['created']));
            } elseif ($cachedData['created'] instanceof \DateTime) {
                $organisation->setCreated($cachedData['created']);
            }
        }
        if (isset($cachedData['updated']) && $cachedData['updated'] !== null) {
            // Convert string back to DateTime if needed.
            if (is_string($cachedData['updated'])) {
                $organisation->setUpdated(new \DateTime($cachedData['updated']));
            } elseif ($cachedData['updated'] instanceof \DateTime) {
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
        $cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
        $timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP . '_' . $userId;
        
        $this->session->remove($cacheKey);
        $this->session->remove($timestampKey);
        
        $this->logger->debug('Cleared active organisation cache', [
            'userId' => $userId
        ]);
        
    }//end clearActiveOrganisationCache()


    /**
     * Get the organisation UUID to use for creating new entities
     * Uses the active organisation or falls back to default
     *
     * @return string The organisation UUID to use
     */
    public function getOrganisationForNewEntity(): string
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
     * @return string|null The UUID of the default organisation, or null if not set
     */
    public function getDefaultOrganisationId(): ?string
    {
        $defaultOrgId = $this->config->getAppValue('openregister', 'defaultOrganisation', '');
        return $defaultOrgId !== '' ? $defaultOrgId : null;

    }//end getDefaultOrganisationId()


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
     * Get the default organisation object
     *
     * @return Organisation|null The default organisation, or null if not set
     */
    public function getDefaultOrganisation(): ?Organisation
    {
        $defaultOrgId = $this->getDefaultOrganisationId();
        if ($defaultOrgId === null) {
            return null;
        }

        try {
            return $this->organisationMapper->findByUuid($defaultOrgId);
        } catch (\Exception $e) {
            $this->logger->warning('Default organisation not found', [
                'uuid' => $defaultOrgId,
                'error' => $e->getMessage()
            ]);
            return null;
        }

    }//end getDefaultOrganisation()


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
     * @return array Array of organisation UUIDs (active org + all parents)
     */
    public function getUserActiveOrganisations(): array
    {
        $activeOrg = $this->getActiveOrganisation();
        
        if ($activeOrg === null) {
            $this->logger->debug('No active organisation found for user');
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
                'activeOrg' => $activeOrg->getUuid(),
                'activeOrgName' => $activeOrg->getName(),
                'parents' => $parents,
                'totalOrganisations' => count($orgUuids),
                'allUuids' => $orgUuids,
            ]
        );
        
        return $orgUuids;

    }//end getUserActiveOrganisations()


}//end class
