<?php

/**
 * OpenRegister ownership sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAppConfig;

/**
 * Where an ownership change resolves its principals and its objects.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnershipSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param PrincipalDirectory $directory Resolves a named new owner, their display name, and groups.
	 * @param ObjectLookup       $lookup    Resolves objects, their children, and a child's register and schema.
	 * @param IAppConfig|null    $appConfig Holds the configured system identity.
	 */
	public function __construct(
		public readonly PrincipalDirectory $directory,
		public readonly ObjectLookup $lookup,
		public readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * The identity that owns records written by the system, as OrganisationService names it.
	 *
	 * @return string The configured system identity, else the default one.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function systemOwner(): string {
		$configured = '';
		if ($this->appConfig !== null) {
			$configured = $this->appConfig->getValueString('openregister', OrganisationService::CONFIG_SYSTEM_USER_ID, '');
		}

		if ($configured !== '') {
			return $configured;
		}

		return OrganisationService::SYSTEM_USER_ID_DEFAULT;
	}//end systemOwner()
}//end class
