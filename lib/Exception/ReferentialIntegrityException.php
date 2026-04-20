<?php

/**
 * OpenRegister Referential Integrity Exception
 *
 * Exception thrown when an object deletion is blocked by referential integrity constraints.
 * Contains the full DeletionAnalysis with blocker details for structured API error responses.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use OCA\OpenRegister\Dto\DeletionAnalysis;

/**
 * Exception thrown when deletion is blocked by referential integrity constraints.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 */
class ReferentialIntegrityException extends Exception
{

    /**
     * The deletion analysis containing blocker details.
     *
     * @var DeletionAnalysis
     */
    private readonly DeletionAnalysis $analysis;

    /**
     * Constructor for ReferentialIntegrityException.
     *
     * @param DeletionAnalysis $analysis The deletion analysis with blocker information.
     * @param int              $code     The error code.
     * @param Exception|null   $previous The previous exception.
     */
    public function __construct(DeletionAnalysis $analysis, int $code=0, ?Exception $previous=null)
    {
        $blockerCount = count($analysis->blockers);
        $message      = "Cannot delete object: {$blockerCount} dependent object(s) block deletion";

        parent::__construct(message: $message, code: $code, previous: $previous);
        $this->analysis = $analysis;
    }//end __construct()

    /**
     * Get the deletion analysis.
     *
     * @return DeletionAnalysis The analysis containing blocker and target details.
     */
    public function getAnalysis(): DeletionAnalysis
    {
        return $this->analysis;
    }//end getAnalysis()

    /**
     * Get a structured error response body suitable for JSON API responses.
     *
     * @return array The structured error response with error code, message, and blockers.
     */
    public function toResponseBody(): array
    {
        return [
            'error'    => 'DELETION_BLOCKED',
            'message'  => $this->getMessage(),
            'blockers' => $this->analysis->blockers,
        ];
    }//end toResponseBody()
}//end class
