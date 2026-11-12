<?php

/**
 * OpenRegister Credential — OAuth2 Code Redemption
 *
 * One authorization-code exchange at a provider's token endpoint.
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
 * Redeem an authorization code: which provider, which grant, as which client,
 * and against which pinned host.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
 */
final class OAuth2CodeRedemption {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                               $provider        The catalogue provider entry.
	 * @param OAuth2CodeGrant                                    $grant           The code, PKCE verifier and callback.
	 * @param array{clientId: string, clientSecret: string|null} $client          The resolved client.
	 * @param string|null                                        $instanceBaseUrl The pinned host, when the provider has one.
	 */
	public function __construct(
		public readonly array $provider,
		public readonly OAuth2CodeGrant $grant,
		#[\SensitiveParameter]
		public readonly array $client,
		public readonly ?string $instanceBaseUrl,
	) {
	}//end __construct()
}//end class
