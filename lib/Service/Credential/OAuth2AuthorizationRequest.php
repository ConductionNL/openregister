<?php

/**
 * OAuth2AuthorizationRequest — the per-request values of one authorization redirect.
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
 * Where the provider sends the person back, the signed state, and the PKCE challenge.
 *
 * Built by {@see OAuth2ConnectServiceInterface::authorizationRequest()}, so the
 * controller never names this class.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final readonly class OAuth2AuthorizationRequest {
	/**
	 * Constructor.
	 *
	 * @param string $redirectUri The callback the provider must redirect to.
	 * @param string $state       The signed state.
	 * @param string $challenge   The PKCE code challenge.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(
		public string $redirectUri,
		public string $state,
		public string $challenge,
	) {
	}//end __construct()
}//end class
