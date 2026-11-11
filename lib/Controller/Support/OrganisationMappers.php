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

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\TenantUsageMapperInterface;

/**
 * The mappers and repositories of the organisation API.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationMappers {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper
	 * @param TenantUsageMapperInterface $tenantUsageMapper Usage mapper
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly OrganisationMapperInterface $organisationMapper,
		public readonly TenantUsageMapperInterface $tenantUsageMapper,
	) {
	}//end __construct()
}//end class
