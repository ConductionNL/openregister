<?php
/**
 * NotAuthorizedException Class
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

/**
 * Exception thrown when a user is not authorized for an action
 */
class NotAuthorizedException extends \Exception
{
    /**
     * Constructor for the exception
     *
     * @param string          $message  The error message
     * @param int             $code     The error code
     * @param \Throwable|null $previous The previous exception
     */
    public function __construct(string $message = 'Not authorized', int $code = 401, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }//end __construct()
} 