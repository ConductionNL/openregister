<?php

/**
 * OpenRegister tenant records
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\TenantUsageMapperInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;

/**
 * The tenant lifecycle as the tenant jobs see it: the organisations, their usage rows and the lifecycle transitions.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class TenantRecords {
	/**
	 * Bundle the tenant records.
	 *
	 * @param OrganisationMapperInterface     $organisationMapper     The organisations (tenants).
	 * @param TenantUsageMapperInterface      $tenantUsageMapper      Their usage rows.
	 * @param TenantLifecycleServiceInterface $tenantLifecycleService Moves a tenant through its lifecycle.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly OrganisationMapperInterface $organisationMapper,
		public readonly TenantUsageMapperInterface $tenantUsageMapper,
		public readonly TenantLifecycleServiceInterface $tenantLifecycleService,
	) {
	}//end __construct()
}//end class
