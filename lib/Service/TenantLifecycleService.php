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
class TenantLifecycleService implements TenantLifecycleServiceInterface {
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
	 * Constructor
	 *
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper
	 * @param TenantDirectory $directory Group and user managers for the tenant groups and admin user.
	 * @param LoggerInterface $logger Logger
	 * @param OrganisationLifecycleAuditor|null $auditor Writes each status change to the audit trail. Null only in legacy fixtures.
	 */
	public function __construct(
		private readonly OrganisationMapperInterface $organisationMapper,
		private readonly TenantDirectory $directory,
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
			$this->activateWithDefaultAccess(organisation: $organisation, groupIds: $groupIds);
			$organisation->addUser($adminUserId);
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
			if ($this->directory->groupManager->groupExists($groupId) === false) {
				$this->directory->groupManager->createGroup($groupId);
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
		$adminGroup = $this->directory->groupManager->get($groupIds['admin']);
		$usersGroup = $this->directory->groupManager->get($groupIds['users']);
		if ($adminGroup === null) {
			return;
		}

		$user = $this->directory->userManager->get($adminUserId);
		if ($user === null) {
			return;
		}

		$adminGroup->addUser($user);
		$usersGroup?->addUser($user);
	}//end addAdminToGroups()

	/**
	 * Set the groups and the default authorization, and mark the organisation active.
	 *
	 * The caller adds the admin member.
	 *
	 * @param Organisation                        $organisation The organisation.
	 * @param array{admin: string, users: string} $groupIds     The group ids.
	 *
	 * @return void
	 */
	private function activateWithDefaultAccess(Organisation $organisation, array $groupIds): void {
		// Set organisation groups.
		$organisation->setGroups([$groupIds['admin'], $groupIds['users']]);

		// Set default authorization RBAC rules.
		$organisation->setAuthorization($this->defaultAuthorization(authorization: $organisation->getAuthorization(), groupIds: $groupIds));

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
