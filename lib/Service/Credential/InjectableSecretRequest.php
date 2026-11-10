<?php

/**
 * InjectableSecretRequest — an in-process app's request for the raw secret of an inject-only credential.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

/**
 * Which credential, which calling app, and the identities a sessionless caller asserts
 * (see {@see CredentialBrokerService::resolveInjectable()}).
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class InjectableSecretRequest {
	/**
	 * Constructor.
	 *
	 * @param string      $credentialId         The `credential` object UUID.
	 * @param string      $appId                The authenticated calling app id.
	 * @param string|null $actingUserId         Asserted user for sessionless in-process callers; ignored with a session.
	 * @param string|null $actingOrganisationId Asserted organisation for sessionless in-process callers; ignored with a session.
	 */
	public function __construct(
		public readonly string $credentialId,
		public readonly string $appId,
		public readonly ?string $actingUserId = null,
		public readonly ?string $actingOrganisationId = null,
	) {
	}//end __construct()
}//end class
