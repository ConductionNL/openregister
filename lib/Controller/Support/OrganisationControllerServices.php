<?php

/**
 * OpenRegister organisation controller services
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

/**
 * The service bundles of the organisation API.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession    $session  The session bundle.
	 * @param OrganisationMappers  $mappers  The mappers bundle.
	 * @param OrganisationDomain   $domain   The domain bundle.
	 * @param OrganisationAuditing $auditing The auditing bundle.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly OrganisationMappers $mappers,
		public readonly OrganisationDomain $domain,
		public readonly OrganisationAuditing $auditing,
	) {
	}//end __construct()
}//end class
