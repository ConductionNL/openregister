<?php

namespace OCA\OpenRegister\Exception;

use Exception;
use Opis\JsonSchema\Errors\ValidationError;
use Throwable;

class ValidationException extends Exception
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = NULL,
        private ?ValidationError $errors = NULL
    ) {
        parent::__construct($message, $code, $previous);

    }//end __construct()

    public function getErrors(): ValidationError
    {
        return $this->errors;

    }//end getErrors()

}//end class
