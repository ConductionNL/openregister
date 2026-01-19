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

use DateTime;
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
     * 4. Published objects may be accessible across organizations (if configured)
     * 5. Objects with null organization are accessible to all (legacy/global data)
     *
     * @param IQueryBuilder $qb                    Query builder to modify
     * @param bool          $allowPublishedAccess  Whether to allow access to published objects from other orgs
     * @param bool          $adminBypassEnabled    Whether admin users can bypass org filtering
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function applyOrganizationFilter(
        IQueryBuilder $qb,
        bool $allowPublishedAccess = true,
        bool $adminBypassEnabled = false
    ): void {
        $user = $this->userSession->getUser();

        // Check if admin bypass is enabled
        if ($adminBypassEnabled === true && $user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
            if (in_array('admin', $userGroups, true) === true) {
                $this->logger->debug('MagicOrganizationHandler: Admin bypass enabled, skipping org filter');
                return;
            }
        }

        // Get the active organization UUID(s) for the current user
        $activeOrgUuids = $this->getActiveOrganizationUuids();

        if (empty($activeOrgUuids) === true) {
            $this->logger->debug('MagicOrganizationHandler: No active organization, applying public/null filter');

            // No active organization - only show objects with null organization or published objects
            $conditions = [];

            // Condition 1: Objects with null organization (legacy/global data)
            $conditions[] = $qb->expr()->isNull('t._organisation');

            // Condition 2: Published objects (if allowed)
            if ($allowPublishedAccess === true) {
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

            $qb->andWhere($qb->expr()->orX(...$conditions));
            return;
        }

        // Build conditions for organization filtering
        $conditions = [];

        // Condition 1: Objects belonging to the user's active organization(s)
        if (count($activeOrgUuids) === 1) {
            $conditions[] = $qb->expr()->eq(
                't._organisation',
                $qb->createNamedParameter($activeOrgUuids[0])
            );
        } else {
            $conditions[] = $qb->expr()->in(
                't._organisation',
                $qb->createNamedParameter($activeOrgUuids, IQueryBuilder::PARAM_STR_ARRAY)
            );
        }

        // Condition 2: Objects with null organization (legacy/global data)
        $conditions[] = $qb->expr()->isNull('t._organisation');

        // Condition 3: Published objects from other organizations (if allowed)
        if ($allowPublishedAccess === true) {
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

        // Apply OR of all conditions
        $qb->andWhere($qb->expr()->orX(...$conditions));

        $this->logger->debug('MagicOrganizationHandler: Applied organization filter', [
            'activeOrgUuids' => $activeOrgUuids,
            'allowPublishedAccess' => $allowPublishedAccess,
            'conditionsCount' => count($conditions)
        ]);
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
            // Get OrganisationService from container (lazy loading to avoid circular dependencies)
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');

            // Get active organisations including parent chain
            $orgUuids = $organisationService->getUserActiveOrganisations();

            $this->logger->debug('MagicOrganizationHandler: getUserActiveOrganisations returned', [
                'orgUuids' => $orgUuids,
                'user' => $this->userSession->getUser()?->getUID()
            ]);

            if (empty($orgUuids) === false) {
                return $orgUuids;
            }

            // Fallback: try to get just the active organisation
            $activeOrg = $organisationService->getActiveOrganisation();
            if ($activeOrg !== null) {
                $this->logger->debug('MagicOrganizationHandler: getActiveOrganisation returned', [
                    'uuid' => $activeOrg->getUuid()
                ]);
                return [$activeOrg->getUuid()];
            }

            $this->logger->debug('MagicOrganizationHandler: No active organisation found');
            return [];
        } catch (\Exception $e) {
            $this->logger->warning('MagicOrganizationHandler: Failed to get active organisation', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
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
            // Objects with null organization are accessible to all
            return true;
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
        return $defaultOrgId !== '' ? $defaultOrgId : null;
    }//end getDefaultOrganizationUuid()
}//end class
