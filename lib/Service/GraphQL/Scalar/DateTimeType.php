<?php

/**
 * DateTime scalar type for GraphQL.
 *
 * Handles serialization and parsing of ISO 8601 date-time strings.
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
 * DateTime scalar type for ISO 8601 date-time strings.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DateTimeType extends ScalarType
{

    /**
     * The name of this scalar type.
     *
     * @var string
     */
    public string $name = 'DateTime';

    /**
     * The description of this scalar type.
     *
     * @var string|null
     */
    public ?string $description = 'ISO 8601 date-time string (e.g., 2025-01-15T10:30:00+00:00).';

    /**
     * Serializes a DateTime value to ISO 8601 string.
     *
     * @param mixed $value The value to serialize
     *
     * @return string The ISO 8601 string
     *
     * @throws Error If the value cannot be serialized
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    public function serialize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value) === true) {
            return $value;
        }

        throw new Error(
            'DateTime cannot represent non-date value: '.Utils::printSafe($value)
        );

    }//end serialize()

    /**
     * Parses a value from client input.
     *
     * @param mixed $value The value to parse
     *
     * @return string The validated ISO 8601 string
     *
     * @throws Error If the value is not a valid ISO 8601 date-time
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    public function parseValue(mixed $value): string
    {
        if (is_string($value) === false) {
            throw new Error(
                'DateTime cannot represent non-string value: '.Utils::printSafe($value)
            );
        }

        $dateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);
        if ($dateTime === false) {
            $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);
        }

        if ($dateTime === false) {
            $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        }

        if ($dateTime === false) {
            throw new Error(
                'DateTime cannot represent invalid date-time value: '.$value
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
            throw new Error(
                'DateTime cannot represent non-string value',
                $valueNode
            );
        }

        return $this->parseValue(value: $valueNode->value);

    }//end parseLiteral()
}//end class
