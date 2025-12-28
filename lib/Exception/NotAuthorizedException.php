<?php

/**
 * OpenRegister NotAuthorizedException
 *
 * This file contains the exception class for authorization errors.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when a user is not authorized to perform an action
 *
 * Thrown when a user attempts to perform an operation they don't have
 * permission for. Used for access control and authorization checks.
 * Typically results in HTTP 403 Forbidden response.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class NotAuthorizedException extends Exception
{
    /**
     * Constructor for NotAuthorizedException
     *
     * Initializes exception with authorization error message.
     * Uses default HTTP 403 Forbidden status code.
     *
     * @param string         $message  The error message describing authorization failure
     * @param int            $code     The error code (default: 403 Forbidden)
     * @param Throwable|null $previous The previous exception that caused this one
     *
     * @return void
     */
    public function __construct(
        string $message = 'You are not authorized to perform this action',
        int $code = 403,
        ?Throwable $previous = null
    ) {
        // Call parent constructor to initialize base exception properties.
        parent::__construct($message, $code, $previous);
    }//end __construct()
}//end class
