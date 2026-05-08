<?php

/**
 * Tenant Quota Exceeded Exception
 *
 * Thrown when an organisation exceeds its request or bandwidth quota.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;

/**
 * Exception for quota limit exceeded.
 *
 * @package OCA\OpenRegister\Middleware
 */
class TenantQuotaExceededException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message    Error message
     * @param int    $quota      The quota limit
     * @param string $resetAt    ISO 8601 timestamp when quota resets
     * @param int    $retryAfter Seconds until quota reset
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-17
     */
    public function __construct(
        string $message,
        private readonly int $quota,
        private readonly string $resetAt,
        private readonly int $retryAfter
    ) {
        parent::__construct(message: $message, code: 429);
    }//end __construct()

    /**
     * Get the quota limit.
     *
     * @return int The quota
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-17
     */
    public function getQuota(): int
    {
        return $this->quota;
    }//end getQuota()

    /**
     * Get the reset timestamp.
     *
     * @return string ISO 8601 timestamp
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-17
     */
    public function getResetAt(): string
    {
        return $this->resetAt;
    }//end getResetAt()

    /**
     * Get the retry-after seconds.
     *
     * @return int Seconds until reset
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-17
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }//end getRetryAfter()
}//end class
