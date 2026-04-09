<?php

/**
 * Class NoVtodoCalendarException
 *
 * Exception thrown when no VTODO-supporting calendar is found for a user.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Exception thrown when no VTODO-supporting calendar is found for a user
 *
 * Thrown when attempting to find a calendar that supports VTODO components
 * but none exists for the given user. Used to gracefully handle the absence
 * of a tasks calendar without exposing internal error messages.
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
 *
 * @phpstan-consistent-constructor
 */
class NoVtodoCalendarException extends RuntimeException
{
    /**
     * NoVtodoCalendarException constructor
     *
     * @param string              $userId   The user ID for whom no calendar was found
     * @param int                 $code     The exception code (default: 0)
     * @param \Throwable|null     $previous The previous exception that caused this one
     *
     * @return void
     */
    public function __construct(string $userId, int $code = 0, ?\Throwable $previous = null)
    {
        $message = 'No VTODO-supporting calendar found for user '.$userId;

        parent::__construct(message: $message, code: $code, previous: $previous);
    }//end __construct()
}//end class
