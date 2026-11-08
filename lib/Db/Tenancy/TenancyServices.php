<?php

/**
 * TenancyServices: who the caller is and which organisation they act in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Tenancy;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The three services MultiTenancyTrait reads the caller's tenancy from.
 *
 * A mapper unpacks them into its own properties, so the trait's checks run
 * exactly as they did when each service was a constructor parameter.
 */
final readonly class TenancyServices {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationMapperInterface $organisationMapper The organisation mapper (membership, active organisation).
	 * @param IUserSession                $userSession        The session the caller is read from.
	 * @param IGroupManager               $groupManager       The group manager (admin check, group ids).
	 */
	public function __construct(
		public OrganisationMapperInterface $organisationMapper,
		public IUserSession $userSession,
		public IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
