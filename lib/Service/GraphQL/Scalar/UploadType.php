<?php

/**
 * Upload scalar type for GraphQL.
 *
 * Follows the GraphQL multipart request specification for file uploads.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL\Scalar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Service\GraphQL\Scalar;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;

/**
 * Upload scalar type following the GraphQL multipart request specification.
 *
 * In queries, file fields return as File objects. In mutations, this scalar
 * accepts file upload data which is delegated to FilePropertyHandler.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class UploadType extends ScalarType
{

    /**
     * The name of this scalar type.
     *
     * @var string
     */
    public string $name = 'Upload';

    /**
     * The description of this scalar type.
     *
     * @var string|null
     */
    public ?string $description = 'File upload scalar (multipart request spec). Returns File object in queries.';

    /**
     * Serializes an upload value.
     *
     * @param mixed $value The value to serialize
     *
     * @return mixed The serialized value
     */
    public function serialize(mixed $value): mixed
    {
        // Files are serialized as their metadata (handled by File output type).
        return $value;

    }//end serialize()

    /**
     * Parses a value from client input.
     *
     * @param mixed $value The value to parse
     *
     * @return mixed The parsed value
     *
     * @throws Error If the value cannot be represented
     */
    public function parseValue(mixed $value): mixed
    {
        // Value comes from the multipart request processor — it's already a file reference.
        if (is_array($value) === true || is_string($value) === true) {
            return $value;
        }

        throw new Error(
            'Upload cannot represent value: '.Utils::printSafe($value)
        );

    }//end parseValue()

    /**
     * Parses a literal AST value.
     *
     * @param \GraphQL\Language\AST\Node $valueNode The AST node
     * @param array|null                 $variables Variables map
     *
     * @return mixed The parsed value
     *
     * @throws Error Always, uploads must use multipart form
     */
    public function parseLiteral(\GraphQL\Language\AST\Node $valueNode, ?array $variables=null): mixed
    {
        throw new Error('Upload cannot be used as a literal value — use multipart form upload');

    }//end parseLiteral()
}//end class
