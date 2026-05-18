<?php

/**
 * OpenRegister AppendOnlyException
 *
 * Exception thrown when a mutating operation (UPDATE or DELETE) is attempted
 * on an object whose schema is declared append-only.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Exception thrown when an UPDATE or DELETE is attempted on an append-only schema.
 *
 * Schemas with `appendOnly: true` permit INSERT operations but reject all
 * subsequent mutations. This is used for audit logs (e.g. xAPI statements,
 * compliance attestations) where immutability of past records is a business
 * or legal requirement.
 *
 * Callers should translate this to HTTP 405 Method Not Allowed with the
 * structured error body:
 *   { "error": "SCHEMA_APPEND_ONLY", "message": "...", "schema": "..." }
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */
class AppendOnlyException extends \Exception
{

    /**
     * The schema slug or identifier that triggered this exception.
     *
     * @var string
     */
    private readonly string $schemaIdentifier;

    /**
     * The mutating operation that was rejected ('update' or 'delete').
     *
     * @var string
     */
    private readonly string $operation;

    /**
     * Constructor.
     *
     * @param string         $schemaIdentifier The schema slug, UUID, or ID
     * @param string         $operation        The rejected operation ('update' or 'delete')
     * @param Throwable|null $previous         Previous exception
     */
    public function __construct(
        string $schemaIdentifier,
        string $operation='update',
        ?Throwable $previous=null
    ) {
        $this->schemaIdentifier = $schemaIdentifier;
        $this->operation        = $operation;

        $message = sprintf(
            'SCHEMA_APPEND_ONLY: Schema "%s" is append-only; %s operations are not permitted.',
            $schemaIdentifier,
            $operation
        );

        parent::__construct(message: $message, code: 405, previous: $previous);
    }//end __construct()

    /**
     * Get the schema identifier that triggered this exception.
     *
     * @return string The schema slug, UUID, or ID
     */
    public function getSchemaIdentifier(): string
    {
        return $this->schemaIdentifier;
    }//end getSchemaIdentifier()

    /**
     * Get the mutating operation that was rejected.
     *
     * @return string 'update' or 'delete'
     */
    public function getOperation(): string
    {
        return $this->operation;
    }//end getOperation()

    /**
     * Build the structured JSON error body for HTTP 405 responses.
     *
     * @return array{error: string, message: string, schema: string, operation: string}
     */
    public function toResponseBody(): array
    {
        return [
            'error'     => 'SCHEMA_APPEND_ONLY',
            'message'   => $this->getMessage(),
            'schema'    => $this->schemaIdentifier,
            'operation' => $this->operation,
        ];
    }//end toResponseBody()
}//end class
