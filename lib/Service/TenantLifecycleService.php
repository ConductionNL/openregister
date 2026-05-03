<?php

/**
 * Tenant Lifecycle Service
 *
 * Manages the lifecycle state machine for tenant organisations:
 * provisioning -> active -> suspended -> deprovisioning -> archived.
 * Also handles reactivation from suspended back to active.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-73
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-74
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-77
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-76
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-75
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-74
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * TenantLifecycleService
 *
 * Manages tenant organisation state transitions and provisioning workflows.
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TenantLifecycleService
{
    /**
     * Valid lifecycle states
     */
    public const STATUS_PROVISIONING   = 'provisioning';
    public const STATUS_ACTIVE         = 'active';
    public const STATUS_SUSPENDED      = 'suspended';
    public const STATUS_DEPROVISIONING = 'deprovisioning';
    public const STATUS_ARCHIVED       = 'archived';

    /**
     * Valid state transitions: current-state => [allowed-next-states]
     */
    private const STATE_TRANSITIONS = [
        self::STATUS_PROVISIONING   => [self::STATUS_ACTIVE],
        self::STATUS_ACTIVE         => [self::STATUS_SUSPENDED, self::STATUS_DEPROVISIONING],
        self::STATUS_SUSPENDED      => [self::STATUS_ACTIVE, self::STATUS_DEPROVISIONING],
        self::STATUS_DEPROVISIONING => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED       => [],
    ];

    /**
     * Valid OTAP environments
     */
    public const ENV_DEVELOPMENT = 'development';
    public const ENV_TEST        = 'test';
    public const ENV_ACCEPTANCE  = 'acceptance';
    public const ENV_PRODUCTION  = 'production';

    /**
     * OTAP order for promotion validation
     */
    public const OTAP_ORDER = [
        self::ENV_DEVELOPMENT => 0,
        self::ENV_TEST        => 1,
        self::ENV_ACCEPTANCE  => 2,
        self::ENV_PRODUCTION  => 3,
    ];

    /**
     * Constructor
     *
     * @param OrganisationMapper $organisationMapper Organisation mapper
     * @param IGroupManager      $groupManager       Nextcloud group manager
     * @param IEventDispatcher   $eventDispatcher    Event dispatcher
     * @param LoggerInterface    $logger             Logger
     */
    public function __construct(
        private readonly OrganisationMapper $organisationMapper,
        private readonly IGroupManager $groupManager,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Validate that a state transition is allowed.
     *
     * @param string $currentStatus Current lifecycle status
     * @param string $targetStatus  Desired lifecycle status
     *
     * @return void
     *
     * @throws Exception If the transition is invalid
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-73
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-76
     */
    public function validateTransition(string $currentStatus, string $targetStatus): void
    {
        $allowedTransitions = self::STATE_TRANSITIONS[$currentStatus] ?? [];

        if (in_array($targetStatus, $allowedTransitions, true) === false) {
            $message = sprintf(
                "Invalid state transition from '%s' to '%s'. Valid transitions: %s",
                $currentStatus,
                $targetStatus,
                implode(', ', $allowedTransitions)
            );
            throw new Exception($message, Response::HTTP_CONFLICT);
        }
    }//end validateTransition()

    /**
     * Get valid transitions for a status.
     *
     * @param string $status Current status
     *
     * @return string[] Valid next states
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-73
     */
    public function getValidTransitions(string $status): array
    {
        return self::STATE_TRANSITIONS[$status] ?? [];
    }//end getValidTransitions()

    /**
     * Provision a new organisation: create default groups, set RBAC, activate.
     *
     * @param Organisation $organisation The organisation in provisioning state
     * @param string       $adminUserId  The user who will be the org admin
     *
     * @return Organisation The activated organisation
     *
     * @throws Exception If provisioning fails
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-74
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-77
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function provision(Organisation $organisation, string $adminUserId): Organisation
    {
        if (($organisation->getStatus() ?? self::STATUS_PROVISIONING) !== self::STATUS_PROVISIONING) {
            throw new Exception(
                'Organisation must be in provisioning state to provision',
                Response::HTTP_CONFLICT
            );
        }

        $slug = $organisation->getSlug() ?? 'org';

        try {
            // Create default groups prefixed with org slug.
            $adminGroupId = $slug.'-admin';
            $usersGroupId = $slug.'-users';

            if ($this->groupManager->groupExists($adminGroupId) === false) {
                $this->groupManager->createGroup($adminGroupId);
            }

            if ($this->groupManager->groupExists($usersGroupId) === false) {
                $this->groupManager->createGroup($usersGroupId);
            }

            // Add admin user to admin group.
            $adminGroup = $this->groupManager->get($adminGroupId);
            $usersGroup = $this->groupManager->get($usersGroupId);

            if ($adminGroup !== null) {
                $user = \OC::$server->get(\OCP\IUserManager::class)->get($adminUserId);
                if ($user !== null) {
                    $adminGroup->addUser($user);
                    if ($usersGroup !== null) {
                        $usersGroup->addUser($user);
                    }
                }
            }

            // Set organisation groups.
            $organisation->setGroups([$adminGroupId, $usersGroupId]);

            // Set default authorization RBAC rules.
            $authorization = $organisation->getAuthorization();
            foreach ($authorization as &$permissions) {
                if (is_array($permissions) === false) {
                    continue;
                }

                if (isset($permissions['create']) === true) {
                    $permissions['create'] = [$adminGroupId, $usersGroupId];
                    $permissions['read']   = [$adminGroupId, $usersGroupId];
                    $permissions['update'] = [$adminGroupId, $usersGroupId];
                    $permissions['delete'] = [$adminGroupId];
                }
            }

            unset($permissions);
            $organisation->setAuthorization($authorization);

            // Add admin user to organisation.
            $organisation->addUser($adminUserId);

            // Transition to active.
            $organisation->setStatus(self::STATUS_ACTIVE);
            $organisation->setProvisionedAt(new DateTime());

            $result = $this->organisationMapper->update($organisation);

            $this->logger->info(
                '[TenantLifecycleService] Organisation provisioned and activated',
                ['uuid' => $organisation->getUuid(), 'slug' => $slug]
            );

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                '[TenantLifecycleService] Provisioning failed',
                ['uuid' => $organisation->getUuid(), 'error' => $e->getMessage()]
            );
            throw $e;
        }//end try
    }//end provision()

    /**
     * Suspend an active organisation.
     *
     * @param Organisation $organisation The organisation to suspend
     *
     * @return Organisation The suspended organisation
     *
     * @throws Exception If transition is invalid
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-76
     */
    public function suspend(Organisation $organisation): Organisation
    {
        $currentStatus = $organisation->getStatus() ?? self::STATUS_ACTIVE;
        $this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_SUSPENDED);

        $organisation->setStatus(self::STATUS_SUSPENDED);
        $organisation->setSuspendedAt(new DateTime());

        $result = $this->organisationMapper->update($organisation);

        $this->logger->info(
            '[TenantLifecycleService] Organisation suspended',
            ['uuid' => $organisation->getUuid()]
        );

        return $result;
    }//end suspend()

    /**
     * Reactivate a suspended organisation.
     *
     * @param Organisation $organisation The organisation to reactivate
     *
     * @return Organisation The reactivated organisation
     *
     * @throws Exception If transition is invalid
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-76
     */
    public function reactivate(Organisation $organisation): Organisation
    {
        $currentStatus = $organisation->getStatus() ?? self::STATUS_ACTIVE;
        $this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_ACTIVE);

        $organisation->setStatus(self::STATUS_ACTIVE);
        $organisation->setSuspendedAt(null);

        $result = $this->organisationMapper->update($organisation);

        $this->logger->info(
            '[TenantLifecycleService] Organisation reactivated',
            ['uuid' => $organisation->getUuid()]
        );

        return $result;
    }//end reactivate()

    /**
     * Start deprovisioning an organisation.
     *
     * @param Organisation $organisation The organisation to deprovision
     *
     * @return Organisation The organisation in deprovisioning state
     *
     * @throws Exception If transition is invalid
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-75
     */
    public function deprovision(Organisation $organisation): Organisation
    {
        $currentStatus = $organisation->getStatus() ?? self::STATUS_ACTIVE;
        $this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_DEPROVISIONING);

        $organisation->setStatus(self::STATUS_DEPROVISIONING);
        $organisation->setDeprovisionedAt(new DateTime());

        $result = $this->organisationMapper->update($organisation);

        $this->logger->info(
            '[TenantLifecycleService] Organisation deprovisioning started',
            ['uuid' => $organisation->getUuid()]
        );

        return $result;
    }//end deprovision()

    /**
     * Archive a deprovisioning organisation (called by background job).
     *
     * @param Organisation $organisation The organisation to archive
     *
     * @return Organisation The archived organisation
     *
     * @throws Exception If transition is invalid
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-75
     */
    public function archive(Organisation $organisation): Organisation
    {
        $currentStatus = $organisation->getStatus() ?? self::STATUS_DEPROVISIONING;
        $this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_ARCHIVED);

        $organisation->setStatus(self::STATUS_ARCHIVED);

        $result = $this->organisationMapper->update($organisation);

        $this->logger->info(
            '[TenantLifecycleService] Organisation archived',
            ['uuid' => $organisation->getUuid()]
        );

        return $result;
    }//end archive()

    /**
     * Validate an environment value.
     *
     * @param string $environment The environment to validate
     *
     * @return bool Whether the environment is valid
     *
     * @spec openspec/changes/retrofit-tenant-lifecycle-2026-04-28/tasks.md#task-2
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-74
     */
    public function isValidEnvironment(string $environment): bool
    {
        return isset(self::OTAP_ORDER[$environment]);
    }//end isValidEnvironment()

    /**
     * Validate OTAP promotion order (source must be lower than target).
     *
     * @param string $sourceEnv Source environment
     * @param string $targetEnv Target environment
     *
     * @return bool Whether the promotion order is valid
     *
     * @spec openspec/changes/retrofit-tenant-lifecycle-2026-04-28/tasks.md#task-2
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-74
     */
    public function isValidPromotionOrder(string $sourceEnv, string $targetEnv): bool
    {
        $sourceOrder = self::OTAP_ORDER[$sourceEnv] ?? -1;
        $targetOrder = self::OTAP_ORDER[$targetEnv] ?? -1;

        return $sourceOrder < $targetOrder;
    }//end isValidPromotionOrder()

    /**
     * Validate a status value.
     *
     * @param string $status The status to validate
     *
     * @return bool Whether the status is valid
     */
    public function isValidStatus(string $status): bool
    {
        return isset(self::STATE_TRANSITIONS[$status]);
    }//end isValidStatus()
}//end class
