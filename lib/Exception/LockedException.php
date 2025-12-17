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
 * Exception thrown when an object is locked and cannot be modified
 *
 * Thrown when attempting to modify an object that is currently locked.
 * Object locking prevents concurrent modifications and ensures data integrity.
 * Uses HTTP 423 Locked status code as per RFC 4918.
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
class LockedException extends Exception
{
    /**
     * Constructor for LockedException
     *
     * Initializes exception with lock error message.
     * Uses HTTP 423 Locked status code (RFC 4918) to indicate the resource
     * is locked and cannot be modified at this time.
     *
     * @param string         $message  The error message describing lock status
     *                                 (default: 'Object is locked and cannot be modified')
     * @param int            $code     The error code (default: 423 Locked)
     * @param Throwable|null $previous The previous exception that caused this one
     *
     * @return void
     */
    public function __construct(
        string $message='Object is locked and cannot be modified',
        int $code=423,
        ?Throwable $previous=null
    ) {
        // Call parent constructor to initialize base exception properties.
        // HTTP 423 Locked indicates the resource is locked (RFC 4918).
        parent::__construct($message, $code, $previous);

    }//end __construct()
}//end class
