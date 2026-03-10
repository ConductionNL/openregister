<?php

/**
 * Authentication Exception.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception for storing authentication failures with structured details.
 *
 * @package OCA\OpenRegister\Exception
 */
class AuthenticationException extends Exception
{

    /**
     * Details describing why authentication failed.
     *
     * @var array
     */
    private array $details;

    /**
     * Create a new AuthenticationException.
     *
     * @param string $message A human-readable error message
     * @param array  $details Structured details about the failure
     */
    public function __construct(string $message, array $details)
    {
        $this->details = $details;
        parent::__construct(message: $message);

    }//end __construct()

    /**
     * Get the failure details.
     *
     * @return array The details array.
     */
    public function getDetails(): array
    {
        return $this->details;

    }//end getDetails()
}//end class
