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

use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\IAppConfig;

/**
 * The multi-tenancy and RBAC services the view mapper checks every read and write against.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class ViewMapperAccess {
	/**
	 * Bundle the services.
	 *
	 * @param TenancyServices $tenancy   The organisation mapper, user session and group manager for multi-tenancy and RBAC.
	 * @param IAppConfig      $appConfig App configuration for multitenancy settings.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function __construct(
		public readonly TenancyServices $tenancy,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
