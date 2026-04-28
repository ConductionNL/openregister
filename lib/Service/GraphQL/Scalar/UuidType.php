<?php

/**
 * UUID scalar type for GraphQL.
 *
 * Validates and handles UUID v4 format strings.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL\Scalar
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Service\GraphQL\Scalar;

use GraphQL\Error\Error;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;

/**
 * UUID scalar type validating UUID v4 format.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class UuidType extends ScalarType
{

    /**
     * The name of this scalar type.
     *
     * @var string
     */
    public string $name = 'UUID';

    /**
     * The description of this scalar type.
     *
     * @var string|null
     */
    public ?string $description = 'UUID v4 string (e.g., 550e8400-e29b-41d4-a716-446655440000).';

    /**
     * The UUID validation pattern.
     *
     * @var string
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Serializes a UUID value to string.
     *
     * @param mixed $value The value to serialize
     *
     * @return string The UUID string
     *
     * @throws Error If the value is not a string
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    public function serialize(mixed $value): string
    {
        if (is_string($value) === false) {
            throw new Error(
                'UUID cannot represent non-string value: '.Utils::printSafe($value)
            );
        }

        return $value;

    }//end serialize()

    /**
     * Parses a value from client input.
     *
     * @param mixed $value The value to parse
     *
     * @return string The validated UUID string
     *
     * @throws Error If the value is not a valid UUID
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    public function parseValue(mixed $value): string
    {
        if (is_string($value) === false) {
            throw new Error(
                'UUID cannot represent non-string value: '.Utils::printSafe($value)
            );
        }

        if (preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw new Error(
                'UUID cannot represent invalid UUID value: '.$value
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
            throw new Error('UUID cannot represent non-string value', $valueNode);
        }

        return $this->parseValue(value: $valueNode->value);

    }//end parseLiteral()
}//end class
