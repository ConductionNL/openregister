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
        /**
         * @psalm-suppress UnusedProperty
         */
        private readonly IUserSession $userSession,
        /**
         * @psalm-suppress UnusedProperty
         */
        private readonly IGroupManager $groupManager,
        /**
         * @psalm-suppress UnusedProperty
         */
        private readonly IUserManager $userManager,
        private readonly IAppConfig $appConfig,

    ) {

    }//end __construct()



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





}//end class
