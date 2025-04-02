<?php
/**
 * OpenRegister CustomValidationException
 *
 * This file contains the exception class for custom validation errors.
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
