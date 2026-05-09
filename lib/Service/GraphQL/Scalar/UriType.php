<?php

/**
 * URI scalar type for GraphQL.
 *
 * Validates and handles RFC 3986 URI strings.
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
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;

/**
 * URI scalar type for valid URI strings.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class UriType extends ScalarType
{

    /**
     * The name of this scalar type.
     *
     * @var string
     */
    public string $name = 'URI';

    /**
     * The description of this scalar type.
     *
     * @var string|null
     */
    public ?string $description = 'A valid URI string (RFC 3986).';

    /**
     * Serializes a URI value to string.
     *
     * @param mixed $value The value to serialize
     *
     * @return string The URI string
     *
     * @throws Error If the value is not a string
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    public function serialize(mixed $value): string
    {
        if (is_string($value) === false) {
            throw new Error(
                'URI cannot represent non-string value: '.Utils::printSafe($value)
            );
        }

        return $value;

    }//end serialize()

    /**
     * Parses a value from client input.
     *
     * @param mixed $value The value to parse
     *
     * @return string The validated URI string
     *
     * @throws Error If the value is not a valid URI
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    public function parseValue(mixed $value): string
    {
        if (is_string($value) === false) {
            throw new Error(
                'URI cannot represent non-string value: '.Utils::printSafe($value)
            );
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new Error(
                'URI cannot represent invalid URI value: '.$value
            );
        }

        return $value;

    }//end parseValue()

    /**
     * Parses a literal AST value.
     *
     * @param \GraphQL\Language\AST\Node $valueNode The AST node
     * @param array|null                 $variables Variables map
     *
     * @return string The parsed value
     *
     * @throws Error If the node is not a string
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    public function parseLiteral(\GraphQL\Language\AST\Node $valueNode, ?array $variables=null): string
    {
        if ($valueNode instanceof StringValueNode === false) {
            throw new Error('URI cannot represent non-string value', $valueNode);
        }

        return $this->parseValue(value: $valueNode->value);

    }//end parseLiteral()
}//end class
