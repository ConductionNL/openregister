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
 */
class DatabaseConstraintException extends Exception
{

    /**
     * HTTP status code for the error
     *
     * @var integer
     */
    private int $httpStatusCode;


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
     * @return int The HTTP status code
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;

    }//end getHttpStatusCode()


    /**
     * Create a DatabaseConstraintException from a database exception
     *
     * @param Exception $dbException The original database exception
     * @param string    $entityType  The type of entity (e.g., 'schema', 'register', 'object')
     *
     * @return DatabaseConstraintException The user-friendly exception
     */
    public static function fromDatabaseException(Exception $dbException, string $entityType='item'): DatabaseConstraintException
    {
        $message     = $dbException->getMessage();
        $userMessage = self::parseConstraintError($message, $entityType);

        return new self($userMessage, (int) $dbException->getCode(), 409, $dbException);

    }//end fromDatabaseException()


    /**
     * Parse database constraint error messages and return user-friendly messages
     *
     * @param string $dbMessage  The database error message
     * @param string $entityType The type of entity being saved
     *
     * @return string User-friendly error message
     */
    private static function parseConstraintError(string $dbMessage, string $entityType): string
    {
        // Handle unique constraint violations.
        if (str_contains($dbMessage, 'Duplicate entry') === true && str_contains($dbMessage, 'for key') === true) {
            // Extract constraint name for more specific messages.
            if (str_contains($dbMessage, 'schemas_organisation_slug_unique') === true) {
                return "A schema with this slug already exists in your organization. Please choose a different slug or title.";
            } else if (str_contains($dbMessage, 'registers_organisation_slug_unique') === true) {
                return "A register with this slug already exists in your organization. Please choose a different slug or title.";
            } else if (str_contains($dbMessage, 'unique') === true) {
                return "This {$entityType} already exists. Please check your input and try again.";
            }

            return "A {$entityType} with these details already exists. Please modify your input and try again.";
        }

        // Handle foreign key constraint violations.
        if (str_contains($dbMessage, 'foreign key constraint') === true || str_contains($dbMessage, 'FOREIGN KEY') === true) {
            return "This {$entityType} cannot be saved because it references data that doesn't exist. Please check your configuration and try again.";
        }

        // Handle NOT NULL constraint violations.
        if (str_contains($dbMessage, 'cannot be null') === true || str_contains($dbMessage, 'NOT NULL') === true) {
            return "Required information is missing. Please fill in all required fields and try again.";
        }

        // Handle CHECK constraint violations.
        if (str_contains($dbMessage, 'check constraint') === true || str_contains($dbMessage, 'CHECK') === true) {
            return "The provided data doesn't meet the required format or constraints. Please check your input and try again.";
        }

        // Handle data too long errors.
        if (str_contains($dbMessage, 'Data too long') === true || str_contains($dbMessage, 'too long') === true) {
            return "Some of the provided information is too long. Please shorten your input and try again.";
        }

        // Handle general SQL errors.
        if (str_contains($dbMessage, 'SQLSTATE') === true) {
            return "There was a problem saving your {$entityType}. Please check your input and try again.";
        }

        // Generic database error fallback.
        return "There was a database error while saving your {$entityType}. Please try again or contact support if the problem persists.";

    }//end parseConstraintError()


}//end class
