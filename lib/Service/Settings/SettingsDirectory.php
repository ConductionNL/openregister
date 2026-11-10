<?php

/**
 * OpenRegister settings directory sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
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

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Where the settings page lists the available groups, users and organisations from.
 *
 * @spec openspec/specs/settings-management/spec.md
 */
final class SettingsDirectory {
	/**
	 * Bundle the directory sources.
	 *
	 * @param IGroupManager               $groupManager       Group manager.
	 * @param IUserManager                $userManager        User manager.
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper.
	 *
	 * @spec openspec/specs/settings-management/spec.md
	 */
	public function __construct(
		public readonly IGroupManager $groupManager,
		public readonly IUserManager $userManager,
		public readonly OrganisationMapperInterface $organisationMapper,
	) {
	}//end __construct()
}//end class
