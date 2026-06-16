<?php

/**
 * OpenRegister AppHost — Health Result
 *
 * Aggregate result of executing an app's health checks: the overall status,
 * the per-check map, and the resolved HTTP status code under the active
 * `statusCodePolicy`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Observability
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

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Health execution outcome.
 *
 * @psalm-immutable
 */
final class HealthResult
{
    /**
     * Constructor.
     *
     * @param string                $status         ok|degraded|error.
     * @param array<string, string> $checks         Map of check id => "ok" | "failed: ...".
     * @param int                   $httpStatusCode Resolved HTTP status code.
     */
    public function __construct(
        public readonly string $status,
        public readonly array $checks,
        public readonly int $httpStatusCode
    ) {
    }//end __construct()
}//end class
