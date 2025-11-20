<?php
/**
 * MagicMapper Organization Handler
 *
 * This handler provides multi-tenancy support for dynamic schema-based tables.
 * It implements organization-based filtering to ensure that users can only access
 * objects that belong to their active organization, maintaining data isolation
 * between different tenants.
 *
 * KEY RESPONSIBILITIES:
 * - Apply organization-based filtering to dynamic table queries
 * - Handle multi-tenancy isolation between organizations
 * - Support for default organization special behaviors
 * - Integration with user session and organization context
 * - Admin override capabilities for cross-organization access
 *
 * MULTI-TENANCY FEATURES:
 * - Organization-based object isolation
 * - Default organization special handling
 * - Published object cross-organization visibility
 * - Admin users cross-organization access (configurable)
 * - Unauthenticated user organization filtering
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Service\MagicMapperHandlers
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper organization support
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MagicMapperHandlers;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Organization filtering handler for MagicMapper dynamic tables
 *
 * This class provides multi-tenancy support for dynamically created schema-based
 * tables, ensuring proper data isolation between organizations while supporting
 * appropriate cross-organization access patterns.
 */
class MagicOrganizationHandler
{


    /**
     * Constructor for MagicOrganizationHandler
     *
     * @param IDBConnection   $db           Database connection
     * @param IUserSession    $userSession  User session for current user context
     * @param IGroupManager   $groupManager Group manager for user groups
     * @param IUserManager    $userManager  User manager for user operations
     * @param IAppConfig      $appConfig    App configuration for multi-tenancy settings
     * @param LoggerInterface $logger       Logger for debugging and error reporting
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Apply organization filtering for multi-tenancy to dynamic table queries
     *
     * This method adds WHERE conditions to filter objects based on the user's
     * active organization, ensuring proper data isolation between tenants.
     *
     * @param IQueryBuilder $qb                     Query builder to modify
     * @param Register      $register               Register context
     * @param Schema        $schema                 Schema context
     * @param string        $tableAlias             Table alias for the dynamic table (default: 't')
     * @param string|null   $activeOrganisationUuid Active organization UUID to filter by
     * @param bool          $multi                  Whether to apply multitenancy filtering (default: true)
     *
     * @return void
     */
    public function applyOrganizationFilters(
        IQueryBuilder $qb,
        Register $register,
        Schema $schema,
        string $tableAlias='t',
        ?string $activeOrganisationUuid=null,
        bool $multi=true
    ): void {
        // If multitenancy is disabled, skip all organization filtering
        if ($multi === false || !$this->isMultiTenancyEnabled()) {
            return;
        }

        // Get current user to check if they're admin
        $user   = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : null;

        if ($userId === null) {
            // For unauthenticated requests, show published objects only
            $this->applyUnauthenticatedOrganizationAccess($qb, $tableAlias);
            return;
        }

        // Use provided active organization UUID or return (no filtering)
        if ($activeOrganisationUuid === null) {
            return;
        }

        // Check if this is the system-wide default organization
        $systemDefaultOrgUuid = $this->getSystemDefaultOrganizationUuid();
        $isSystemDefaultOrg   = ($activeOrganisationUuid === $systemDefaultOrgUuid);

        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);

