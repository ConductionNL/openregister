<?php

/**
 * Auth Rate Limit Exceeded Exception
 *
 * Thrown by RateLimitMiddleware when an (identity + IP) pair has exceeded the
 * inbound-API brute-force threshold and is temporarily locked out (issue #1834).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCP\AppFramework\Http;

/**
 * Exception for an exceeded inbound-API authentication rate limit.
 *
 * @package OCA\OpenRegister\Middleware
 */
class AuthRateLimitExceededException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message      The error message
     * @param int    $lockoutUntil Unix timestamp the lockout expires at (0 if unknown)
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function __construct(
        string $message,
        private readonly int $lockoutUntil=0
    ) {
        parent::__construct(message: $message, code: Http::STATUS_TOO_MANY_REQUESTS);
    }//end __construct()

    /**
     * Get the Unix timestamp the lockout expires at.
     *
     * @return int The lockout expiry timestamp, or 0 when unknown
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function getLockoutUntil(): int
    {
        return $this->lockoutUntil;
    }//end getLockoutUntil()
}//end class
