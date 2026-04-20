<?php

/**
 * Email scalar type for GraphQL.
 *
 * Validates and handles RFC 5321 email address strings.
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
 * Email scalar type validating RFC 5321 email format.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class EmailType extends ScalarType
{

    /**
     * The name of this scalar type.
     *
     * @var string
     */
    public string $name = 'Email';

    /**
     * The description of this scalar type.
     *
     * @var string|null
     */
    public ?string $description = 'RFC 5321 email address string.';

    /**
     * Serializes an email value to string.
     *
     * @param mixed $value The value to serialize
     *
     * @return string The email string
     *
     * @throws Error If the value is not a string
     */
    public function serialize(mixed $value): string
    {
        if (is_string($value) === false) {
            throw new Error(
                'Email cannot represent non-string value: '.Utils::printSafe($value)
            );
        }

        return $value;

    }//end serialize()

    /**
     * Parses a value from client input.
     *
     * @param mixed $value The value to parse
     *
     * @return string The validated email string
     *
     * @throws Error If the value is not a valid email
     */
    public function parseValue(mixed $value): string
    {
        if (is_string($value) === false) {
            throw new Error(
                'Email cannot represent non-string value: '.Utils::printSafe($value)
            );
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new Error(
                'Email cannot represent invalid email value: '.$value
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
     */
    public function parseLiteral(\GraphQL\Language\AST\Node $valueNode, ?array $variables=null): string
    {
        if ($valueNode instanceof StringValueNode === false) {
            throw new Error('Email cannot represent non-string value', $valueNode);
        }

        return $this->parseValue(value: $valueNode->value);

    }//end parseLiteral()
}//end class
