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
 * Exception thrown when a user is not authorized to perform an action.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 */
class NotAuthorizedException extends Exception
{

    /**
     * Constructor for NotAuthorizedException.
     *
     * @param string         $message  The error message.
     * @param int            $code     The error code.
     * @param Throwable|null $previous The previous exception.
     *
     * @return void
     */
    public function __construct(
        string $message = 'User is not authorized to perform this action',
        int $code = 403,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

    }//end __construct()


}//end class

