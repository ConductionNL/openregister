<?php

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception for storing custom validation errors.
 */
class CustomValidationException extends Exception
{
    private array $errors;

    /**
     * @inheritDoc
     *
     * @param array $errors The errors.
     */
    public function __construct(string $message, array $errors)
    {
        $this->errors = $errors;
        parent::__construct($message);

    }//end __construct()

    /**
     * Retrieves the errors to display them.
     *
     * @return array The errors array.
     */
    public function getErrors(): array
    {
        return $this->errors;

    }//end getErrors()

}//end class
