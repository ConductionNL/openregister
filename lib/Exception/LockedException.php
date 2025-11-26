<?php
/**
 * OpenRegister LockedException
 *
 * This file contains the exception class for object lock errors.
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
 * Exception thrown when an object is locked and cannot be modified.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 */
class LockedException extends Exception
{


    /**
     * Constructor for LockedException.
     *
     * @param string         $message  The error message
     * @param int            $code     The error code
     * @param Throwable|null $previous The previous exception
     *
     * @return void
     */
    public function __construct(
        string $message='Object is locked and cannot be modified',
        int $code=423,
        ?Throwable $previous=null
    ) {
        parent::__construct($message, $code, $previous);

    }//end __construct()


}//end class
