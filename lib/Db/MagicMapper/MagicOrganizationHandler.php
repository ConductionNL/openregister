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
 * - Admin users cross-organization access (configurable)
 * - Unauthenticated user organization filtering
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper organization support
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

/**
 * Organization filtering handler for MagicMapper dynamic tables
 *
 * This class provides multi-tenancy support for dynamically created schema-based
 * tables, ensuring proper data isolation between organizations while supporting
 * appropriate cross-organization access patterns.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MagicOrganizationHandler
{
    /**
     * Constructor for MagicOrganizationHandler.
     *
     * @param IUserSession       $userSession  User session manager
     * @param IGroupManager      $groupManager Group manager
     * @param IAppConfig         $appConfig    Application configuration
     * @param ContainerInterface $container    Container for lazy loading services
     * @param LoggerInterface    $logger       Logger for logging operations
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Apply organization-based filtering to a query builder
     *
     * This method implements multi-tenancy filtering:
     * 1. Admin users can optionally bypass organization filtering
     * 2. Objects belonging to the user's active organization are accessible
     * 3. Objects belonging to parent organizations are accessible
     * 4. Objects with null organization are accessible to all (legacy/global data)
     *
     * @param IQueryBuilder $qb                 Query builder to modify
     * @param bool          $adminBypassEnabled Whether admin users can bypass org filtering
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function applyOrganizationFilter(
        IQueryBuilder $qb,
        bool $adminBypassEnabled=false
    ): void {
        $user = $this->userSession->getUser();

        // Check if user is admin - admins can see all objects including those with null organization.
        $isAdmin = false;
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
            $isAdmin    = in_array('admin', $userGroups, true);
        }

        // Check if admin bypass is enabled (disabled in SaaS mode).
        if ($adminBypassEnabled === true && $isAdmin === true) {
            // In SaaS mode, never bypass organisation boundary.
            $saasMode = $this->isSaasModeEnabled();
            if ($saasMode === true) {
                $this->logger->debug(
                    message: '[MagicOrganizationHandler] SaaS mode active — admin bypass disabled for org boundary',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            } else {
                $this->logger->debug(
                    message: '[MagicOrganizationHandler] Admin bypass enabled, skipping org filter',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return;
            }
        }

        // Get the active organization UUID(s) for the current user.
        $activeOrgUuids = $this->getActiveOrganizationUuids();

        if (empty($activeOrgUuids) === true) {
            $this->logger->debug(
                message: '[MagicOrganizationHandler] No active organization, applying public filter',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // No active organization - admins can see null-org objects, others get no results.
            if ($isAdmin !== true) {
                $qb->andWhere('1 = 0');
                return;
            }

            $qb->andWhere($qb->expr()->isNull('t._organisation'));
            return;
        }//end if

        // Build conditions for organization filtering.
        $conditions = [];

        // Condition 1: Objects belonging to the user's active organization(s).
        $conditions[] = $qb->expr()->in(
            't._organisation',
            $qb->createNamedParameter($activeOrgUuids, IQueryBuilder::PARAM_STR_ARRAY)
        );
        if (count($activeOrgUuids) === 1) {
            array_pop($conditions);
            $conditions[] = $qb->expr()->eq(
                't._organisation',
                $qb->createNamedParameter($activeOrgUuids[0])
            );
        }

        // Condition 2: Objects with null organization - ONLY for admin users.
        if ($isAdmin === true) {
            $conditions[] = $qb->expr()->isNull('t._organisation');
        }

        // Apply OR of all conditions.
        $qb->andWhere($qb->expr()->orX(...$conditions));

        $this->logger->debug(
            message: '[MagicOrganizationHandler] Applied organization filter',
            context: [
                'file'            => __FILE__,
                'line'            => __LINE__,
                'activeOrgUuids'  => $activeOrgUuids,
                'conditionsCount' => count($conditions),
                'isAdmin'         => $isAdmin,
            ]
                );
    }//end applyOrganizationFilter()

    /**
     * Get the active organization UUID(s) for the current user
     *
     * Returns an array of organization UUIDs that the current user has access to,
     * including the active organization and its parent organizations.
     *
     * @return string[] Array of organization UUIDs
     */
    public function getActiveOrganizationUuids(): array
    {
        try {
            // Get OrganisationService from container (lazy loading to avoid circular dependencies).
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');

            // Get active organisations including parent chain.
            $orgUuids = $organisationService->getUserActiveOrganisations();

            $this->logger->debug(
                message: '[MagicOrganizationHandler] getUserActiveOrganisations returned',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'orgUuids' => $orgUuids,
                    'user'     => $this->userSession->getUser()?->getUID(),
                ]
                    );

            if (empty($orgUuids) === false) {
                return $orgUuids;
            }

            // Fallback: try to get just the active organisation.
            $activeOrg = $organisationService->getActiveOrganisation();
            if ($activeOrg !== null) {
                $this->logger->debug(
                    message: '[MagicOrganizationHandler] getActiveOrganisation returned',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'uuid' => $activeOrg->getUuid(),
                    ]
                        );
                return [$activeOrg->getUuid()];
            }

            $this->logger->debug(
                message: '[MagicOrganizationHandler] No active organisation found',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MagicOrganizationHandler] Failed to get active organisation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
                    );
            return [];
        }//end try
    }//end getActiveOrganizationUuids()

    /**
     * Get the primary active organization UUID for the current user
     *
     * @return string|null The active organization UUID or null if none
     */
    public function getActiveOrganizationUuid(): ?string
    {
        $uuids = $this->getActiveOrganizationUuids();
        return $uuids[0] ?? null;
    }//end getActiveOrganizationUuid()

    /**
     * Check if an object belongs to the user's active organization
     *
     * @param string|null $objectOrganisation The organization UUID of the object
     *
     * @return bool True if object belongs to user's organization
     */
    public function belongsToActiveOrganization(?string $objectOrganisation): bool
    {
        if ($objectOrganisation === null) {
            // Objects with null organization are only accessible to admin users.
            $user = $this->userSession->getUser();
            if ($user !== null) {
                $userGroups = $this->groupManager->getUserGroupIds($user);
                return in_array('admin', $userGroups, true);
            }

            return false;
        }

        $activeOrgUuids = $this->getActiveOrganizationUuids();

        return in_array($objectOrganisation, $activeOrgUuids, true);
    }//end belongsToActiveOrganization()

    /**
     * Get the default organization UUID from app config
     *
     * @return string|null The default organization UUID or null
     */
    public function getDefaultOrganizationUuid(): ?string
    {
        $defaultOrgId = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
        if ($defaultOrgId !== '') {
            return $defaultOrgId;
        }

        return null;
    }//end getDefaultOrganizationUuid()

    /**
     * Check if admin users should bypass multi-tenancy filtering
     *
     * This reads the adminOverride setting from the multitenancy config,
     * ensuring consistent behavior with MultiTenancyTrait.
     *
     * @return bool True if admin users can bypass organization filtering
     */
    public function isAdminOverrideEnabled(): bool
    {
        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');

        // Default to true when no config exists (matches ConfigurationSettingsHandler defaults).
        if (empty($multitenancyConfig) === true) {
            return true;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        if ($multitenancyData === null) {
            return true;
        }

        // Default to true if not explicitly set (matches ConfigurationSettingsHandler).
        return $multitenancyData['adminOverride'] ?? true;
    }//end isAdminOverrideEnabled()

    /**
     * Check if the current user is logged in (not anonymous)
     *
     * @return bool True if a user is logged in, false for anonymous access
     */
    public function isUserLoggedIn(): bool
    {
        return $this->userSession->getUser() !== null;
    }//end isUserLoggedIn()

    /**
     * Check if SaaS mode is enabled in multitenancy configuration.
     *
     * When SaaS mode is enabled, organisation boundaries cannot be bypassed
     * even with admin override.
     *
     * @return bool True if SaaS mode is enabled
     */
    private function isSaasModeEnabled(): bool
    {
        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return ($multitenancyData['saasMode'] ?? false) === true;
    }//end isSaasModeEnabled()
}//end class
