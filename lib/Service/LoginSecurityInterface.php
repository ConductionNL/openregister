<?php

/**
 * LoginSecurityInterface: what the user API asks of SecurityService.
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
 * Login rate limiting, credential validation and input sanitising for the user API. Only the methods UserController calls.
 */
interface LoginSecurityInterface extends SecurityHeadersInterface {

	/**
	 * Check if login attempt is allowed based on rate limiting rules
	 *
	 * @param string $username  The username attempting to login
	 * @param string $ipAddress The IP address of the request
	 *
	 * @return array Result with 'allowed' boolean and optional 'delay' or 'lockout_until'
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function checkLoginRateLimit(string $username, string $ipAddress): array;

	/**
	 * Record a failed login attempt
	 *
	 * @param FailedAttempt $attempt The username that failed authentication, the IP address of the failed
	 *                               attempt and the reason for the failure (e.g. `invalid_credentials`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function recordFailedLoginAttempt(FailedAttempt $attempt): void;

	/**
	 * Record a successful login attempt
	 *
	 * @param string $username  The username that successfully authenticated
	 * @param string $ipAddress The IP address of the successful attempt
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function recordSuccessfulLogin(string $username, string $ipAddress): void;

	/**
	 * Sanitize input data to prevent XSS and injection attacks
	 *
	 * @param mixed $input     The input to sanitize
	 * @param int   $maxLength Maximum allowed length for strings
	 *
	 * @return mixed Sanitized input
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-input-sanitization-must-prevent-xss-and-injection-attacks
	 */
	public function sanitizeInput(mixed $input, int $maxLength = 255): mixed;

	/**
	 * Validate and sanitize login credentials
	 *
	 * @param array $credentials The login credentials to validate
	 *
	 * @return array Validated and sanitized credentials or error information
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-input-sanitization-must-prevent-xss-and-injection-attacks
	 */
	public function validateLoginCredentials(array $credentials): array;

	/**
	 * Get client IP address from request
	 *
	 * @param IRequest $request The request object
	 *
	 * @return string The client IP address
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-cors-policy-must-be-enforced-per-consumer-and-prevent-csrf
	 */
	public function getClientIpAddress(IRequest $request): string;
}//end interface
