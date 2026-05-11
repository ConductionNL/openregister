<?php

/**
 * OpenRegister AppendOnlyException
 *
 * This file contains the exception class for append-only schema violations.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @see https://github.com/ConductionNL/openregister/issues/1470
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when an update or delete is attempted on an append-only schema.
 *
 * Schemas with `appendOnly: true` permit object creation (INSERT) but prohibit
 * any subsequent mutation or deletion. This exception signals HTTP 405
 * Method Not Allowed to callers.
 *
 * Error code returned in the HTTP body: `SCHEMA_APPEND_ONLY`.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class AppendOnlyException extends Exception
{

    /**
     * Constructor for AppendOnlyException.
     *
     * @param string         $message  Human-readable description of the violation.
     * @param int            $code     HTTP-aligned error code (default: 405 Method Not Allowed).
     * @param Throwable|null $previous The previous exception in the chain.
     *
     * @return void
     */
    public function __construct(
        string $message='This schema is append-only; update and delete are not permitted.',
        int $code=405,
        ?Throwable $previous=null
    ) {
        parent::__construct(message: $message, code: $code, previous: $previous);
    }//end __construct()

}//end class
