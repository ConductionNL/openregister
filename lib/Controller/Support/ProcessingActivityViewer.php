<?php

/**
 * Who is looking at processing activities: an admin, or the organisations a non-admin may see.
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

/**
 * The caller a processing activity is shown to.
 *
 * An admin sees every activity. A non-admin sees an activity only when it is
 * bound to one of the accessible organisations; an unbound activity is hidden
 * (fail-closed).
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class ProcessingActivityViewer {
	/**
	 * Constructor.
	 *
	 * @param bool               $isAdmin        Whether the caller is an admin.
	 * @param array<int, string> $accessibleOrgs The organisation uuids a non-admin may see.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function __construct(
		public readonly bool $isAdmin,
		public readonly array $accessibleOrgs,
	) {
	}//end __construct()

	/**
	 * Whether this caller may see the activity, given its organisation binding.
	 *
	 * @param Verwerkingsactiviteit $activity Candidate activity.
	 *
	 * @return bool True when the caller may see the activity.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function sees(Verwerkingsactiviteit $activity): bool {
		if ($this->isAdmin === true) {
			return true;
		}

		$org = (string)$activity->getOrganisationId();
		if ($org === '') {
			return false;
		}

		return in_array($org, $this->accessibleOrgs, true);
	}//end sees()
}//end class
