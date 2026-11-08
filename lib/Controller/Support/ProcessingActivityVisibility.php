<?php

/**
 * Which processing activities a caller may see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Service\OrganisationServiceInterface;

/**
 * Tenant scoping of the processing register.
 *
 * Admins see everything. Non-admins see the activities bound to their active
 * organisation. Activities with no `organisationId` belong to no tenant and
 * are hidden from non-admins (fail-closed); they stay visible to admins for
 * maintenance.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class ProcessingActivityVisibility {
	/**
	 * Wrap the organisation service.
	 *
	 * @param OrganisationServiceInterface $organisationService Org-scoping helper.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function __construct(
		private readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()

	/**
	 * The organisations whose activities a caller may see.
	 *
	 * An admin is not scoped, and the organisation service is not asked. A
	 * non-admin gets the active organisation, as a list of at most one uuid;
	 * an empty list means deny-all.
	 *
	 * @param bool $isAdmin Whether the caller is an admin.
	 *
	 * @return array<int, string> The accessible organisation uuids.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function accessibleOrgs(bool $isAdmin): array {
		if ($isAdmin === true) {
			return [];
		}

		$uuid = (string)$this->organisationService->getActiveOrganisation()?->getUuid();
		if ($uuid === '') {
			return [];
		}

		return [$uuid];
	}//end accessibleOrgs()

	/**
	 * Whether the caller may see an activity given its organisation binding.
	 *
	 * @param Verwerkingsactiviteit $activity       Candidate activity.
	 * @param array<int, string>    $accessibleOrgs Accessible org UUIDs.
	 * @param bool                  $isAdmin        Whether caller is admin.
	 *
	 * @return bool True when the caller may see the activity.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function maySee(Verwerkingsactiviteit $activity, array $accessibleOrgs, bool $isAdmin): bool {
		if ($isAdmin === true) {
			return true;
		}

		$org = (string)$activity->getOrganisationId();
		if ($org === '') {
			return false;
		}

		return in_array($org, $accessibleOrgs, true);
	}//end maySee()

	/**
	 * Reduce a list of activities to the ones the caller may see.
	 *
	 * @param array<int, Verwerkingsactiviteit> $activities     Candidates.
	 * @param array<int, string>                $accessibleOrgs Accessible org UUIDs.
	 * @param bool                              $isAdmin        Whether caller is admin.
	 *
	 * @return array<int, Verwerkingsactiviteit> Visible activities.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function filter(array $activities, array $accessibleOrgs, bool $isAdmin): array {
		$visible = [];
		foreach ($activities as $activity) {
			if ($this->maySee(activity: $activity, accessibleOrgs: $accessibleOrgs, isAdmin: $isAdmin) === true) {
				$visible[] = $activity;
			}
		}

		return $visible;
	}//end filter()
}//end class
