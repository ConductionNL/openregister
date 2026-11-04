<?php

/**
 * OpenRegister organisation mappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;

/**
 * The mappers and repositories of the organisation API.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationMappers {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationMapper $organisationMapper Organisation mapper
	 * @param TenantUsageMapper  $tenantUsageMapper  Usage mapper
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly OrganisationMapper $organisationMapper,
		public readonly TenantUsageMapper $tenantUsageMapper,
	) {
	}//end __construct()
}//end class
