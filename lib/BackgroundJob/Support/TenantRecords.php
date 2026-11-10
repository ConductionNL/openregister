<?php

/**
 * OpenRegister tenant records
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\TenantUsageMapperInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;

/**
 * The tenant administration the scheduled tenant jobs work on: the organisations
 * that are tenants of this installation, their usage rows, and their lifecycle.
 *
 * Consumed by TenantDeprovisionJob, TenantPurgeJob and TenantUsageSyncJob (ADR-011 Rule 4).
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class TenantRecords {
	/**
	 * Bundle the tenant stores.
	 *
	 * @param OrganisationMapperInterface     $organisations The organisation (tenant) mapper.
	 * @param TenantUsageMapperInterface      $usage         The tenant usage mapper.
	 * @param TenantLifecycleServiceInterface $lifecycle     The tenant lifecycle service.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly OrganisationMapperInterface $organisations,
		public readonly TenantUsageMapperInterface $usage,
		public readonly TenantLifecycleServiceInterface $lifecycle,
	) {
	}//end __construct()
}//end class
