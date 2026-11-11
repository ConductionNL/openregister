<?php

/**
 * The details an organisation is created with.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

/**
 * The name, description and optional UUID of an organisation to create
 * ({@see \OCA\OpenRegister\Service\OrganisationService::createOrganisation()}).
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */
final class NewOrganisation {
	/**
	 * Constructor.
	 *
	 * @param string $name        The organisation name; the slug is derived from it.
	 * @param string $description The organisation description.
	 * @param string $uuid        A specific UUID to use, or '' to let the entity generate one.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description = '',
		public readonly string $uuid = '',
	) {
	}//end __construct()
}//end class
