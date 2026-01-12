<?php

/**
 * OpenRegister ValidationException
 *
 * This file contains the exception class for validation errors.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use Opis\JsonSchema\Errors\ValidationError;
use Throwable;

/**
 * Exception class for validation errors
 *
 * Thrown when data validation fails against JSON schema or business rules.
 * Contains detailed validation error information from Opis JSON Schema validator.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class ValidationException extends Exception
{

    /**
     * The validation errors from JSON schema validator
     *
     * Contains detailed error information including field paths, error types,
     * and validation failure reasons. Null if validation errors are not available.
     *
     * @var ValidationError|null Validation errors object or null
     */
    private readonly ?ValidationError $errors;

    /**
     * Constructor for ValidationException
     *
     * Initializes exception with validation error message and optional detailed
     * validation errors from JSON schema validator. Calls parent constructor
     * to set message, code, and previous exception.
     *
     * @param string               $message  The error message describing validation failure
     * @param int                  $code     The error code (default: 0)
     * @param Throwable|null       $previous The previous exception that caused this one
     * @param ValidationError|null $errors   The detailed validation errors from Opis validator
     *
     * @return void
     */
    public function __construct(
        string $message,
        int $code=0,
        ?Throwable $previous=null,
        ?ValidationError $errors=null
    ) {
        // Store validation errors for detailed error reporting.
        $this->errors = $errors;

        // Call parent constructor to initialize base exception properties.
        parent::__construct($message, $code, $previous);
    }//end __construct()

    /**
     * Returns the validation errors
     *
     * Returns detailed validation error information from JSON schema validator.
     * Contains field paths, error types, and validation failure reasons.
     * Returns null if validation errors were not provided.
     *
     * @return ValidationError|null The validation errors object or null if not available
     */
    public function getErrors(): ?ValidationError
    {
        return $this->errors;
    }//end getErrors()
}//end class
