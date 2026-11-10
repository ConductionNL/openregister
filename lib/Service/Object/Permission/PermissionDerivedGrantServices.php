<?php

/**
 * OpenRegister permission handler derived grant services: identity-claim and API-token grants
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Permission
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

namespace OCA\OpenRegister\Service\Object\Permission;

use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;

/**
 * Permission handler derived grant services: identity-claim and API-token grants.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionDerivedGrantServices {
	/**
	 * Bundle the services.
	 *
	 * @param ?DerivedGrantStore    $derivedGrantStore    Access derived from identity claims; absent means none.
	 * @param ?DerivedGrantResolver $derivedGrantResolver Reads a derived grant in one area.
	 * @param ?TokenGrantSource     $tokenGrantSource     Reads the grant the request's API token carries.
	 * @param ?TokenGrantNarrower   $tokenGrantNarrower   Narrows the block to that token's grant.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ?DerivedGrantStore $derivedGrantStore = null,
		public readonly ?DerivedGrantResolver $derivedGrantResolver = null,
		public readonly ?TokenGrantSource $tokenGrantSource = null,
		public readonly ?TokenGrantNarrower $tokenGrantNarrower = null,
	) {
	}//end __construct()
}//end class
