<?php

/**
 * OpenRegister bulk save session
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Who a bulk save runs for: the user, their organisation and their groups.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveSession {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession                 $userSession         User session for getting current user
	 * @param OrganisationServiceInterface $organisationService Service for organisation operations
	 * @param IGroupManager|null           $groupManager        Group manager for admin-bypass detection; also backs the
	 *                                                          wave-12 `_rbac` / `_validation` reserved-keys policy
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly OrganisationServiceInterface $organisationService,
		public readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()
}//end class
