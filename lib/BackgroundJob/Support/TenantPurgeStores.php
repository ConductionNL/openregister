<?php

/**
 * OpenRegister tenant purge stores
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
use OCA\OpenRegister\Db\TenantUsageMapper;

/**
 * The organisations the tenant purge walks and the usage rows it deletes with them.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final readonly class TenantPurgeStores {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper.
	 * @param TenantUsageMapper           $tenantUsageMapper  Usage mapper.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public OrganisationMapperInterface $organisationMapper,
		public TenantUsageMapper $tenantUsageMapper,
	) {
	}//end __construct()
}//end class
