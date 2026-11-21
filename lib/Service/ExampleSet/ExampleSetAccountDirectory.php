<?php

/**
 * OpenRegister Example Set Account Directory
 *
 * Nextcloud's accounts, groups and group sub-admins, as an example set's accounts reach them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Nextcloud's accounts, groups and group sub-admins, as an example set's accounts reach them.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
final readonly class ExampleSetAccountDirectory {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserManager   $users     Nextcloud accounts.
	 * @param IGroupManager  $groups    Nextcloud groups.
	 * @param ISubAdmin|null $subAdmins Nextcloud group sub-admins.
	 *
	 * @return void
	 */
	public function __construct(
		public IUserManager $users,
		public IGroupManager $groups,
		public ?ISubAdmin $subAdmins=null,
	) {
	}//end __construct()
}//end class
