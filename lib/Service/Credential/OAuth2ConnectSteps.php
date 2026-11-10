<?php

/**
 * The steps that turn an authorization code into a stored connection, and undo it.
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
final class OAuth2ConnectSteps {
	/**
	 * Bundle the services.
	 *
	 * @param OAuth2CodeExchange     $exchange Redeems the authorization code at the token endpoint.
	 * @param OAuth2ConnectionWriter $writer   Repairs the targeted credential in place or mints a new one.
	 * @param OAuth2UpstreamRevoker  $revoker  Revokes a connection at the provider, best effort.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md
	 */
	public function __construct(
		public readonly OAuth2CodeExchange $exchange,
		public readonly OAuth2ConnectionWriter $writer,
		public readonly OAuth2UpstreamRevoker $revoker,
	) {
	}//end __construct()
}//end class
