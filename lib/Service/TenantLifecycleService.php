<?php

/**
 * Tenant Lifecycle Service
 *
 * Manages the lifecycle state machine for tenant organisations:
 * provisioning -> active -> suspended -> deprovisioning -> archived.
 * Also handles reactivation from suspended back to active, and the retained
 * state: an organisation whose access has ended but whose data is kept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisation-entities-must-have-a-lifecycle-status-field-with-defined-state-transitions
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-provisioning-must-create-default-resources-automatically
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-terminated-organisation-must-be-able-to-keep-its-data-in-the-retained-state
 * @spec openspec/specs/tenant-lifecycle/spec.md
 * @spec openspec/specs/tenant-lifecycle/spec.md
 * @spec openspec/specs/tenant-lifecycle/spec.md
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor;
use OCP\IGroupManager;
use OCP\IUserManager;
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
class TenantLifecycleService {
	/**
	 * Valid lifecycle states
	 */
	public const STATUS_PROVISIONING = 'provisioning';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_DEPROVISIONING = 'deprovisioning';
	public const STATUS_ARCHIVED = 'archived';

	/**
	 * Access has ended and the data is kept.
	 *
	 * The terminal state for an organisation with a retention duty: a tenant
	 * whose contract ended, whose records must still be kept for a legal
	 * period. API access is blocked exactly as for `suspended`, and nothing is
	 * deleted. No background job selects this state, and TenantPurgeJob can
	 * only ever delete a row in PURGEABLE_STATUS, so a retained organisation
	 * keeps its data for as long as it stays here.
	 *
	 * It is entered from `active` or `suspended`, the same two states
	 * `deprovisioning` is entered from: the operator ending a tenancy chooses
	 * between deleting it and keeping it. There is no way back to `active`.
	 * The one way out is `deprovisioning`, taken on purpose when the retention
	 * period is over, so the data leaves through the same deletion path and
	 * grace period as every other organisation.
	 */
	public const STATUS_RETAINED = 'retained';

	/**
	 * The one lifecycle state TenantPurgeJob may permanently delete.
	 *
	 * Declared here, beside the transitions, so which states are deletable is
	 * a property of the lifecycle and not of the job. The job selects on it and
	 * re-checks every row against it before deleting anything, so a state that
	 * is not this one, `retained` above all, is never purged.
	 */
	public const PURGEABLE_STATUS = self::STATUS_ARCHIVED;

	/**
	 * Valid state transitions: current-state => [allowed-next-states]
	 */
	private const STATE_TRANSITIONS = [
		self::STATUS_PROVISIONING => [self::STATUS_ACTIVE],
		self::STATUS_ACTIVE => [self::STATUS_SUSPENDED, self::STATUS_DEPROVISIONING, self::STATUS_RETAINED],
		self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_DEPROVISIONING, self::STATUS_RETAINED],
		self::STATUS_DEPROVISIONING => [self::STATUS_ARCHIVED],
		self::STATUS_ARCHIVED => [],
		self::STATUS_RETAINED => [self::STATUS_DEPROVISIONING],
	];

	/**
	 * Valid OTAP environments
	 */
	public const ENV_DEVELOPMENT = 'development';
	public const ENV_TEST = 'test';
	public const ENV_ACCEPTANCE = 'acceptance';
	public const ENV_PRODUCTION = 'production';

	/**
	 * OTAP order for promotion validation
	 */
	public const OTAP_ORDER = [
		self::ENV_DEVELOPMENT => 0,
		self::ENV_TEST => 1,
		self::ENV_ACCEPTANCE => 2,
		self::ENV_PRODUCTION => 3,
	];

	/**
	 * Constructor
	 *
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper
	 * @param IGroupManager $groupManager Nextcloud group manager
	 * @param IUserManager $userManager Nextcloud user manager, to look up the org admin
	 * @param LoggerInterface $logger Logger
	 * @param OrganisationLifecycleAuditor|null $auditor Writes each status change to the audit trail. Null only in legacy fixtures.
	 */
	public function __construct(
		private readonly OrganisationMapperInterface $organisationMapper,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
		private readonly ?OrganisationLifecycleAuditor $auditor = null,
	) {
	}//end __construct()

	/**
	 * Validate that a state transition is allowed.
	 *
	 * @param string $currentStatus Current lifecycle status
	 * @param string $targetStatus Desired lifecycle status
	 *
	 * @return void
	 *
	 * @throws Exception If the transition is invalid
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisation-entities-must-have-a-lifecycle-status-field-with-defined-state-transitions
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function validateTransition(string $currentStatus, string $targetStatus): void {
		// Validate that both statuses are known before checking transition.
		if ($this->isValidStatus(status: $currentStatus) === false || $this->isValidStatus(status: $targetStatus) === false) {
			$message = sprintf("Unknown status '%s' or '%s'.", $currentStatus, $targetStatus);
			throw new Exception($message, Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$allowedTransitions = self::STATE_TRANSITIONS[$currentStatus] ?? [];

		if (in_array($targetStatus, $allowedTransitions, true) === false) {
			$valid = implode(', ', $allowedTransitions);
			$message = sprintf("Invalid state transition from '%s' to '%s'. Valid transitions: %s", $currentStatus, $targetStatus, $valid);
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
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisation-entities-must-have-a-lifecycle-status-field-with-defined-state-transitions
	 */
	public function getValidTransitions(string $status): array {
		return self::STATE_TRANSITIONS[$status] ?? [];
	}//end getValidTransitions()

	/**
	 * Provision a new organisation: create default groups, set RBAC, activate.
	 *
	 * @param Organisation $organisation The organisation in provisioning state
	 * @param string $adminUserId The user who will be the org admin
	 *
	 * @return Organisation The activated organisation
	 *
	 * @throws Exception If provisioning fails
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-tenant-provisioning-must-create-default-resources-automatically
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function provision(Organisation $organisation, string $adminUserId): Organisation {
		$this->assertProvisionable(organisation: $organisation);
		$slug = $organisation->getSlug() ?? 'org';

		try {
			$groupIds = $this->provisionGroups(slug: $slug, adminUserId: $adminUserId);
			$this->activateWithDefaultAccess(organisation: $organisation, groupIds: $groupIds, adminUserId: $adminUserId);
			$result = $this->organisationMapper->update($organisation);

			$this->logger->info('[TenantLifecycleService] Organisation provisioned and activated', ['uuid' => $organisation->getUuid(), 'slug' => $slug]);

			return $result;
		} catch (Exception $e) {
			$this->logger->error('[TenantLifecycleService] Provisioning failed', ['uuid' => $organisation->getUuid(), 'error' => $e->getMessage()]);
			throw $e;
		}
	}//end provision()

	/**
	 * Refuse to provision an organisation that is not provisioning or targets an unknown environment.
	 *
	 * @param Organisation $organisation The organisation to provision.
	 *
	 * @return void
	 *
	 * @throws Exception 409 when not provisioning, 422 on an unknown environment, 409 on a bad promotion order.
	 */
	private function assertProvisionable(Organisation $organisation): void {
		if (($organisation->getStatus() ?? self::STATUS_PROVISIONING) !== self::STATUS_PROVISIONING) {
			throw new Exception('Organisation must be in provisioning state to provision', Response::HTTP_CONFLICT);
		}

		// Validate that the organisation's target environment is a known OTAP stage.
		$env = $organisation->getEnvironment() ?? self::ENV_PRODUCTION;
		if ($this->isValidEnvironment(environment: $env) === false) {
			throw new Exception(sprintf("Unknown target environment '%s'.", $env), Response::HTTP_UNPROCESSABLE_ENTITY);
		}

		$this->assertPromotionOrder(sourceEnv: ($organisation->getEnvironment() ?? null), env: $env);
	}//end assertProvisionable()

	/**
	 * Refuse a promotion from a set source environment to a target that does not come after it.
	 *
	 * @param string|null $sourceEnv The source environment, if any.
	 * @param string      $env       The target environment.
	 *
	 * @return void
	 *
	 * @throws Exception 409 on a bad promotion order.
	 */
	private function assertPromotionOrder(?string $sourceEnv, string $env): void {
		// If a source environment is set, validate the promotion order.
		if ($sourceEnv !== null && $sourceEnv !== $env && $this->isValidPromotionOrder(sourceEnv: $sourceEnv, targetEnv: $env) === false) {
			throw new Exception(sprintf("Invalid OTAP promotion order from '%s' to '%s'.", $sourceEnv, $env), Response::HTTP_CONFLICT);
		}
	}//end assertPromotionOrder()

	/**
	 * Create the slug-prefixed admin and users groups when absent, and put the admin user in both.
	 *
	 * @param string $slug        The organisation slug.
	 * @param string $adminUserId The admin user id.
	 *
	 * @return array{admin: string, users: string} The group ids.
	 */
	private function provisionGroups(string $slug, string $adminUserId): array {
		// Create default groups prefixed with org slug.
		$groupIds = ['admin' => $slug . '-admin', 'users' => $slug . '-users'];
		foreach ($groupIds as $groupId) {
			if ($this->groupManager->groupExists($groupId) === false) {
				$this->groupManager->createGroup($groupId);
			}
		}

		$this->addAdminToGroups(groupIds: $groupIds, adminUserId: $adminUserId);

		return $groupIds;
	}//end provisionGroups()

	/**
	 * Add the admin user to the admin group and, when it resolves, the users group.
	 *
	 * @param array{admin: string, users: string} $groupIds    The group ids.
	 * @param string                              $adminUserId The admin user id.
	 *
	 * @return void
	 */
	private function addAdminToGroups(array $groupIds, string $adminUserId): void {
		// Add admin user to admin group.
		$adminGroup = $this->groupManager->get($groupIds['admin']);
		$usersGroup = $this->groupManager->get($groupIds['users']);
		if ($adminGroup === null) {
			return;
		}

		$user = $this->userManager->get($adminUserId);
		if ($user === null) {
			return;
		}

		$adminGroup->addUser($user);
		$usersGroup?->addUser($user);
	}//end addAdminToGroups()

	/**
	 * Set the groups, the default authorization and the admin member, and mark the organisation active.
	 *
	 * @param Organisation                        $organisation The organisation.
	 * @param array{admin: string, users: string} $groupIds     The group ids.
	 * @param string                              $adminUserId  The admin user id.
	 *
	 * @return void
	 */
	private function activateWithDefaultAccess(Organisation $organisation, array $groupIds, string $adminUserId): void {
		// Set organisation groups.
		$organisation->setGroups([$groupIds['admin'], $groupIds['users']]);

		// Set default authorization RBAC rules.
		$organisation->setAuthorization($this->defaultAuthorization(authorization: $organisation->getAuthorization(), groupIds: $groupIds));

		// Add admin user to organisation.
		$organisation->addUser($adminUserId);

		// Transition to active.
		$organisation->setStatus(self::STATUS_ACTIVE);
		$organisation->setProvisionedAt(new DateTime());
	}//end activateWithDefaultAccess()

	/**
	 * The authorization with every CRUD rule set to the default groups: admin and users, delete admin only.
	 *
	 * Only rules that are arrays and carry a `create` key are rewritten.
	 *
	 * @param array                               $authorization The current authorization.
	 * @param array{admin: string, users: string} $groupIds      The group ids.
	 *
	 * @return array The rewritten authorization.
	 */
	private function defaultAuthorization(array $authorization, array $groupIds): array {
		$both = [$groupIds['admin'], $groupIds['users']];
		foreach ($authorization as &$permissions) {
			if (is_array($permissions) === false || isset($permissions['create']) === false) {
				continue;
			}

			$permissions['create'] = $both;
			$permissions['read']   = $both;
			$permissions['update'] = $both;
			$permissions['delete'] = [$groupIds['admin']];
		}

		unset($permissions);

		return $authorization;
	}//end defaultAuthorization()

	/**
	 * Suspend an active organisation.
	 *
	 * @param Organisation $organisation The organisation to suspend
	 *
	 * @return Organisation The suspended organisation
	 *
	 * @throws Exception If transition is invalid
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function suspend(Organisation $organisation): Organisation {
		$currentStatus = $organisation->getStatus() ?? self::STATUS_ACTIVE;
		$this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_SUSPENDED);

		$organisation->setStatus(self::STATUS_SUSPENDED);
		$organisation->setSuspendedAt(new DateTime());

		$result = $this->organisationMapper->update($organisation);
		$this->auditor?->recordStatus(organisation: $result, before: $currentStatus);

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
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function reactivate(Organisation $organisation): Organisation {
		$currentStatus = $organisation->getStatus() ?? self::STATUS_ACTIVE;
		$this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_ACTIVE);

		$organisation->setStatus(self::STATUS_ACTIVE);
		$organisation->setSuspendedAt(null);

		$result = $this->organisationMapper->update($organisation);
		$this->auditor?->recordStatus(organisation: $result, before: $currentStatus);

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
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function deprovision(Organisation $organisation): Organisation {
		$currentStatus = $organisation->getStatus() ?? self::STATUS_ACTIVE;
		$this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_DEPROVISIONING);

		$organisation->setStatus(self::STATUS_DEPROVISIONING);
		$organisation->setDeprovisionedAt(new DateTime());

		$result = $this->organisationMapper->update($organisation);
		$this->auditor?->recordStatus(organisation: $result, before: $currentStatus);

		$this->logger->info(
			'[TenantLifecycleService] Organisation deprovisioning started',
			['uuid' => $organisation->getUuid()]
		);

		return $result;
	}//end deprovision()

	/**
	 * End an organisation's access and keep its data.
	 *
	 * Moves an active or suspended organisation to `retained`. Nothing is
	 * deleted and no deletion is scheduled: `deprovisionedAt`, which the purge
	 * job measures its retention window from, is left untouched. The moment
	 * the retention began is stamped on `retainedAt` instead, so the end of a
	 * retention period can be computed from the organisation itself.
	 *
	 * @param Organisation $organisation The organisation to retain
	 *
	 * @return Organisation The organisation in retained state
	 *
	 * @throws Exception If transition is invalid
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-terminated-organisation-must-be-able-to-keep-its-data-in-the-retained-state
	 */
	public function retain(Organisation $organisation): Organisation {
		$currentStatus = $organisation->getStatus() ?? self::STATUS_ACTIVE;
		$this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_RETAINED);

		$organisation->setStatus(self::STATUS_RETAINED);
		$organisation->setRetainedAt(new DateTime());

		$result = $this->organisationMapper->update($organisation);
		$this->auditor?->recordStatus(organisation: $result, before: $currentStatus);

		$this->logger->info(
			'[TenantLifecycleService] Organisation retained, access ended and data kept',
			['uuid' => $organisation->getUuid()]
		);

		return $result;
	}//end retain()

	/**
	 * Archive a deprovisioning organisation (called by background job).
	 *
	 * @param Organisation $organisation The organisation to archive
	 *
	 * @return Organisation The archived organisation
	 *
	 * @throws Exception If transition is invalid
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function archive(Organisation $organisation): Organisation {
		$currentStatus = $organisation->getStatus() ?? self::STATUS_DEPROVISIONING;
		$this->validateTransition(currentStatus: $currentStatus, targetStatus: self::STATUS_ARCHIVED);

		$organisation->setStatus(self::STATUS_ARCHIVED);

		$result = $this->organisationMapper->update($organisation);
		$this->auditor?->recordStatus(organisation: $result, before: $currentStatus);

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
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function isValidEnvironment(string $environment): bool {
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
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function isValidPromotionOrder(string $sourceEnv, string $targetEnv): bool {
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
	public function isValidStatus(string $status): bool {
		return isset(self::STATE_TRANSITIONS[$status]);
	}//end isValidStatus()
}//end class
