<?php
/**
 * MagicMapper RBAC Handler
 *
 * This handler provides role-based access control (RBAC) filtering for dynamic
 * schema-based tables. It implements the same RBAC logic as ObjectEntityMapper
 * but optimized for schema-specific table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Apply RBAC permission filters to dynamic table queries
 * - Handle user authentication and authorization checks
 * - Support publication-based public access controls
 * - Integrate with Nextcloud's user and group management
 * - Provide consistent security across all dynamic tables
 *
 * RBAC FEATURES:
 * - Schema-level authorization configuration
 * - User ownership validation
 * - Group-based access control
 * - Publication-based public access
 * - Admin override capabilities
 * - Unauthenticated user handling
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Service\MagicMapperHandlers
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper RBAC capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MagicMapperHandlers;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * RBAC (Role-Based Access Control) handler for MagicMapper dynamic tables
 *
 * This class provides comprehensive RBAC filtering for dynamically created
 * schema-based tables, ensuring that users can only access objects they have
 * permission to view based on schema authorization configurations.
 */
class MagicRbacHandler
{


    /**
     * Constructor for MagicRbacHandler
     *
     * @param IUserSession    $userSession  User session for current user context
     * @param IGroupManager   $groupManager Group manager for user group operations
     * @param IUserManager    $userManager  User manager for user operations
     * @param IAppConfig      $appConfig    App configuration for RBAC settings
     * @param LoggerInterface $logger       Logger for debugging and error reporting
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Apply RBAC permission filters to a dynamic table query
     *
     * This method adds WHERE conditions to filter objects based on the current user's
     * permissions according to the schema's authorization configuration.
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param Register      $register   Register context
     * @param Schema        $schema     Schema with authorization config
     * @param string        $tableAlias Table alias for the dynamic table (default: 't')
     * @param string|null   $userId     Optional user ID (defaults to current user)
     * @param bool          $rbac       Whether to apply RBAC checks (default: true)
     *
     * @return void
     */
    public function applyRbacFilters(
        IQueryBuilder $qb,
        Register $register,
        Schema $schema,
        string $tableAlias='t',
        ?string $userId=null,
        bool $rbac=true
    ): void {
        // If RBAC is disabled, skip all permission filtering.
        if ($rbac === false || $this->isRbacEnabled() === false) {
            return;
        }

        // Get current user if not provided.
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                // For unauthenticated requests, apply public access rules.
                $this->applyUnauthenticatedAccess($qb, $schema, $tableAlias);
                return;
            }

