<?php

/**
 * OAuth2Tokens — the secret half of an OAuth2 token set.
 *
 * Like {@see OAuth2TokenSet} it carries no `__toString()` and no
 * `jsonSerialize()`: the token values leave only through
 * {@see OAuth2TokenSet::toStoredJson()}.
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

use DateTimeImmutable;

/**
 * The access and refresh tokens of one token set, their type and their expiry.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
 */
final class OAuth2Tokens {

	/**
	 * Constructor.
	 *
	 * @param string                 $accessToken  The bearer token injected on every brokered call.
	 * @param string|null            $refreshToken The refresh token, or null when the provider issued none.
	 * @param string                 $tokenType    The token type the provider named (practically always `Bearer`).
	 * @param DateTimeImmutable|null $expiresAt    Absolute expiry, or null when the provider declared none.
	 *
	 * @return void
	 */
	public function __construct(
		#[\SensitiveParameter] public readonly string $accessToken,
		#[\SensitiveParameter] public readonly ?string $refreshToken,
		public readonly string $tokenType,
		public readonly ?DateTimeImmutable $expiresAt,
	) {
	}//end __construct()
}//end class
