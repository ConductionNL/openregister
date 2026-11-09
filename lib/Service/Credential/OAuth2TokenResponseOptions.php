<?php

/**
 * OpenRegister Credential — OAuth2 Token Response Options
 *
 * How a token response becomes a token set: the scopes asked for, the set it replaces and the clock.
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

use DateTimeImmutable;

/**
 * The optional context a token response is read in.
 *
 * A first connection names the scopes it asked for; a refresh names the set it
 * replaces, whose refresh token, scopes and account carry over. Tests pin the
 * clock.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
 */
final class OAuth2TokenResponseOptions {
	/**
	 * Constructor.
	 *
	 * @param array<int, string>     $requestedScopes Scopes to record when the response names none.
	 * @param OAuth2TokenSet|null    $previous        The set being replaced, for the refresh token and account fallbacks.
	 * @param DateTimeImmutable|null $now             The clock, injectable for tests.
	 */
	public function __construct(
		public readonly array $requestedScopes=[],
		public readonly ?OAuth2TokenSet $previous=null,
		public readonly ?DateTimeImmutable $now=null,
	) {
	}//end __construct()
}//end class
