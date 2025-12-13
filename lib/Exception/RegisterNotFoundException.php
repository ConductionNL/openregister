<?php
/**
 * Class RegisterNotFoundException
 *
 * Exception thrown when a register cannot be found by slug or ID.
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

use Exception;

/**
 * Exception thrown when a register cannot be found by slug or ID
 *
 * Thrown when attempting to access a register that doesn't exist or the user
 * doesn't have permission to access. Used for error handling in register operations.
 * Uses HTTP 404 Not Found status code.
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
class RegisterNotFoundException extends Exception
{


    /**
     * RegisterNotFoundException constructor
     *
     * Initializes exception with register identifier that was not found.
     * Creates user-friendly error message including the register slug or ID.
     *
     * @param string         $registerSlugOrId The register slug or ID that was not found
     * @param int            $code             The exception code (default: 404 Not Found)
     * @param Exception|null $previous         The previous exception that caused this one
     *
     * @return void
     *
     * @phpstan-param string $registerSlugOrId
     * @phpstan-param int $code
     * @phpstan-param Exception|null $previous
     */
    public function __construct(string $registerSlugOrId, int $code=404, ?Exception $previous=null)
    {
        // Build error message with register identifier.
        $message = "Register not found: '".$registerSlugOrId."'";

        // Call parent constructor to initialize base exception properties.
        parent::__construct($message, $code, $previous);

    }//end __construct()


}//end class
