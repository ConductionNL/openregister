<?php

/**
 * DbalWriteException — a write failure against an external database carrying
 * the HTTP status and a SANITIZED, client-safe message.
 *
 * Constraint violations raised by the external database map onto 4xx statuses
 * (unique/foreign-key → 409, not-null/check/data errors → 422); the message
 * names only the constraint CLASS — never SQL fragments, table internals, DSNs
 * or credentials. The underlying DBAL exception travels as `previous` so the
 * middleware can log it server-side.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use RuntimeException;
use Throwable;

/**
 * Sanitized, status-carrying write failure against an external database.
 */
class DbalWriteException extends RuntimeException
{

    /**
     * The HTTP status this failure should surface as.
     *
     * @var integer
     */
    private int $statusCode;

    /**
     * Constructor.
     *
     * @param string         $message    A sanitized, client-safe message (never a secret or SQL).
     * @param int            $statusCode The HTTP status (400/409/422/502/503).
     * @param Throwable|null $previous   The underlying DBAL exception, if any.
     *
     * @return void
     */
    public function __construct(string $message, int $statusCode=422, ?Throwable $previous=null)
    {
        parent::__construct(message: $message, code: 0, previous: $previous);
        $this->statusCode = $statusCode;
    }//end __construct()

    /**
     * The HTTP status this failure should surface as.
     *
     * @return int The status code.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }//end getStatusCode()

    /**
     * Translate a driver SQLSTATE into a sanitized write exception.
     *
     * @param string         $sqlState The five-character SQLSTATE (empty when unknown).
     * @param Throwable|null $previous The underlying DBAL exception.
     *
     * @return self The mapped exception.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public static function fromSqlState(string $sqlState, ?Throwable $previous=null): self
    {
        switch ($sqlState) {
            case '23505':
                return new self('A unique constraint on the external table was violated.', 409, $previous);
            case '23503':
                return new self('A foreign-key constraint on the external table was violated.', 409, $previous);
            case '23502':
                return new self('A required (not-null) column on the external table was not provided.', 422, $previous);
            case '23514':
                return new self('A check constraint on the external table was violated.', 422, $previous);
            default:
                if (str_starts_with($sqlState, '22') === true) {
                    return new self('A value did not match the external column type.', 422, $previous);
                }
                return new self('The external database rejected the write.', 502, $previous);
        }//end switch
    }//end fromSqlState()
}//end class
