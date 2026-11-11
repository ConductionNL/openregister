<?php

/**
 * OAuth2Issuance — the non-token half of an OAuth2 token set.
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
 * What one token set was issued for: the granted scopes, the account, the raw
 * response and the moment it was issued or rotated.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
 */
final class OAuth2Issuance {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>    $scopes     The scopes the provider actually granted.
	 * @param array<string, string> $account    Non-secret account identity (`id`, `handle`, `displayName`).
	 * @param array<string, mixed>  $raw        The raw token response, kept for provider-specific fields.
	 * @param DateTimeImmutable     $obtainedAt When this set was issued or rotated.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $scopes,
		public readonly array $account,
		#[\SensitiveParameter] public readonly array $raw,
		public readonly DateTimeImmutable $obtainedAt,
	) {
	}//end __construct()
}//end class
