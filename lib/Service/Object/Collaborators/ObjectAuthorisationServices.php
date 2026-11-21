<?php

/**
 * OpenRegister ObjectService authorisation services: permission checks and organisations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;

/**
 * ObjectService authorisation services: permission checks and organisations.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectAuthorisationServices {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionHandlerInterface   $permissionHandler   Handler for permission checks.
	 * @param OrganisationServiceInterface $organisationService Service for organisation operations.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly PermissionHandlerInterface $permissionHandler,
		public readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()
}//end class
