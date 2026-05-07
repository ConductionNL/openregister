<?php

/**
 * OpenRegister OAS validation-failure exception.
 *
 * Thrown by `OasValidationMiddleware::beforeController` when the request
 * body fails OAS schema validation. Caught by the same middleware's
 * `afterException` method and translated into an RFC 7807 422 response.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

/**
 * Internal carrier for the validator's `{path, message}[]` error list.
 */
class OasValidationFailureException extends \RuntimeException
{
    /**
     * Constructor.
     *
     * @param array<int, array{path: string, message: string}> $errors The flat list of validation errors.
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(message: 'OAS request validation failed');

    }//end __construct()

    /**
     * Return the validator's `{path, message}[]` payload.
     *
     * @return array<int, array{path: string, message: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;

    }//end getErrors()
}//end class
