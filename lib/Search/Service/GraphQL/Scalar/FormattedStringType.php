<?php

/**
 * Formatted string scalar type for GraphQL.
 *
 * The shared behaviour of the string scalars that only accept a given format.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL\Scalar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\Scalar;

use GraphQL\Error\Error;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;

/**
 * A string scalar that serializes any string and only parses a valid one.
 *
 * Subclasses name the scalar, the noun used in the invalid-value message and
 * the format check.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */
abstract class FormattedStringType extends ScalarType {

	/**
	 * The noun naming a value of this format in the invalid-value message.
	 *
	 * @return string The noun.
	 */
	abstract protected function formatNoun(): string;

	/**
	 * Whether a string is in this scalar's format.
	 *
	 * @param string $value The string.
	 *
	 * @return bool True when it is.
	 */
	abstract protected function isWellFormed(string $value): bool;

	/**
	 * Serializes a value to string.
	 *
	 * @param mixed $value The value to serialize
	 *
	 * @return string The string
	 *
	 * @throws Error If the value is not a string
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function serialize(mixed $value): string {
		return $this->requireString(value: $value);
	}//end serialize()

	/**
	 * Parses a value from client input.
	 *
	 * @param mixed $value The value to parse
	 *
	 * @return string The validated string
	 *
	 * @throws Error If the value is not a string in this scalar's format
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function parseValue(mixed $value): string {
		$string = $this->requireString(value: $value);
		if ($this->isWellFormed(value: $string) === false) {
			throw new Error(
				$this->name . ' cannot represent invalid ' . $this->formatNoun() . ' value: ' . $string
			);
		}

		return $string;
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
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function parseLiteral(\GraphQL\Language\AST\Node $valueNode, ?array $variables = null): string {
		if ($valueNode instanceof StringValueNode === false) {
			throw new Error($this->name . ' cannot represent non-string value', $valueNode);
		}

		return $this->parseValue(value: $valueNode->value);
	}//end parseLiteral()

	/**
	 * The value itself when it is a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 *
	 * @throws Error If the value is not a string
	 */
	private function requireString(mixed $value): string {
		if (is_string($value) === false) {
			throw new Error(
				$this->name . ' cannot represent non-string value: ' . Utils::printSafe($value)
			);
		}

		return $value;
	}//end requireString()
}//end class
