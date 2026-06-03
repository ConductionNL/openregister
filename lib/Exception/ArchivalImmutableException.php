<?php

/**
 * OpenRegister ArchivalImmutableException
 *
 * Thrown when a user attempts to delete an object from a schema that declares
 * x-openregister-archival. Only the ArchivalRetentionTask cron is exempt.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when a delete is attempted on an archival-protected schema.
 *
 * HTTP code: 403. The structured body SHALL be:
 *   { error: "SCHEMA_ARCHIVAL_IMMUTABLE", message, schema, operation: "delete", hint }
 *
 * Callers that render HTTP responses should inspect getBody() to forward the
 * structured payload to the client.
 */
class ArchivalImmutableException extends Exception
{

    /**
     * Machine-readable error code surfaced in the JSON body.
     */
    private const ERROR_CODE = 'SCHEMA_ARCHIVAL_IMMUTABLE';

    /**
     * Structured body to be forwarded to the HTTP response.
     *
     * @var array{error: string, message: string, schema: string, operation: string, hint: string}
     */
    private array $body;

    /**
     * Construct an immutability exception for the given schema.
     *
     * @param string         $schema    The slug or UUID of the archival schema.
     * @param string         $operation The operation that was blocked (e.g. 'delete').
     * @param Throwable|null $previous  The previous exception, if any.
     *
     * @return void
     */
    public function __construct(
        private readonly string $schema,
        private readonly string $operation='delete',
        ?Throwable $previous=null
    ) {
        $message = "Schema '$schema' declares x-openregister-archival; '$operation' is forbidden.";
        $hint    = 'Only the ArchivalRetentionTask sweep may delete archival objects.';

        $this->body = [
            'error'     => self::ERROR_CODE,
            'message'   => $message,
            'schema'    => $schema,
            'operation' => $operation,
            'hint'      => $hint,
        ];

        parent::__construct(message: $message, code: 403, previous: $previous);
    }//end __construct()

    /**
     * Return the structured body suitable for a JSON HTTP 403 response.
     *
     * @return array{error: string, message: string, schema: string, operation: string, hint: string}
     */
    public function getBody(): array
    {
        return $this->body;
    }//end getBody()
}//end class
