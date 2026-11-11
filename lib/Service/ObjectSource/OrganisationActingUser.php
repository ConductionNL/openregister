<?php

/**
 * Who is acting on the organisation projection, and whether they are an instance admin.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The acting user's session and the group manager the admin check asks.
 *
 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-the-projection-is-not-an-enumeration-oracle-req-orp-103
 */
final readonly class OrganisationActingUser {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession  $userSession  The acting user's session.
	 * @param IGroupManager $groupManager Group manager, for the admin check.
	 */
	public function __construct(
		public IUserSession $userSession,
		public IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
