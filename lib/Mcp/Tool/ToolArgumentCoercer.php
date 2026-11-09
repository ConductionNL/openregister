<?php

/**
 * OpenRegister Tool Argument Coercer
 *
 * Maps the loosely-typed arguments an LLM sends to a tool method's parameters.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Mcp\Tool;

use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Builds the typed argument list for a snake_case tool call.
 *
 * Kept out of AbstractTool so its helpers are not reachable through the
 * tool's snake_case __call dispatch. Deliberately not strict_types, like
 * AbstractTool: the casts below are the coercion the LLM path relies on.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ToolArgumentCoercer {

	/**
	 * The arguments for a method, matched by name or position and type-cast.
	 *
	 * @param ReflectionMethod $method    The camelCase method to call.
	 * @param array            $arguments Positional or associative arguments.
	 *
	 * @return array Positional, type-cast arguments
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function argumentsFor(ReflectionMethod $method, array $arguments): array {
		// Associative arrays have non-sequential keys (e.g., ['param1' => 'value']).
		// Positional arrays have sequential numeric keys (e.g., [0 => 'value', 1 => 'value']).
		$isAssociative = array_keys($arguments) !== range(0, count($arguments) - 1);

		$typedArguments = [];
		foreach ($method->getParameters() as $index => $param) {
			$value = $this->rawValue(
				arguments: $arguments,
				index: $index,
				paramName: $param->getName(),
				isAssociative: $isAssociative
			);
			$typedArguments[] = $this->coerce(param: $param, value: $value);
		}

		return $typedArguments;
	}//end argumentsFor()

	/**
	 * The argument for one parameter: named argument > positional argument > null.
	 *
	 * @param array      $arguments     The call's arguments.
	 * @param int|string $index         The parameter position.
	 * @param string     $paramName     The parameter name.
	 * @param bool       $isAssociative Whether the arguments are named.
	 *
	 * @return mixed The raw value
	 */
	private function rawValue(array $arguments, int|string $index, string $paramName, bool $isAssociative): mixed {
		// Use positional argument as default.
		$value = $arguments[$index] ?? null;
		if ($isAssociative === true && (($arguments[$paramName] ?? null) !== null)) {
			// Use named argument if available (associative array).
			$value = $arguments[$paramName];
		}

		return $value;
	}//end rawValue()

	/**
	 * Replace a (string) null by the default and cast other values to the declared type.
	 *
	 * @param ReflectionParameter $param The parameter.
	 * @param mixed               $value The raw value.
	 *
	 * @return mixed The value to pass
	 */
	private function coerce(ReflectionParameter $param, mixed $value): mixed {
		// LLMs sometimes return string 'null' instead of actual null value.
		if ($value === 'null' || $value === null) {
			// Use default value if parameter has one, otherwise null.
			if ($param->isDefaultValueAvailable() === true) {
				return $param->getDefaultValue();
			}

			return null;
		}

		$type = $param->getType();
		if ($type instanceof ReflectionNamedType === false) {
			return $value;
		}

		return $this->castTo(typeName: $type->getName(), value: $value);
	}//end coerce()

	/**
	 * Cast a value to a named type; other types pass through unchanged.
	 *
	 * @param string $typeName The declared type name.
	 * @param mixed  $value    The value.
	 *
	 * @return mixed The cast value
	 */
	private function castTo(string $typeName, mixed $value): mixed {
		return match ($typeName) {
			'int' => (int)$value,
			'float' => (float)$value,
			// Handles 'true', 'false', '1', '0', etc.
			'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			'string' => (string)$value,
			// If already array, keep it; otherwise convert to empty array.
			'array' => $this->asArray(value: $value),
			default => $value,
		};
	}//end castTo()

	/**
	 * The value when it is an array, otherwise an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array
	 */
	private function asArray(mixed $value): array {
		if (is_array($value) !== true) {
			return [];
		}

		return $value;
	}//end asArray()
}//end class
