<?php

/**
 * OpenRegister CustomValidationException
 *
 * This file contains the exception class for custom validation errors.
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

/**
 * Exception for storing custom validation errors
 *
 * Used for business logic validation errors that don't come from JSON schema
 * validation. Stores custom error messages keyed by field names or error codes.
 * Allows applications to provide detailed, context-specific validation feedback.
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
class CustomValidationException extends Exception
{

    /**
     * The validation errors array
     *
     * Associative array containing validation errors, typically keyed by
     * field names or error codes. Each value contains error message(s)
     * for that field or error type.
     *
     * @var array<string, string|array<string>> Validation errors array
     */
    private readonly array $errors;

    /**
     * Constructor for CustomValidationException
     *
     * Initializes exception with custom validation error message and
     * detailed error array. Calls parent constructor to set message.
     *
     * @param string                              $message The error message describing validation failure
     * @param array<string, string|array<string>> $errors  The validation errors array,
     *                                                     typically keyed by field
     *                                                     names
     *
     * @return void
     */
    public function __construct(string $message, array $errors)
    {
        // Store validation errors for detailed error reporting.
        $this->errors = $errors;

        // Call parent constructor to initialize base exception properties.
        parent::__construct($message);
    }//end __construct()

    /**
     * Retrieves the errors to display them
     *
     * Returns the validation errors array for display to users or API clients.
     * Errors are typically formatted as field => error message(s) pairs.
     *
     * @return array<string, string|array<string>> The errors array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }//end getErrors()
}//end class
