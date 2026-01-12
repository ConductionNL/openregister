<?php

/**
 * OpenRegister Database Constraint Exception
 *
 * This file contains the custom exception class for handling database constraint violations
 * with user-friendly error messages.
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
 * Exception class for database constraint violations
 *
 * This class provides user-friendly error messages for database constraint violations
 * and can determine the type of constraint violation from the database error message.
 * Extends the base Exception class to add HTTP status code support for API responses.
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
class DatabaseConstraintException extends Exception
{

    /**
     * HTTP status code for the error response
     *
     * Used to return appropriate HTTP status codes in API responses.
     * Defaults to 409 Conflict for constraint violations.
     *
     * @var integer HTTP status code (typically 409 for conflicts)
     */
    private readonly int $httpStatusCode;

    /**
     * Constructor
     *
     * @param string         $message    The user-friendly error message
     * @param int            $code       The error code
     * @param int            $httpStatus The HTTP status code (default: 409 Conflict)
     * @param Exception|null $previous   The previous exception
     */
    public function __construct(string $message, int $code=0, int $httpStatus=409, ?Exception $previous=null)
    {
        parent::__construct($message, $code, $previous);
        $this->httpStatusCode = $httpStatus;
    }//end __construct()

    /**
     * Get the HTTP status code for this exception
     *
     * Returns the HTTP status code associated with this exception.
     * Used by API controllers to return appropriate HTTP responses.
     *
     * @return int The HTTP status code (typically 409 for constraint violations)
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }//end getHttpStatusCode()

    /**
     * Create a DatabaseConstraintException from a database exception
     *
     * Factory method that converts a raw database exception into a user-friendly
     * DatabaseConstraintException. Parses the database error message to determine
     * the type of constraint violation and generates appropriate user message.
     *
     * @param Exception $dbException The original database exception
     * @param string    $entityType  The type of entity (e.g., 'schema', 'register', 'object')
     *                               Used to customize error messages (default: 'item')
     *
     * @return DatabaseConstraintException The user-friendly exception with parsed message
     */
    public static function fromDatabaseException(
        Exception $dbException,
        string $entityType='item'
    ): DatabaseConstraintException {
        // Extract original database error message.
        $message = $dbException->getMessage();

        // Parse database error message to generate user-friendly message.
        $userMessage = self::parseConstraintError(dbMessage: $message, entityType: $entityType);

        // Create new exception with user-friendly message, preserving original exception.
        // HTTP status code defaults to 409 Conflict for constraint violations.
        return new self($userMessage, (int) $dbException->getCode(), 409, $dbException);
    }//end fromDatabaseException()

    /**
     * Parse database constraint error messages and return user-friendly messages
     *
     * Analyzes database error messages to identify constraint violation types
     * and generates user-friendly error messages. Handles various constraint
     * types: unique, foreign key, NOT NULL, CHECK, and data length violations.
     *
     * @param string $dbMessage  The database error message from the exception
     * @param string $entityType The type of entity being saved (used in error messages)
     *
     * @return string User-friendly error message explaining the constraint violation
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Constraint parsing requires many conditional error type checks
     * @SuppressWarnings(PHPMD.NPathComplexity)      Constraint parsing requires many conditional error type checks
     */
    private static function parseConstraintError(string $dbMessage, string $entityType): string
    {
        // Handle unique constraint violations (duplicate key errors).
        // MySQL/MariaDB format: "Duplicate entry 'value' for key 'constraint_name'".
        if (str_contains($dbMessage, 'Duplicate entry') === true && str_contains($dbMessage, 'for key') === true) {
            // Check for specific constraint names to provide more detailed messages.
            // Schema slug uniqueness violation.
            if (str_contains($dbMessage, 'schemas_organisation_slug_unique') === true) {
                $msg = 'A schema with this slug already exists in your organization. ';
                return $msg.'Please choose a different slug or title.';
            }

            // Register slug uniqueness violation.
            if (str_contains($dbMessage, 'registers_organisation_slug_unique') === true) {
                $msg = 'A register with this slug already exists in your organization. ';
                return $msg.'Please choose a different slug or title.';
            }

            // Generic unique constraint violation.
            if (str_contains($dbMessage, 'unique') === true) {
                return "This {$entityType} already exists. Please check your input and try again.";
            }

            // Fallback for duplicate entry errors without specific constraint name.
            return "A {$entityType} with these details already exists. Please modify your input and try again.";
        }//end if

        // Handle foreign key constraint violations.
        // Occurs when referencing non-existent records in related tables.
        $isForeignKeyViol = str_contains($dbMessage, 'foreign key constraint') === true
            || str_contains($dbMessage, 'FOREIGN KEY') === true;
        if ($isForeignKeyViol === true) {
            $msg = "This {$entityType} cannot be saved because it references data that doesn't exist. ";
            return $msg.'Please check your configuration and try again.';
        }

        // Handle NOT NULL constraint violations.
        // Occurs when required fields are missing or null.
        if (str_contains($dbMessage, 'cannot be null') === true || str_contains($dbMessage, 'NOT NULL') === true) {
            return "Required information is missing. Please fill in all required fields and try again.";
        }

        // Handle CHECK constraint violations.
        // Occurs when data doesn't meet validation rules defined in database.
        $isCheckViolation = str_contains($dbMessage, 'check constraint') === true
            || str_contains($dbMessage, 'CHECK') === true;
        if ($isCheckViolation === true) {
            $msg = "The provided data doesn't meet the required format or constraints. ";
            return $msg.'Please check your input and try again.';
        }

        // Handle data too long errors.
        // Occurs when string length exceeds column maximum length.
        if (str_contains($dbMessage, 'Data too long') === true || str_contains($dbMessage, 'too long') === true) {
            return "Some of the provided information is too long. Please shorten your input and try again.";
        }

        // Handle general SQL errors (SQLSTATE format).
        // Generic SQL error format used by PDO and other database abstractions.
        if (str_contains($dbMessage, 'SQLSTATE') === true) {
            return "There was a problem saving your {$entityType}. Please check your input and try again.";
        }

        // Generic database error fallback.
        // Used when error message doesn't match any known patterns.
        $msg = "There was a database error while saving your {$entityType}. ";
        return $msg.'Please try again or contact support if the problem persists.';
    }//end parseConstraintError()
}//end class
