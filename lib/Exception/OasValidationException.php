<?php

/**
 * OAS validation exception.
 *
 * Thrown by `OasService::createOas()` when invoked in strict mode and the
 * generated specification contains one or more validation errors. Carries
 * the full `OasValidationReport` so HTTP layers can translate it into a
 * 422 response with structured detail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use Throwable;

/**
 * Exception class carrying the full OAS validation report.
 */
class OasValidationException extends Exception
{
    /**
     * Constructor for OasValidationException.
     *
     * @param string              $message  Human-readable summary of the failure.
     * @param OasValidationReport $report   The full validation report.
     * @param int                 $code     HTTP-style code (default 422).
     * @param Throwable|null      $previous Optional underlying exception.
     */
    public function __construct(
        string $message,
        private readonly OasValidationReport $report,
        int $code=422,
        ?Throwable $previous=null,
    ) {
        parent::__construct(message: $message, code: $code, previous: $previous);

    }//end __construct()

    /**
     * Returns the validation report associated with this exception.
     *
     * @return OasValidationReport The validation report.
     */
    public function getReport(): OasValidationReport
    {
        return $this->report;

    }//end getReport()
}//end class
