<?php

/**
 * TenantLifecycleServiceInterface: the states, environments and transitions of TenantLifecycleService used outside Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\Organisation;

/**
 * The lifecycle of a tenant organisation: its states and OTAP environments, and the transitions the
 * organisation endpoints and the tenant jobs drive.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisation-entities-must-have-a-lifecycle-status-field-with-defined-state-transitions
 */
interface TenantLifecycleServiceInterface {
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
	 * Get valid transitions for a status.
	 *
	 * @param string $status Current status
	 *
	 * @return string[] Valid next states
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisation-entities-must-have-a-lifecycle-status-field-with-defined-state-transitions
	 */
	public function getValidTransitions(string $status): array;

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
	public function provision(Organisation $organisation, string $adminUserId): Organisation;

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
	public function suspend(Organisation $organisation): Organisation;

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
	public function reactivate(Organisation $organisation): Organisation;

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
	public function deprovision(Organisation $organisation): Organisation;

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
	public function retain(Organisation $organisation): Organisation;

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
	public function archive(Organisation $organisation): Organisation;
}//end interface
