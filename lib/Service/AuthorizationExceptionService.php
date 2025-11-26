<?php

/**
 * OpenRegister Authorization Exception Service
 *
 * This service handles the business logic for authorization exceptions
 * in the OpenRegister application.
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
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuthorizationException;
use OCA\OpenRegister\Db\AuthorizationExceptionMapper;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * Service class for managing authorization exceptions
 *
 * This service provides business logic for creating, managing, and evaluating
 * authorization exceptions that override the standard RBAC system.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git_id>
 * @link    https://www.OpenRegister.app
 */
class AuthorizationExceptionService
{

    /**
     * Authorization exception mapper instance
     *
     * @var AuthorizationExceptionMapper
     */
    private AuthorizationExceptionMapper $mapper;

    /**
     * User session instance
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Group manager instance
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Cache factory instance
     *
     * @var ICacheFactory|null
     */
    private ?ICacheFactory $cacheFactory;

    /**
     * Cache instance for storing authorization exceptions
     *
     * @var IMemcache|null
     */
    private ?IMemcache $cache = null;

    /**
     * In-memory cache for user exceptions to avoid repeated database queries
     *
     * @var array<string, array<AuthorizationException>>
     */
    private array $userExceptionCache = [];

    /**
     * In-memory cache for group memberships to avoid repeated group manager calls
     *
     * @var array<string, array<string>>
     */
    private array $groupMembershipCache = [];


