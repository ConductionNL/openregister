<?php
/**
 * OpenRegister ValidationException
 *
 * This file contains the exception class for validation errors.
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
use Opis\JsonSchema\Errors\ValidationError;
use Throwable;

class ValidationException extends Exception
{


    public function __construct(
        string $message,
        int $code=0,
        ?Throwable $previous=null,
        private ?ValidationError $errors=null
    ) {
        parent::__construct($message, $code, $previous);

    }//end __construct()


    public function getErrors(): ValidationError
    {
        return $this->errors;

    }//end getErrors()


}//end class
