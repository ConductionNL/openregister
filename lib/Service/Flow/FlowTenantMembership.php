<?php

/**
 * Answers whether a user belongs to an organisation.
 *
 * Membership only. An administrator's access to an organisation is a right to
 * manage it, so it does not count here: a schedule runs with its identity's
 * rights in that tenant.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use Throwable;

/**
 * Fail-closed organisation membership, for a user with no session.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class FlowTenantMembership implements FlowTenantMembershipInterface {
	/**
	 * Constructor.
	 *
	 * @param OrganisationMapperInterface $organisations Reads organisations.
	 */
	public function __construct(
		private readonly OrganisationMapperInterface $organisations,
	) {
	}//end __construct()

	/**
	 * Whether a user is a member of an organisation.
	 *
	 * @param string $uid The user.
	 * @param string $organisation The organisation uuid.
	 *
	 * @return bool False too when the organisation cannot be read.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function isMember(string $uid, string $organisation): bool {
		try {
			return $this->organisations->findByUuid($organisation)->hasUser($uid);
		} catch (Throwable $e) {
			return false;
		}
	}//end isMember()
}//end class
