<?php

/**
 * TokenResponseOptions — the fallbacks a token response is read against.
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
 * The optional inputs of {@see OAuth2TokenSet::fromResponse()}: scopes to record when the
 * response names none, the set being replaced (for the refresh token and account
 * fallbacks), and the clock `expires_in` is resolved against.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
 */
final readonly class TokenResponseOptions {
	/**
	 * Constructor.
	 *
	 * @param array<int, string>     $requestedScopes Scopes to record when the response names none.
	 * @param OAuth2TokenSet|null    $previous        The set being replaced.
	 * @param DateTimeImmutable|null $now             The clock, injectable for tests; the wall clock when null.
	 *
	 * @return void
	 */
	public function __construct(
		public array $requestedScopes=[],
		public ?OAuth2TokenSet $previous=null,
		public ?DateTimeImmutable $now=null,
	) {
	}//end __construct()
}//end class
