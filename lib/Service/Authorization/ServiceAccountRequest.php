<?php

/**
 * OpenRegister ServiceAccountRequest
 *
 * What an administrator names when a service account is provisioned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * The name, owning team, contact and purpose of a service account to provision.
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */
final class ServiceAccountRequest {
	/**
	 * Constructor.
	 *
	 * @param string $name         What the account is called; its user id is derived from it.
	 * @param string $ownerGroup   The Nextcloud group (the team) that owns the account.
	 * @param string $contactName  The person to contact about the account.
	 * @param string $contactEmail Where to reach that person.
	 * @param string $purpose      What the account is for.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $ownerGroup,
		public readonly string $contactName,
		public readonly string $contactEmail,
		public readonly string $purpose,
	) {
	}//end __construct()
}//end class
