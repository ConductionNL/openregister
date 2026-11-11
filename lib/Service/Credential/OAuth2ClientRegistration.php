<?php

/**
 * OpenRegister Credential — OAuth2 Client Registration
 *
 * Make sure a connect flow has an OAuth2 client at a per-instance provider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Credential
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

/**
 * One client registration request: the provider, the claims assembled so far
 * and the callback to register.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
 */
final class OAuth2ClientRegistration {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $provider    The catalogue provider entry.
	 * @param array<string, mixed> $claims      The claims assembled so far.
	 * @param string               $redirectUri The callback to register.
	 */
	public function __construct(
		public readonly array $provider,
		public readonly array $claims,
		public readonly string $redirectUri,
	) {
	}//end __construct()

	/**
	 * The claims, read through the flow accessor.
	 *
	 * @return OAuth2FlowClaims The flow.
	 */
	public function flow(): OAuth2FlowClaims {
		return new OAuth2FlowClaims(claims: $this->claims);
	}//end flow()

	/**
	 * The scopes the client is registered for.
	 *
	 * @return array<int, string> The scopes.
	 */
	public function scopes(): array {
		return $this->flow()->scopes(provider: $this->provider);
	}//end scopes()
}//end class
