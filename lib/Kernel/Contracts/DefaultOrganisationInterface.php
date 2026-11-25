<?php

/**
 * DefaultOrganisationInterface: the instance's default organisation, created when it is missing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Lets a component outside Core reach the default organisation. Implemented by OrganisationService.
 */
interface DefaultOrganisationInterface {

	/**
	 * The default organisation, created when it does not exist yet.
	 *
	 * @return OrganisationInterface The default organisation.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function ensureDefaultOrganisation(): OrganisationInterface;
}//end interface
