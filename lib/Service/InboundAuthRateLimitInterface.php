<?php

/**
 * InboundAuthRateLimitInterface: what the inbound-API rate-limit middleware asks of SecurityService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IRequest;

/**
 * Brute-force protection of inbound-API authentication. Only the methods RateLimitMiddleware calls.
 */
interface InboundAuthRateLimitInterface {

	/**
	 * Check whether an inbound-API authentication attempt is allowed.
	 *
	 * @param string $identity  The authentication identity (username/token id) or '' if unknown
	 * @param string $ipAddress The trusted client IP address of the request
	 *
	 * @return array{allowed: bool, lockout_until?: int, reason?: string} Result
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function checkAuthRateLimit(string $identity, string $ipAddress): array;

	/**
	 * Record a failed inbound-API authentication attempt.
	 *
	 * @param string $identity  The authentication identity (username/token id) or '' if unknown
	 * @param string $ipAddress The trusted client IP address of the failed attempt
	 * @param string $reason    The reason for authentication failure
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function recordFailedAuthAttempt(string $identity, string $ipAddress, string $reason = 'inbound_auth_failed'): void;

	/**
	 * Clear the inbound-API auth rate-limit counters for an identity+IP.
	 *
	 * @param string $identity  The authentication identity (username/token id)
	 * @param string $ipAddress The trusted client IP address
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function recordSuccessfulAuth(string $identity, string $ipAddress): void;

	/**
	 * Resolve the trusted client IP address for rate-limiting purposes.
	 *
	 * @param IRequest $request The request object
	 *
	 * @return string The trusted client IP address
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-cors-policy-must-be-enforced-per-consumer-and-prevent-csrf
	 */
	public function getTrustedClientIpAddress(IRequest $request): string;
}//end interface
