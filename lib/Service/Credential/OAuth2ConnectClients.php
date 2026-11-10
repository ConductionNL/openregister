<?php

/**
 * Where an OAuth2 connect finds its provider and its client.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * A dependency bundle of {@see OAuth2ConnectService}.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md
 */
final class OAuth2ConnectClients {
	/**
	 * Bundle the services.
	 *
	 * @param ProviderCatalogue    $catalogue       Declares every provider's endpoints and grants.
	 * @param OAuth2ClientResolver $clients         Resolves the client id and secret for the exchange.
	 * @param OAuth2InstanceClient $instanceClients Registers or reuses a per-instance OAuth2 application.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md
	 */
	public function __construct(
		public readonly ProviderCatalogue $catalogue,
		public readonly OAuth2ClientResolver $clients,
		public readonly OAuth2InstanceClient $instanceClients,
	) {
	}//end __construct()
}//end class