            // Check if user is admin and admin override is enabled
            if (in_array('admin', $userGroups)) {
                if ($this->isAdminOverrideEnabled()) {
                    return;
                    // No filtering for admin users when override is enabled
                }

                // If admin override is disabled, apply organization filtering for admin users
                if ($activeOrganisationUuid === null) {
                    return;
                    // No filtering if no active organization
                }

                if ($isSystemDefaultOrg) {
                    return;
                    // Admin with default org sees everything
                }

                // Continue with organization filtering for non-default org
            }
        }//end if

        $organizationColumn = $tableAlias.'._organisation';

        // Build organization filter conditions
        $orgConditions = $qb->expr()->orX();

        // Objects explicitly belonging to the user's organization
        $orgConditions->add(
            $qb->expr()->eq($organizationColumn, $qb->createNamedParameter($activeOrganisationUuid))
        );

        // Include published objects from any organization if configured to do so
        if ($this->shouldPublishedObjectsBypassMultiTenancy()) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $orgConditions->add(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull("{$tableAlias}._published"),
                    $qb->expr()->lte("{$tableAlias}._published", $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull("{$tableAlias}._depublished"),
                        $qb->expr()->gt("{$tableAlias}._depublished", $qb->createNamedParameter($now))
                    )
                )
            );
        }

        // If this is the system-wide default organization, include additional objects
        if ($isSystemDefaultOrg) {
            // Include objects with NULL organization (legacy data)
            $orgConditions->add(
                $qb->expr()->isNull($organizationColumn)
            );
        }

        $qb->andWhere($orgConditions);

    }//end applyOrganizationFilters()


    /**
     * Check if published objects should bypass multi-tenancy restrictions
     *
     * @return bool True if published objects should bypass multi-tenancy, false otherwise
     */
    private function shouldPublishedObjectsBypassMultiTenancy(): bool
    {
        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig)) {
            return false;
            // Default to false for security
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false;

    }//end shouldPublishedObjectsBypassMultiTenancy()


    /**
     * Apply organization access rules for unauthenticated users
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param string        $tableAlias Table alias for the dynamic table
     *
     * @return void
     */
    private function applyUnauthenticatedOrganizationAccess(IQueryBuilder $qb, string $tableAlias): void
    {
        // For unauthenticated requests, show only published objects
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb->andWhere(
            $qb->expr()->andX(
                $qb->expr()->isNotNull("{$tableAlias}._published"),
                $qb->expr()->lte("{$tableAlias}._published", $qb->createNamedParameter($now)),
                $qb->expr()->orX(
                    $qb->expr()->isNull("{$tableAlias}._depublished"),
                    $qb->expr()->gt("{$tableAlias}._depublished", $qb->createNamedParameter($now))
                )
            )
        );

    }//end applyUnauthenticatedOrganizationAccess()


    /**
     * Get the system default organization UUID
     *
     * @return string|null Default organization UUID or null if not found
     */
    private function getSystemDefaultOrganizationUuid(): ?string
    {
        try {
            // Get default organisation UUID from configuration (not deprecated is_default column)
            $defaultUuid = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
            return $defaultUuid !== '' ? $defaultUuid : null;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get system default organization from configuration',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return null;
        }

    }//end getSystemDefaultOrganizationUuid()


    /**
     * Check if multi-tenancy is enabled in app configuration
     *
     * @return bool True if multi-tenancy is enabled, false otherwise
     */
    private function isMultiTenancyEnabled(): bool
    {
        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig)) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['enabled'] ?? false;

    }//end isMultiTenancyEnabled()


    /**
     * Check if admin override is enabled in app configuration
     *
     * @return bool True if admin override is enabled, false otherwise
     */
    private function isAdminOverrideEnabled(): bool
    {
        $rbacConfig = $this->appConfig->getValueString('openregister', 'rbac', '');
        if (empty($rbacConfig)) {
            return true;
            // Default to true if no RBAC config exists
        }

        $rbacData = json_decode($rbacConfig, true);
        return $rbacData['adminOverride'] ?? true;

    }//end isAdminOverrideEnabled()


    /**
     * Get current user's active organization
     *
     * @return string|null Active organization UUID or null
     */
    public function getCurrentUserActiveOrganization(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        // This would typically come from OrganisationService
        // For now, return null and let the caller provide it
        return null;

    }//end getCurrentUserActiveOrganization()


    /**
     * Check if user belongs to specific organization
     *
     * @param string   $userId         User ID to check
     * @param string   $organizationId Organization UUID to check
     * @param Register $register       Register context
     * @param Schema   $schema         Schema context
     *
     * @return bool True if user belongs to organization
     */
    public function userBelongsToOrganization(string $userId, string $organizationId, Register $register, Schema $schema): bool
    {
        // This would implement organization membership checks
        // For now, simplified implementation
        return true;

    }//end userBelongsToOrganization()


    /**
     * Set default organization for objects that don't have one
     *
     * @param array  $objects        Array of object data
     * @param string $defaultOrgUuid Default organization UUID
     *
     * @return array Array of objects with organization set
     */
    public function setDefaultOrganization(array $objects, string $defaultOrgUuid): array
    {
        foreach ($objects as &$object) {
            if (!isset($object['_organisation']) || empty($object['_organisation'])) {
                $object['_organisation'] = $defaultOrgUuid;
            }
        }

        return $objects;

    }//end setDefaultOrganization()


}//end class
