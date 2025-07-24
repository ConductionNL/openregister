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
use OCP\ISession;
use OCP\IGroupManager;
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
     * Session key for storing active organisation UUID
     */
    private const SESSION_ACTIVE_ORGANISATION = 'openregister_active_organisation';

    /**
     * Session key for storing user's organisations array
     */
    private const SESSION_USER_ORGANISATIONS = 'openregister_user_organisations';

    /**
     * Cache timeout for organisations in seconds (15 minutes)
     */
    private const CACHE_TIMEOUT = 900;

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
     * Group manager for accessing Nextcloud groups
     * 
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Logger for debugging and error tracking
     * 
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * OrganisationService constructor
     * 
     * @param OrganisationMapper $organisationMapper Organisation database mapper
     * @param IUserSession $userSession User session service
     * @param ISession $session Session storage service
     * @param IGroupManager $groupManager Group manager service
     * @param LoggerInterface $logger Logger service
     */
    public function __construct(
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        ISession $session,
        IGroupManager $groupManager,
        LoggerInterface $logger
    ) {
        $this->organisationMapper = $organisationMapper;
        $this->userSession = $userSession;
        $this->session = $session;
        $this->groupManager = $groupManager;
        $this->logger = $logger;
    }

    /**
     * Ensure default organisation exists, create if needed
     * 
     * @return Organisation The default organisation
     */
    public function ensureDefaultOrganisation(): Organisation
    {
        try {
            $defaultOrg = $this->organisationMapper->findDefault();
            
            // Ensure admin users are added to existing default organisation
            $adminUsers = $this->getAdminGroupUsers();
            $updated = false;
            
            foreach ($adminUsers as $adminUserId) {
                if (!$defaultOrg->hasUser($adminUserId)) {
                    $defaultOrg->addUser($adminUserId);
                    $updated = true;
                }
            }
            
            if ($updated) {
                $this->organisationMapper->update($defaultOrg);
                $this->logger->info('Added admin users to existing default organisation', [
                    'adminUsersAdded' => $adminUsers
                ]);
            }
            
            return $defaultOrg;
        } catch (DoesNotExistException $e) {
            $this->logger->info('Creating default organisation');
            $defaultOrg = $this->organisationMapper->createDefault();
            
            // Add all admin group users to the new default organisation
            $defaultOrg = $this->addAdminUsersToOrganisation($defaultOrg);
            $this->organisationMapper->update($defaultOrg);
            
            return $defaultOrg;
        }
    }

    /**
     * Get the current user
     * 
     * @return IUser|null The current user or null if not logged in
     */
    private function getCurrentUser(): ?IUser
    {
        return $this->userSession->getUser();
    }

    /**
     * Get organisations for the current user
     * 
     * @param bool $useCache Whether to use session cache (temporarily disabled)
     * @return array Array of Organisation objects
     */
    public function getUserOrganisations(bool $useCache = true): array
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return [];
        }

        $userId = $user->getUID();
        
        // Temporarily disable caching to avoid serialization issues
        // TODO: Implement proper object serialization/deserialization later
        
        // Get from database
        $organisations = $this->organisationMapper->findByUserId($userId);
        
        // If user has no organisations, add them to default
        if (empty($organisations)) {
            $defaultOrg = $this->ensureDefaultOrganisation();
            $defaultOrg->addUser($userId);
            $this->organisationMapper->update($defaultOrg);
            $organisations = [$defaultOrg];
        }

        return $organisations;
    }

    /**
     * Get the active organisation for the current user
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
        $cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
        $activeUuid = $this->session->get($cacheKey);

        if ($activeUuid) {
            try {
                return $this->organisationMapper->findByUuid($activeUuid);
            } catch (DoesNotExistException $e) {
                // Active organisation no longer exists, clear from session
                $this->session->remove($cacheKey);
            }
        }

        // No active organisation set, try to set the oldest one from user's organisations
        $organisations = $this->getUserOrganisations();
        if (!empty($organisations)) {
            // Sort by created date and take the oldest
            usort($organisations, function($a, $b) {
                return $a->getCreated() <=> $b->getCreated();
            });
            
            $oldestOrg = $organisations[0];
            
            // Set in session directly (we know user belongs to this org since getUserOrganisations ensures it)
            $cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
            $this->session->set($cacheKey, $oldestOrg->getUuid());
            
            return $oldestOrg;
        }

        return null;
    }

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

        // Verify user belongs to this organisation
        try {
            $organisation = $this->organisationMapper->findByUuid($organisationUuid);
        } catch (DoesNotExistException $e) {
            throw new Exception('Organisation not found');
        }

        if (!$organisation->hasUser($userId)) {
            throw new Exception('User does not belong to this organisation');
        }

        // Set in session
        $cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
        $this->session->set($cacheKey, $organisationUuid);

        // Clear cached organisations to force refresh
        $orgCacheKey = self::SESSION_USER_ORGANISATIONS . '_' . $userId;
        $this->session->remove($orgCacheKey);

        $this->logger->info('Set active organisation', [
            'userId' => $userId,
            'organisationUuid' => $organisationUuid,
            'organisationName' => $organisation->getName()
        ]);

        return true;
    }

    /**
     * Add current user to an organisation
     * 
     * @param string $organisationUuid The organisation UUID
     * 
     * @return bool True if successfully added
     * 
     * @throws Exception If organisation not found or user not logged in
     */
    public function joinOrganisation(string $organisationUuid): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $userId = $user->getUID();
        
        try {
            $this->organisationMapper->addUserToOrganisation($organisationUuid, $userId);
            
            // Clear cached organisations to force refresh
            $cacheKey = self::SESSION_USER_ORGANISATIONS . '_' . $userId;
            $this->session->remove($cacheKey);
            
            return true;
        } catch (DoesNotExistException $e) {
            throw new Exception('Organisation not found');
        }
    }

    /**
     * Remove current user from an organisation
     * 
     * @param string $organisationUuid The organisation UUID
     * 
     * @return bool True if successfully removed
     * 
     * @throws Exception If organisation not found, user not logged in, or trying to leave last organisation
     */
    public function leaveOrganisation(string $organisationUuid): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $userId = $user->getUID();
        $userOrgs = $this->getUserOrganisations(false); // Don't use cache

        // Prevent user from leaving all organisations
        if (count($userOrgs) <= 1) {
            throw new Exception('Cannot leave last organisation');
        }

        try {
            $organisation = $this->organisationMapper->removeUserFromOrganisation($organisationUuid, $userId);
            
            // If this was the active organisation, set another one as active
            $activeOrg = $this->getActiveOrganisation();
            if ($activeOrg && $activeOrg->getUuid() === $organisationUuid) {
                // Clear active organisation from session
                $activeKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
                $this->session->remove($activeKey);
                
                // Set another organisation as active
                $this->getActiveOrganisation(); // This will auto-set the oldest remaining org
            }
            
            // Clear cached organisations to force refresh
            $cacheKey = self::SESSION_USER_ORGANISATIONS . '_' . $userId;
            $this->session->remove($cacheKey);
            
            return true;
        } catch (DoesNotExistException $e) {
            throw new Exception('Organisation not found');
        }
    }

    /**
     * Create a new organisation
     * 
     * @param string $name Organisation name
     * @param string $description Organisation description
     * @param bool $addCurrentUser Whether to add current user as owner and member
     * @param string $uuid Optional specific UUID to use
     * 
     * @return Organisation The created organisation
     * 
     * @throws Exception If user not logged in or organisation creation fails
     */
    public function createOrganisation(string $name, string $description = '', bool $addCurrentUser = true, string $uuid = ''): Organisation
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $userId = $user->getUID();
        
        // Validate UUID if provided
        if ($uuid !== '' && !Organisation::isValidUuid($uuid)) {
            throw new Exception('Invalid UUID format. UUID must be a 32-character hexadecimal string.');
        }
        
        $organisation = new Organisation();
        $organisation->setName($name);
        $organisation->setDescription($description);
        $organisation->setIsDefault(false);
        
        // Set UUID if provided
        if ($uuid !== '') {
            $organisation->setUuid($uuid);
        }
        
        if ($addCurrentUser) {
            $organisation->setOwner($userId);
            $organisation->setUsers([$userId]);
        }

        // Add all admin group users to the organisation
        $organisation = $this->addAdminUsersToOrganisation($organisation);

        $saved = $this->organisationMapper->save($organisation);

        // Clear cached organisations to force refresh
        if ($addCurrentUser) {
            $cacheKey = self::SESSION_USER_ORGANISATIONS . '_' . $userId;
            $this->session->remove($cacheKey);
        }

        $this->logger->info('Created new organisation', [
            'organisationUuid' => $saved->getUuid(),
            'name' => $name,
            'owner' => $userId,
            'adminUsersAdded' => $this->getAdminGroupUsers(),
            'uuidProvided' => $uuid !== ''
        ]);

        return $saved;
    }

    /**
     * Create a new organisation with a specific UUID
     * 
     * @param string $name Organisation name
     * @param string $description Organisation description
     * @param string $uuid Specific UUID to use
     * @param bool $addCurrentUser Whether to add current user as owner and member
     * 
     * @return Organisation The created organisation
     * 
     * @throws Exception If user not logged in, UUID is invalid, or organisation creation fails
     */
    public function createOrganisationWithUuid(string $name, string $description, string $uuid, bool $addCurrentUser = true): Organisation
    {
        return $this->createOrganisation($name, $description, $addCurrentUser, $uuid);
    }

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
            $user = $this->getCurrentUser();
            
            if ($user === null) {
                return false;
            }

            return $organisation->hasUser($user->getUID());
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

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
        $activeOrg = $this->getActiveOrganisation();

        return [
            'total' => count($organisations),
            'active' => $activeOrg ? $activeOrg->jsonSerialize() : null,
            'results' => array_map(function($org) { return $org->jsonSerialize(); }, $organisations)
        ];
    }

    /**
     * Clear all organisation session cache for current user
     * 
     * @return bool True if cache cleared
     */
    public function clearCache(): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return false;
        }

        $userId = $user->getUID();
        $this->session->remove(self::SESSION_ACTIVE_ORGANISATION . '_' . $userId);
        $this->session->remove(self::SESSION_USER_ORGANISATIONS . '_' . $userId);

        return true;
    }

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
        return array_map(function($user) {
            return $user->getUID();
        }, $adminUsers);
    }

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
        
        foreach ($adminUsers as $adminUserId) {
            $organisation->addUser($adminUserId);
        }

        $this->logger->info('Added admin users to organisation', [
            'organisationUuid' => $organisation->getUuid(),
            'organisationName' => $organisation->getName(),
            'adminUsersAdded' => $adminUsers
        ]);

        return $organisation;
    }

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

        // Fallback to default organisation
        $defaultOrg = $this->ensureDefaultOrganisation();
        return $defaultOrg->getUuid();
    }
} 