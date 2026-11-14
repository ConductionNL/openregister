<?php

/**
 * A granted token called more often than the rate limit on its grant allows.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * The refusal of a call over a token's own limit, naming the limit.
 *
 * An AuthenticationException, so every caller that already refuses a failed
 * credential refuses this call too; `getStatusCode()` and the details let a
 * caller that knows about it answer 429 with the limit and the reset time.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#scenario-one-runaway-integration-does-not-take-the-balie-down
 */
class TokenRateLimitExceededException extends AuthenticationException {

	/**
	 * Constructor.
	 *
	 * @param int $limit      The grant's calls per window.
	 * @param int $window     The window, in seconds.
	 * @param int $retryAfter Seconds until the window resets.
	 */
	public function __construct(int $limit, int $window, int $retryAfter) {
		parent::__construct(
			message: sprintf('Rate limit exceeded: this token may make %d calls per %d seconds', $limit, $window),
			details: [
				'reason'        => 'token-rate-limit',
				'limit'         => $limit,
				'windowSeconds' => $window,
				'retryAfter'    => max(0, $retryAfter),
			]
		);
	}//end __construct()

	/**
	 * The HTTP status a caller should answer with.
	 *
	 * @return int 429.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#scenario-one-runaway-integration-does-not-take-the-balie-down
	 */
	public function getStatusCode(): int {
		return 429;
	}//end getStatusCode()
}//end class
