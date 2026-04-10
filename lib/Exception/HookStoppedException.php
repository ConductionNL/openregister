<?php

/**
 * OpenRegister HookStoppedException
 *
 * Exception thrown when a schema hook stops event propagation.
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
 * Exception thrown when a schema hook stops event propagation
 *
 * Contains the validation errors returned by the hook that rejected the operation.
 */
class HookStoppedException extends Exception
{

    /**
     * Validation errors from the hook
     *
     * @var array<string, mixed>
     */
    private readonly array $errors;

    /**
     * Constructor for HookStoppedException
     *
     * @param string               $message  Error message
     * @param array<string, mixed> $errors   Hook validation errors
     * @param int                  $code     Error code
     * @param Throwable|null       $previous Previous exception
     *
     * @return void
     */
    public function __construct(
        string $message='Operation blocked by schema hook',
        array $errors=[],
        int $code=0,
        ?Throwable $previous=null
    ) {
        $this->errors = $errors;
        parent::__construct(message: $message, code: $code, previous: $previous);
    }//end __construct()

    /**
     * Get the hook validation errors
     *
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }//end getErrors()
}//end class
