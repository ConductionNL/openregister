<?php

/**
 * OpenRegister Credential — OAuth2 Code Grant
 *
 * What the callback hands the token endpoint: the code, the PKCE verifier and the callback URI.
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
 * The authorization code a provider returned, with the PKCE verifier held
 * since the start and the callback the code was issued against.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
 */
final class OAuth2CodeGrant {
	/**
	 * Constructor.
	 *
	 * @param string $code        The authorization code.
	 * @param string $verifier    The PKCE code verifier.
	 * @param string $redirectUri The callback the code was issued against.
	 */
	public function __construct(
		#[\SensitiveParameter]
		public readonly string $code,
		#[\SensitiveParameter]
		public readonly string $verifier,
		public readonly string $redirectUri,
	) {
	}//end __construct()
}//end class