            $userId = $user->getUID();
        }

        // Get user object and groups.
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            // User doesn't exist, handle as unauthenticated.
            $this->applyUnauthenticatedAccess($qb, $schema, $tableAlias);
            return;
        }

        $userGroups = $this->groupManager->getUserGroupIds($userObj);

        // Admin users see everything if admin override is enabled.
        if (in_array('admin', $userGroups) === true && $this->isAdminOverrideEnabled() === true) {
            // No filtering needed for admin users.
            return;
        }

        // Build conditions for read access.
        $readConditions = $qb->expr()->orX();

        // 1. Check schema authorization configuration.
        $authorization = $schema->getAuthorization();

        if (empty($authorization) === true || $authorization === '{}') {
            // No authorization configured - open access.
            return;
        }

        if (is_string($authorization) === true) {
            $authConfig = json_decode($authorization, true);
        } else {
            $authConfig = $authorization;
        }

        if (is_array($authConfig) === false) {
            // Invalid authorization config - default to open access.
            return;
        }

        // 2. User is the object owner.
        $readConditions->add(
            $qb->expr()->eq("{$tableAlias}._owner", $qb->createNamedParameter($userId))
        );

        // 3. Check read permissions in authorization config.
        $readPerms = $authConfig['read'] ?? [];
        if (is_array($readPerms) === true) {
            // Check if user's groups are in the authorized groups for read action.
            foreach ($userGroups as $groupId) {
                if (in_array($groupId, $readPerms) === true) {
                    // User has read permission through group membership.
                    return;
                    // No filtering needed.
                }
            }

            // Check for public read access.
            if (in_array('public', $readPerms) === true) {
                return;
                // No filtering needed for public access.
            }
        }

        // Removed automatic published object access - this should be handled via explicit published filter.
        $qb->andWhere($readConditions);

    }//end applyRbacFilters()


    /**
     * Apply access rules for unauthenticated users
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param Schema        $schema     Schema with authorization config
     * @param string        $tableAlias Table alias for the dynamic table
     *
     * @return void
     */
    private function applyUnauthenticatedAccess(IQueryBuilder $qb, Schema $schema, string $tableAlias): void
    {
        $authorization = $schema->getAuthorization();

        if (empty($authorization) === true || $authorization === '{}') {
            // No authorization - public access allowed.
            return;
        }

        if (is_string($authorization) === true) {
            $authConfig = json_decode($authorization, true);
        } else {
            $authConfig = $authorization;
        }

        if (is_array($authConfig) === false) {
            // Invalid config - no automatic access, use explicit published filter.
            return;
        }

        $readPerms = $authConfig['read'] ?? [];

        // Check for explicit public read access.
        if (is_array($readPerms) === true && in_array('public', $readPerms) === true) {
            return;
            // Full public access - no filtering needed.
        }

    }//end applyUnauthenticatedAccess()


    /**
     * Create condition for published objects only
     *
     * @param IQueryBuilder $qb         Query builder
     * @param string        $tableAlias Table alias
     *
     * @return mixed Query condition for published objects
     */
    private function createPublishedCondition(IQueryBuilder $qb, string $tableAlias): mixed
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        return $qb->expr()->andX(
            $qb->expr()->isNotNull("{$tableAlias}._published"),
            $qb->expr()->lte("{$tableAlias}._published", $qb->createNamedParameter($now)),
            $qb->expr()->orX(
                $qb->expr()->isNull("{$tableAlias}._depublished"),
                $qb->expr()->gt("{$tableAlias}._depublished", $qb->createNamedParameter($now))
            )
        );

    }//end createPublishedCondition()


    /**
     * Check if RBAC is enabled in app configuration
     *
     * @return bool True if RBAC is enabled, false otherwise
     */
    private function isRbacEnabled(): bool
    {
        $rbacConfig = $this->appConfig->getValueString('openregister', 'rbac', '');
        if (empty($rbacConfig) === true) {
            return false;
        }

        $rbacData = json_decode($rbacConfig, true);
        $enabled  = $rbacData['enabled'] ?? false;
        return $enabled === true;

    }//end isRbacEnabled()


    /**
     * Check if RBAC admin override is enabled in app configuration
     *
     * @return bool True if RBAC admin override is enabled, false otherwise
     */
    private function isAdminOverrideEnabled(): bool
    {
        $rbacConfig = $this->appConfig->getValueString('openregister', 'rbac', '');
        if (empty($rbacConfig) === true) {
            return true;
            // Default to true if no RBAC config exists.
        }

        $rbacData      = json_decode($rbacConfig, true);
        $adminOverride = $rbacData['adminOverride'] ?? true;
        return $adminOverride === true;

    }//end isAdminOverrideEnabled()


    /**
     * Check if current user has admin privileges
     *
     * @param string|null $userId Optional user ID (defaults to current user)
     *
     * @return bool True if user is admin, false otherwise
     */
    public function isCurrentUserAdmin(?string $userId=null): bool
    {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return false;
            }

            $userId = $user->getUID();
        }

        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds($userObj);
        return in_array('admin', $userGroups) === true;

    }//end isCurrentUserAdmin()


    /**
     * Get current user ID
     *
     * @return string|null Current user ID or null if not authenticated
     */
    public function getCurrentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        return null;

    }//end getCurrentUserId()


    /**
     * Get current user groups
     *
     * @param string|null $userId Optional user ID (defaults to current user)
     *
     * @return array Array of group IDs the user belongs to
     */
    public function getCurrentUserGroups(?string $userId=null): array
    {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return [];
            }

            $userId = $user->getUID();
        }

        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            return [];
        }

        return $this->groupManager->getUserGroupIds($userObj);

    }//end getCurrentUserGroups()


}//end class
