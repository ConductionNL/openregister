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
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper RBAC capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTime;
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * @param LoggerInterface $logger       Logger for debugging
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
     * Apply RBAC filters to a query builder based on schema authorization
     *
     * This method implements the RBAC filtering logic:
     * 1. If user is admin, no filtering is applied
     * 2. If schema has no authorization, no filtering is applied (open access)
     * 3. If user's groups match authorized groups for 'read' action, access is granted
     * 4. If object is owned by user, access is granted
     * 5. If object is published and 'public' is in read groups, access is granted
     *
     * @param IQueryBuilder $qb     Query builder to modify
     * @param Schema        $schema Schema with authorization configuration
     * @param string        $action CRUD action to check (default: 'read')
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function applyRbacFilters(IQueryBuilder $qb, Schema $schema, string $action = 'read'): void
    {
        $user = $this->userSession->getUser();
        $userId = $user?->getUID();

        // Get user groups
        $userGroups = [];
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
        }

        // Admin users bypass all RBAC checks
        if (in_array('admin', $userGroups, true) === true) {
            $this->logger->debug('MagicRbacHandler: Admin user, bypassing RBAC filters');
            return;
        }

        // Get schema authorization configuration
        $authorization = $schema->getAuthorization();

        // If no authorization is configured, schema is open to all
        if (empty($authorization) === true) {
            $this->logger->debug('MagicRbacHandler: No authorization configured, schema is open');
            return;
        }

        // Get authorized groups for this action
        $authorizedGroups = $authorization[$action] ?? [];

        // If action is not configured in authorization, it's open to all
        if (empty($authorizedGroups) === true) {
            $this->logger->debug('MagicRbacHandler: Action not configured, open access', ['action' => $action]);
            return;
        }

        // Check if 'public' is in authorized groups - if so, bypass RBAC entirely
        // Public schemas are readable by everyone without requiring publication
        $publicAccess = in_array('public', $authorizedGroups, true);
        if ($publicAccess === true) {
            $this->logger->debug('MagicRbacHandler: Public access configured, bypassing RBAC filters');
            return;
        }

        // Check if user has any matching groups
        $hasGroupAccess = false;
        foreach ($userGroups as $groupId) {
            if (in_array($groupId, $authorizedGroups, true) === true) {
                $hasGroupAccess = true;
                break;
            }
        }

        // Build the RBAC filter conditions
        $conditions = [];

        // Condition 1: User is the owner of the object
        if ($userId !== null) {
            $conditions[] = $qb->expr()->eq('t._owner', $qb->createNamedParameter($userId));
        }

        // Condition 2: User has group-based access
        if ($hasGroupAccess === true) {
            // User has full access based on their groups - no additional filtering needed
            $this->logger->debug('MagicRbacHandler: User has group access', [
                'userId' => $userId,
                'userGroups' => $userGroups,
                'authorizedGroups' => $authorizedGroups
            ]);
            return;
        }

        // Condition 3: Object is published AND public access is allowed
        if ($publicAccess === true) {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->isNotNull('t._published'),
                $qb->expr()->lte('t._published', $qb->createNamedParameter($now)),
                $qb->expr()->orX(
                    $qb->expr()->isNull('t._depublished'),
                    $qb->expr()->gt('t._depublished', $qb->createNamedParameter($now))
                )
            );
        }

        // If no conditions were added and user doesn't have access, deny all
        if (empty($conditions) === true) {
            $this->logger->debug('MagicRbacHandler: No access conditions met, denying all', [
                'userId' => $userId,
                'action' => $action
            ]);
            // Add impossible condition to return no results
            $qb->andWhere($qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)));
            return;
        }

        // Apply OR of all conditions
        $qb->andWhere($qb->expr()->orX(...$conditions));

        $this->logger->debug('MagicRbacHandler: Applied RBAC filters', [
            'userId' => $userId,
            'action' => $action,
            'conditionsCount' => count($conditions)
        ]);
    }//end applyRbacFilters()

    /**
     * Check if a user has permission to perform an action on a schema
     *
     * This is a non-query version of the RBAC check for use in validation.
     *
     * @param Schema      $schema      Schema to check
     * @param string      $action      CRUD action to check
     * @param string|null $objectOwner Optional object owner for ownership check
     *
     * @return bool True if user has permission
     */
    public function hasPermission(Schema $schema, string $action, ?string $objectOwner = null): bool
    {
        $user = $this->userSession->getUser();
        $userId = $user?->getUID();

        // Get user groups
        $userGroups = [];
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
        }

        // Admin users have all permissions
        if (in_array('admin', $userGroups, true) === true) {
            return true;
        }

        // Object owner has all permissions
        if ($userId !== null && $objectOwner !== null && $objectOwner === $userId) {
            return true;
        }

        // Get schema authorization
        $authorization = $schema->getAuthorization();

        // If no authorization configured, everyone has access
        if (empty($authorization) === true) {
            return true;
        }

        // Get authorized groups for this action
        $authorizedGroups = $authorization[$action] ?? [];

        // If action not configured, everyone has access
        if (empty($authorizedGroups) === true) {
            return true;
        }

        // Check if user has any matching groups
        foreach ($userGroups as $groupId) {
            if (in_array($groupId, $authorizedGroups, true) === true) {
                return true;
            }
        }

        // Check if public access is allowed (for unauthenticated users)
        if ($userId === null && in_array('public', $authorizedGroups, true) === true) {
            return true;
        }

        return false;
    }//end hasPermission()

    /**
     * Get the current user ID
     *
     * @return string|null The current user ID or null if not authenticated
     */
    public function getCurrentUserId(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end getCurrentUserId()

    /**
     * Get the current user's groups
     *
     * @return string[] Array of group IDs
     */
    public function getCurrentUserGroups(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }
        return $this->groupManager->getUserGroupIds($user);
    }//end getCurrentUserGroups()

    /**
     * Check if current user is admin
     *
     * @return bool True if user is in admin group
     */
    public function isAdmin(): bool
    {
        return in_array('admin', $this->getCurrentUserGroups(), true);
    }//end isAdmin()
}//end class