    /**
     * Constructor for the AuthorizationExceptionService
     *
     * @param AuthorizationExceptionMapper $mapper       The authorization exception mapper
     * @param IUserSession                 $userSession  The user session
     * @param IGroupManager                $groupManager The group manager
     * @param LoggerInterface              $logger       The logger
     * @param ICacheFactory|null           $cacheFactory Optional cache factory for performance optimization
     */
    public function __construct(
        AuthorizationExceptionMapper $mapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        LoggerInterface $logger,
        ?ICacheFactory $cacheFactory=null
    ) {
        $this->mapper       = $mapper;
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;
        $this->logger       = $logger;
        $this->cacheFactory = $cacheFactory;

        // Initialize cache if available.
        if ($this->cacheFactory !== null) {
            try {
                $this->cache = $this->cacheFactory->createDistributed('openregister_auth_exceptions');
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Failed to initialize authorization exception cache',
                        [
                            'exception' => $e->getMessage(),
                        ]
                        );
                $this->cache = null;
            }
        }

    }//end __construct()


    /**
     * Create a new authorization exception
     *
     * @param string      $type             The exception type (inclusion or exclusion)
     * @param string      $subjectType      The subject type (user or group)
     * @param string      $subjectId        The subject ID
     * @param string      $action           The action (create, read, update, delete)
     * @param string|null $schemaUuid       Optional schema UUID
     * @param string|null $registerUuid     Optional register UUID
     * @param string|null $organizationUuid Optional organization UUID
     * @param int         $priority         Priority for exception resolution
     * @param string|null $description      Optional description
     *
     * @throws InvalidArgumentException If invalid parameters are provided
     *
     * @return AuthorizationException The created authorization exception
     */
    public function createException(
        string $type,
        string $subjectType,
        string $subjectId,
        string $action,
        ?string $schemaUuid=null,
        ?string $registerUuid=null,
        ?string $organizationUuid=null,
        int $priority=0,
        ?string $description=null
    ): AuthorizationException {
        // Get current user.
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new InvalidArgumentException('No authenticated user to create authorization exception');
        }

        // Validate input parameters.
        $this->validateExceptionParameters($type, $subjectType, $subjectId, $action);

        // Create the exception entity.
        $exception = new AuthorizationException();
        $exception->setType($type);
        $exception->setSubjectType($subjectType);
        $exception->setSubjectId($subjectId);
        $exception->setAction($action);
        $exception->setSchemaUuid($schemaUuid);
        $exception->setRegisterUuid($registerUuid);
        $exception->setOrganizationUuid($organizationUuid);
        $exception->setPriority($priority);
        $exception->setDescription($description);

        // Save to database.
        $createdException = $this->mapper->createException($exception, $user->getUID());

        $this->logger->info(
                'Authorization exception created',
                [
                    'uuid'         => $createdException->getUuid(),
                    'type'         => $type,
                    'subject_type' => $subjectType,
                    'subject_id'   => $subjectId,
                    'action'       => $action,
                    'schema_uuid'  => $schemaUuid,
                    'created_by'   => $user->getUID(),
                ]
                );

        return $createdException;

    }//end createException()


    /**
     * Performance-optimized version of evaluateUserPermission with caching
     *
     * This method uses multiple caching layers to improve performance:
     * - Distributed cache for computed results
     * - In-memory cache for user exceptions within request
     * - Cached group memberships
     *
     * @param string      $userId           The user ID to check
     * @param string      $action           The action to check
     * @param string|null $schemaUuid       Optional schema UUID
     * @param string|null $registerUuid     Optional register UUID
     * @param string|null $organizationUuid Optional organization UUID
     *
     * @return bool|null True if allowed, false if denied, null if no applicable exceptions
     */
    public function evaluateUserPermissionOptimized(
        string $userId,
        string $action,
        ?string $schemaUuid=null,
        ?string $registerUuid=null,
        ?string $organizationUuid=null
    ): ?bool {
        // Create cache key for this specific permission check.
        $cacheKey = $this->buildPermissionCacheKey($userId, $action, $schemaUuid, $registerUuid, $organizationUuid);

        // Try distributed cache first.
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached === 'true' ? true : ($cached === 'false' ? false : null);
            }
        }

        // If not cached, evaluate and cache result.
        $result = $this->evaluateUserPermission($userId, $action, $schemaUuid, $registerUuid, $organizationUuid);

        // Cache the result for future requests (5 minutes TTL).
        if ($this->cache !== null) {
            $cacheValue = $result === true ? 'true' : ($result === false ? 'false' : 'null');
            $this->cache->set($cacheKey, $cacheValue, 300);
        }

        return $result;

    }//end evaluateUserPermissionOptimized()


    /**
     * Build cache key for permission evaluation
     *
     * @param string      $userId           The user ID
     * @param string      $action           The action
     * @param string|null $schemaUuid       Optional schema UUID
     * @param string|null $registerUuid     Optional register UUID
     * @param string|null $organizationUuid Optional organization UUID
     *
     * @return string The cache key
     */
    private function buildPermissionCacheKey(
        string $userId,
        string $action,
        ?string $schemaUuid=null,
        ?string $registerUuid=null,
        ?string $organizationUuid=null
    ): string {
        return 'auth_perm_'.md5($userId.'_'.$action.'_'.($schemaUuid ?? '').'_'.($registerUuid ?? '').'_'.($organizationUuid ?? ''));

    }//end buildPermissionCacheKey()


    /**
     * Check if user has exceptions with caching to avoid repeated database queries
     *
     * @param string $userId The user ID to check
     *
     * @return bool True if user has any active exceptions
     */
    public function userHasExceptionsOptimized(string $userId): bool
    {
        // Check in-memory cache first.
        if (isset($this->userExceptionCache[$userId])) {
            return !empty($this->userExceptionCache[$userId]);
        }

        // Check distributed cache.
        $cacheKey = 'user_has_exceptions_'.$userId;
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached === 'true';
            }
        }

        // Compute and cache result.
        $hasExceptions = $this->userHasExceptions($userId);

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $hasExceptions ? 'true' : 'false', 300);
        }

        return $hasExceptions;

    }//end userHasExceptionsOptimized()


    /**
     * Get user groups with caching to avoid repeated group manager calls
     *
     * @param string $userId The user ID
     *
     * @return array<string> Array of group IDs the user belongs to
     */
    private function getUserGroupsCached(string $userId): array
    {
        // Check in-memory cache first.
        if (isset($this->groupMembershipCache[$userId])) {
            return $this->groupMembershipCache[$userId];
        }

        // Get user object and groups.
        $userObj    = $this->groupManager->get($userId);
        $userGroups = [];

        if ($userObj !== null) {
            $groups = $this->groupManager->getUserGroups($userObj);
            foreach ($groups as $group) {
                $userGroups[] = $group->getGID();
            }
        }

        // Cache in memory for this request.
        $this->groupMembershipCache[$userId] = $userGroups;

        return $userGroups;

    }//end getUserGroupsCached()


    /**
     * Preload exceptions for multiple users to optimize batch operations
     *
     * This method loads exceptions for multiple users in a single database query,
     * significantly improving performance for bulk operations.
     *
     * @param array<string> $userIds Array of user IDs to preload exceptions for
     * @param string        $action  Optional action to filter by
     *
     * @return void
     */
    public function preloadUserExceptions(array $userIds, string $action=''): void
    {
        if (empty($userIds)) {
            return;
        }

        $this->logger->debug(
                'Preloading exceptions for users',
                [
                    'user_count' => count($userIds),
                    'action'     => $action,
                ]
                );

        // Load user exceptions in batch.
        foreach ($userIds as $userId) {
            if (!isset($this->userExceptionCache[$userId])) {
                $this->userExceptionCache[$userId] = $this->mapper->findBySubject(
                    AuthorizationException::SUBJECT_TYPE_USER,
                    $userId
                );
            }
        }

        // Also preload group exceptions for all users.
        foreach ($userIds as $userId) {
            $userGroups = $this->getUserGroupsCached($userId);
            foreach ($userGroups as $groupId) {
                $groupCacheKey = 'group_'.$groupId;
                if (!isset($this->userExceptionCache[$groupCacheKey])) {
                    $this->userExceptionCache[$groupCacheKey] = $this->mapper->findBySubject(
                        AuthorizationException::SUBJECT_TYPE_GROUP,
                        $groupId
                    );
                }
            }
        }

    }//end preloadUserExceptions()


    /**
     * Clear all caches (useful for testing or after exception changes)
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->userExceptionCache   = [];
        $this->groupMembershipCache = [];

        if ($this->cache !== null) {
            $this->cache->clear();
        }

        $this->logger->debug('Authorization exception caches cleared');

    }//end clearCache()


    /**
     * Get performance metrics for monitoring
     *
     * @return array<string, mixed> Performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'memory_cache_entries'        => count($this->userExceptionCache),
            'group_cache_entries'         => count($this->groupMembershipCache),
            'distributed_cache_available' => $this->cache !== null,
            'cache_factory_available'     => $this->cacheFactory !== null,
        ];

    }//end getPerformanceMetrics()


    /**
     * Evaluate authorization exceptions for a user and action
     *
     * This method determines if a user has permission based on authorization exceptions.
     * It returns:
     * - true: User has explicit permission (inclusion found)
     * - false: User is explicitly denied (exclusion found)
     * - null: No applicable exceptions, fall back to normal RBAC
     *
     * @param string      $userId           The user ID to check
     * @param string      $action           The action to check
     * @param string|null $schemaUuid       Optional schema UUID
     * @param string|null $registerUuid     Optional register UUID
     * @param string|null $organizationUuid Optional organization UUID
     *
     * @return bool|null True if allowed, false if denied, null if no applicable exceptions
     */
    public function evaluateUserPermission(
        string $userId,
        string $action,
        ?string $schemaUuid=null,
        ?string $registerUuid=null,
        ?string $organizationUuid=null
    ): ?bool {
        // Get all applicable exceptions for this user.
        $userExceptions = $this->mapper->findApplicableExceptions(
            AuthorizationException::SUBJECT_TYPE_USER,
            $userId,
            $action,
            $schemaUuid,
            $registerUuid,
            $organizationUuid
        );

        // Get user's groups using cached method and find applicable group exceptions.
        $userGroups      = $this->getUserGroupsCached($userId);
        $groupExceptions = [];

        foreach ($userGroups as $groupId) {
            $exceptions      = $this->mapper->findApplicableExceptions(
                AuthorizationException::SUBJECT_TYPE_GROUP,
                $groupId,
                $action,
                $schemaUuid,
                $registerUuid,
                $organizationUuid
            );
            $groupExceptions = array_merge($groupExceptions, $exceptions);
        }

        // Combine all exceptions and sort by priority.
        $allExceptions = array_merge($userExceptions, $groupExceptions);
        usort(
                $allExceptions,
                function (AuthorizationException $a, AuthorizationException $b): int {
                    return $b->getPriority() <=> $a->getPriority();
                    // Sort by priority descending.
                }
                );

        // Evaluate exceptions in priority order.
        foreach ($allExceptions as $exception) {
            if ($exception->isExclusion()) {
                // Exclusion found - user is denied access.
                $this->logger->debug(
                        'Authorization exclusion applied',
                        [
                            'user_id'        => $userId,
                            'action'         => $action,
                            'exception_uuid' => $exception->getUuid(),
                            'subject_type'   => $exception->getSubjectType(),
                            'subject_id'     => $exception->getSubjectId(),
                            'priority'       => $exception->getPriority(),
                        ]
                        );
                return false;
            }

            if ($exception->isInclusion()) {
                // Inclusion found - user is granted access.
                $this->logger->debug(
                        'Authorization inclusion applied',
                        [
                            'user_id'        => $userId,
                            'action'         => $action,
                            'exception_uuid' => $exception->getUuid(),
                            'subject_type'   => $exception->getSubjectType(),
                            'subject_id'     => $exception->getSubjectId(),
                            'priority'       => $exception->getPriority(),
                        ]
                        );
                return true;
            }
        }//end foreach

        // No applicable exceptions found - fall back to normal RBAC.
        return null;

    }//end evaluateUserPermission()


    /**
     * Check if a user has any authorization exceptions
     *
     * @param string $userId The user ID to check
     *
     * @return bool True if user has any active exceptions
     */
    public function userHasExceptions(string $userId): bool
    {
        $userExceptions = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_USER, $userId);

        if (count($userExceptions) > 0) {
            return true;
        }

        // Check group exceptions using cached group lookup.
        $userGroups = $this->getUserGroupsCached($userId);
        foreach ($userGroups as $groupId) {
            $groupExceptions = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_GROUP, $groupId);
            if (count($groupExceptions) > 0) {
                return true;
            }
        }

        return false;

    }//end userHasExceptions()


    /**
     * Get all authorization exceptions for a user (including group exceptions)
     *
     * @param string $userId The user ID
     *
     * @return array<AuthorizationException> Array of authorization exceptions
     */
    public function getUserExceptions(string $userId): array
    {
        // Get direct user exceptions.
        $userExceptions = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_USER, $userId);

        // Get group exceptions using cached group lookup.
        $userGroups      = $this->getUserGroupsCached($userId);
        $groupExceptions = [];

        foreach ($userGroups as $groupId) {
            $exceptions      = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_GROUP, $groupId);
            $groupExceptions = array_merge($groupExceptions, $exceptions);
        }

        // Combine and sort by priority.
        $allExceptions = array_merge($userExceptions, $groupExceptions);
        usort(
                $allExceptions,
                function (AuthorizationException $a, AuthorizationException $b): int {
                    return $b->getPriority() <=> $a->getPriority();
                }
                );

        return $allExceptions;

    }//end getUserExceptions()


    /**
     * Validate exception parameters
     *
     * @param string $type        The exception type
     * @param string $subjectType The subject type
     * @param string $subjectId   The subject ID
     * @param string $action      The action
     *
     * @throws InvalidArgumentException If any parameter is invalid
     *
     * @return void
     */
    private function validateExceptionParameters(
        string $type,
        string $subjectType,
        string $subjectId,
        string $action
    ): void {
        // Validate type.
        if (!in_array($type, AuthorizationException::getValidTypes(), true)) {
            throw new InvalidArgumentException(
                'Invalid exception type: '.$type.'. Valid types: '.implode(', ', AuthorizationException::getValidTypes())
            );
        }

        // Validate subject type.
        if (!in_array($subjectType, AuthorizationException::getValidSubjectTypes(), true)) {
            throw new InvalidArgumentException(
                'Invalid subject type: '.$subjectType.'. Valid types: '.implode(', ', AuthorizationException::getValidSubjectTypes())
            );
        }

        // Validate action.
        if (!in_array($action, AuthorizationException::getValidActions(), true)) {
            throw new InvalidArgumentException(
                'Invalid action: '.$action.'. Valid actions: '.implode(', ', AuthorizationException::getValidActions())
            );
        }

        // Validate subject exists.
        if ($subjectType === AuthorizationException::SUBJECT_TYPE_USER) {
            // @todo Could add user existence validation here.
        } else if ($subjectType === AuthorizationException::SUBJECT_TYPE_GROUP) {
            if (!$this->groupManager->groupExists($subjectId)) {
                throw new InvalidArgumentException('Group does not exist: '.$subjectId);
            }
        }

    }//end validateExceptionParameters()


}//end class
