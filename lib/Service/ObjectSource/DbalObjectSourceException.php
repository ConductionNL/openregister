<?php

/**
 * DbalObjectSourceException — a read failure against an external database that
 * carries the HTTP status the caller should surface (502/503, never a bare 500).
 *
 * A DBAL connection failure (source temporarily unreachable) maps to 503; an
 * upstream query error maps to 502 (design D8). A missing driver extension does
 * NOT raise this — the provider degrades that case to an empty list instead.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use RuntimeException;
use Throwable;

/**
 * Read failure carrying a 502/503 HTTP status for the object-source dispatch.
 */
class DbalObjectSourceException extends RuntimeException
{

    /**
     * The HTTP status this failure should surface as (502 or 503).
     *
     * @var integer
     */
    private int $statusCode;

    /**
     * Constructor.
     *
     * @param string         $message    A non-sensitive message (never a secret).
     * @param int            $statusCode The HTTP status (502 upstream error / 503 unreachable).
     * @param Throwable|null $previous   The underlying DBAL exception, if any.
     *
     * @return void
     */
    public function __construct(string $message, int $statusCode=503, ?Throwable $previous=null)
    {
        parent::__construct(message: $message, code: 0, previous: $previous);
        $this->statusCode = $statusCode;
    }//end __construct()

    /**
     * The HTTP status this failure should surface as.
     *
     * @return int The status code (502 or 503).
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }//end getStatusCode()
}//end class
