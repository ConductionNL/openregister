<?php

/**
 * JSON scalar type for GraphQL.
 *
 * Handles arbitrary JSON values including objects, arrays, and scalars.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL\Scalar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Service\GraphQL\Scalar;

use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

/**
 * JSON scalar type for arbitrary JSON values.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class JsonType extends ScalarType {

	/**
	 * The name of this scalar type.
	 *
	 * @var string
	 */
	public string $name = 'JSON';

	/**
	 * The description of this scalar type.
	 *
	 * @var string|null
	 */
	public ?string $description = 'Arbitrary JSON value (object, array, scalar).';

	/**
	 * Serializes a JSON value.
	 *
	 * @param mixed $value The value to serialize
	 *
	 * @return mixed The serialized value
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function serialize(mixed $value): mixed {
		return $value;
	}//end serialize()

	/**
	 * Parses a value from client input.
	 *
	 * @param mixed $value The value to parse
	 *
	 * @return mixed The parsed value
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function parseValue(mixed $value): mixed {
		return $value;
	}//end parseValue()

	/**
	 * Parses a literal AST value.
	 *
	 * @param \GraphQL\Language\AST\Node $valueNode The AST node
	 * @param array|null $variables Variables map
	 *
	 * @return mixed The parsed value
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function parseLiteral(\GraphQL\Language\AST\Node $valueNode, ?array $variables = null): mixed {
		if ($valueNode instanceof StringValueNode) {
			return $valueNode->value;
		}

		if ($valueNode instanceof IntValueNode) {
			return (int)$valueNode->value;
		}

		if ($valueNode instanceof FloatValueNode) {
			return (float)$valueNode->value;
		}

		if ($valueNode instanceof BooleanValueNode) {
			return $valueNode->value;
		}

		if ($valueNode instanceof ObjectValueNode) {
			$result = [];
			foreach ($valueNode->fields as $field) {
				$result[$field->name->value] = $this->parseLiteral(valueNode: $field->value);
			}

			return $result;
		}

		if ($valueNode instanceof ListValueNode) {
			return array_map(
				fn ($node) => $this->parseLiteral(valueNode: $node),
				iterator_to_array($valueNode->values)
			);
		}

		return null;
	}//end parseLiteral()
}//end class
