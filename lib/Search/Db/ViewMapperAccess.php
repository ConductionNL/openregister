<?php

/**
 * OpenRegister view mapper access
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Search\Db
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

namespace OCA\OpenRegister\Search\Db;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The multi-tenancy and RBAC services the view mapper checks every read and write against.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class ViewMapperAccess {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper for multi-tenancy.
	 * @param IAppConfig                  $appConfig          App configuration for multitenancy settings.
	 * @param IUserSession                $userSession        User session for RBAC.
	 * @param IGroupManager               $groupManager       Group manager for RBAC.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function __construct(
		public readonly OrganisationMapperInterface $organisationMapper,
		public readonly IAppConfig $appConfig,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
