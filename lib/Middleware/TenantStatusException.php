<?php

/**
 * Tenant Status Exception
 *
 * Thrown when an organisation is in a non-active state that prevents API access.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;

/**
 * Exception for non-active organisation status.
 *
 * @package OCA\OpenRegister\Middleware
 */
class TenantStatusException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message The error message
     * @param string $status  The organisation status
     * @param int    $code    HTTP status code
     *
     * @spec openspec/specs/tenant-quotas/spec.md
     */
    public function __construct(
        string $message,
        private readonly string $status,
        int $code=403
    ) {
        parent::__construct(message: $message, code: $code);
    }//end __construct()

    /**
     * Get the organisation status.
     *
     * @return string The status
     *
     * @spec openspec/specs/tenant-quotas/spec.md
     */
    public function getStatus(): string
    {
        return $this->status;
    }//end getStatus()
}//end class
