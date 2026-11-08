<?php

/**
 * OpenRegister credential domain
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

use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriverInterface;

/**
 * The domain services of the credential API.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class CredentialTokens {
	/**
	 * Bundle the services.
	 *
	 * @param CredentialAppTokenService $tokenService        Per-app signing-secret registry + token verify.
	 * @param OrganisationServiceInterface       $organisationService Organisation membership + admin authority resolution.
	 * @param SharePrincipalDeriverInterface     $shareDeriver        Validates share lists and derives the principal lists RBAC matches.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function __construct(
		public readonly CredentialAppTokenService $tokenService,
		public readonly OrganisationServiceInterface $organisationService,
		public readonly SharePrincipalDeriverInterface $shareDeriver,
	) {
	}//end __construct()
}//end class
