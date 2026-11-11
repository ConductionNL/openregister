<?php

/**
 * OpenRegister organisation domain
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

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\OrganisationUsageServiceInterface;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditorInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;

/**
 * The domain services of the organisation API.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationDomain {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationServiceInterface      $organisationService Organisation service
	 * @param TenantLifecycleServiceInterface   $tenantLifecycle     Lifecycle service
	 * @param OrganisationUsageServiceInterface $usageService        Measures an organisation's usage
	 * @param ?GrantChangeAuditorInterface      $grantAuditor        Records a change of who holds the special right
	 *                                                               `grant_manage`. Null only in legacy fixtures.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly OrganisationServiceInterface $organisationService,
		public readonly TenantLifecycleServiceInterface $tenantLifecycle,
		public readonly OrganisationUsageServiceInterface $usageService,
		public readonly ?GrantChangeAuditorInterface $grantAuditor = null,
	) {
	}//end __construct()
}//end class
