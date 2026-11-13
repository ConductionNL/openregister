<?php

/**
 * ValidationErrorMessages
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;

/**
 * Turns an Opis validation result into a clear, user-friendly message.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ValidationErrorMessages {
	/**
	 * Messages that name the property, one argument and the value: the argument key, its
	 * default, and the sprintf template.
	 *
	 * @var array<string, array{0: string, 1: int|string, 2: string}>
	 */
	private const SIMPLE_MESSAGES = [
		'format'  => [
			'format',
			'unknown',
			"Property '%s' should match format '%s' but '%s' does not. Please provide a value in the correct format.",
		],
		'minimum' => ['min', 0, "Property '%s' should be at least %s, but is %s. Please provide a larger number."],
		'maximum' => ['max', 0, "Property '%s' should be at most %s, but is %s. Please provide a smaller number."],
		'pattern' => [
			'pattern',
			'unknown',
			"Property '%s' should match pattern '%s' but '%s' does not. Please provide a value that matches the required pattern.",
		],
	];

	/**
	 * Per expected type, the empty value that gets its own message, and that message.
	 *
	 * @var array<string, array{0: string|array{}, 1: string}>
	 */
	private const EMPTY_VALUE_MESSAGES = [
		'string' => [
			'',
			'expects non-empty string but got empty string. For non-required string properties, set this to null to clear the field. '
			.'For required string properties, provide a valid string value.',
		],
		'object' => [
			[],
			'expects object but got empty ({}). For non-required object properties, set this to null to clear the field. '
			.'For required object properties, provide a valid object with the necessary properties.',
		],
		'array'  => [
			[],
			'expects non-empty array but got empty array ([]). This likely has a minItems constraint. Please provide at least one item.',
		],
	];

	/**
	 * The JSON name of each PHP type.
	 *
	 * @var array<string, string>
	 */
	private const JSON_TYPES = [
		'NULL'    => 'null',
		'boolean' => 'boolean',
		'integer' => 'integer',
		'double'  => 'number',
		'string'  => 'string',
		'array'   => 'array',
		'object'  => 'object',
	];

	/**
	 * The message for a validation result: its primary error, or that it passed.
	 *
	 * @param ValidationResult $result The validation result from Opis JsonSchema.
	 *
	 * @return string A meaningful error message.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function forResult(ValidationResult $result): string {
		if ($result->isValid() === true) {
			return 'Validation passed';
		}

		return $this->forError(error: $result->error());
	}//end forResult()

	/**
	 * The JSON type name of a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The type name, `unknown` for what JSON cannot hold.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function valueType(mixed $value): string {
		return (self::JSON_TYPES[gettype($value)] ?? 'unknown');
	}//end valueType()

	/**
	 * The message for one error, by its keyword.
	 *
	 * @param ValidationError $error The validation error.
	 *
	 * @return string The message.
	 */
	private function forError(ValidationError $error): string {
		$keyword = $error->keyword();
		$path    = $this->propertyPath(error: $error);

		if (isset(self::SIMPLE_MESSAGES[$keyword]) === true) {
			return $this->simpleMessage(error: $error, path: $path);
		}

		return match ($keyword) {
			'required' => $this->requiredMessage(args: $error->args(), path: $path),
			'type' => $this->typeMessage(error: $error, path: $path),
			'minItems', 'maxItems' => $this->itemCountMessage(error: $error, path: $path),
			'minLength', 'maxLength' => $this->lengthMessage(error: $error, path: $path),
			'enum' => $this->enumMessage(error: $error, path: $path),
			default => $this->otherMessage(error: $error, path: $path),
		};
	}//end forError()

	/**
	 * The dotted path of the failing property, `root` for the document itself.
	 *
	 * @param ValidationError $error The validation error.
	 *
	 * @return string The path.
	 */
	private function propertyPath(ValidationError $error): string {
		$dataPath = $error->data()->fullPath();
		if (empty($dataPath) === true) {
			return 'root';
		}

		return implode('.', $dataPath);
	}//end propertyPath()

	/**
	 * The message for a keyword in {@see self::SIMPLE_MESSAGES}.
	 *
	 * @param ValidationError $error The validation error.
	 * @param string          $path  The property path.
	 *
	 * @return string The message.
	 */
	private function simpleMessage(ValidationError $error, string $path): string {
		[$argName, $default, $template] = self::SIMPLE_MESSAGES[$error->keyword()];

		return sprintf($template, $path, ($error->args()[$argName] ?? $default), $error->data()->value());
	}//end simpleMessage()

	/**
	 * The message for missing required properties.
	 *
	 * A key missing inside a nested object is named by its full path
	 * (`contactgegevens.email`), so the caller can tell which object lacks it.
	 *
	 * @param array<string, mixed> $args The error's arguments.
	 * @param string               $path The path of the object missing the keys, `root` for the document.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function requiredMessage(array $args, string $path='root'): string {
		$missing = ($args['missing'] ?? []);
		if (is_array($missing) === false || count($missing) === 0) {
			return 'Required property is missing';
		}

		if ($path !== 'root') {
			$missing = array_map(static fn (mixed $key): string => $path.'.'.$key, $missing);
		}

		if (count($missing) === 1) {
			return "The required property ({$missing[0]}) is missing. Please provide a value for this property or set it to null if allowed.";
		}

		$missingList = implode(', ', $missing);
		return "The required properties ({$missingList}) are missing. Please provide values for these properties.";
	}//end requiredMessage()

	/**
	 * The message for a value of the wrong type.
	 *
	 * @param ValidationError $error The validation error.
	 * @param string          $path  The property path.
	 *
	 * @return string The message.
	 */
	private function typeMessage(ValidationError $error, string $path): string {
		$value    = $error->data()->value();
		$expected = ($error->args()['expected'] ?? 'unknown');

		// A type list such as ["string", "null"].
		if (is_array($expected) === true) {
			$expected = implode(' or ', $expected);
		}

		$emptyHint = $this->emptyValueMessage(expected: $expected, value: $value);
		if ($emptyHint !== null) {
			return "Property '{$path}' {$emptyHint}";
		}

		$actual = $this->valueType(value: $value);
		return "Property '{$path}' should be type '{$expected}' but is '{$actual}'. Please provide a value of the correct type.";
	}//end typeMessage()

	/**
	 * The hint for an empty value where a filled one of the expected type is needed.
	 *
	 * @param mixed $expected The expected type.
	 * @param mixed $value    The value.
	 *
	 * @return string|null The hint, null for any other value.
	 */
	private function emptyValueMessage(mixed $expected, mixed $value): ?string {
		[$empty, $message] = (self::EMPTY_VALUE_MESSAGES[$expected] ?? [null, null]);

		if ($value === $empty) {
			return $message;
		}

		return null;
	}//end emptyValueMessage()

	/**
	 * The message for an array with too few or too many items.
	 *
	 * @param ValidationError $error The validation error.
	 * @param string          $path  The property path.
	 *
	 * @return string The message.
	 */
	private function itemCountMessage(ValidationError $error, string $path): string {
		$value  = $error->data()->value();
		$actual = 0;
		if (is_array($value) === true) {
			$actual = count($value);
		}

		if ($error->keyword() === 'minItems') {
			$minItems = ($error->args()['min'] ?? 0);
			$hint     = 'Please add more items to the array or set to null if the property is not required.';
			return "Property '{$path}' requires at least {$minItems} items, has {$actual}. {$hint}";
		}

		$maxItems = ($error->args()['max'] ?? 0);
		return "Property '{$path}' allows at most {$maxItems} items, has {$actual}. Please remove some items from the array.";
	}//end itemCountMessage()

	/**
	 * The message for a string that is too short or too long.
	 *
	 * @param ValidationError $error The validation error.
	 * @param string          $path  The property path.
	 *
	 * @return string The message.
	 */
	private function lengthMessage(ValidationError $error, string $path): string {
		$actual = self::lengthOf(value: $error->data()->value());

		if ($error->keyword() === 'maxLength') {
			$maxLength = ($error->args()['max'] ?? 0);
			return "Property '{$path}' allows at most {$maxLength} chars, has {$actual}. Please provide a shorter string value.";
		}

		$minLength = ($error->args()['min'] ?? 0);
		if ($actual === 0) {
			return "Property '{$path}' requires at least {$minLength} characters, but is empty. Please provide a non-empty string value.";
		}

		return "Property '{$path}' requires at least {$minLength} chars, has {$actual}. Please provide a longer string value.";
	}//end lengthMessage()

	/**
	 * The length of a value, 0 for anything but a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int The length.
	 */
	private static function lengthOf(mixed $value): int {
		if (is_string($value) === true) {
			return strlen($value);
		}

		return 0;
	}//end lengthOf()

	/**
	 * The message for a value outside its enum.
	 *
	 * @param ValidationError $error The validation error.
	 * @param string          $path  The property path.
	 *
	 * @return string The message.
	 */
	private function enumMessage(ValidationError $error, string $path): string {
		$value   = $error->data()->value();
		$allowed = ($error->args()['values'] ?? []);
		if ($allowed === []) {
			$allowed = $this->enumFromSchema(error: $error);
		}

		if (is_array($allowed) === false || $allowed === []) {
			return "Property '{$path}' has an invalid value '{$value}'. Please provide one of the allowed values.";
		}

		$list = implode(', ', array_map(static fn ($item): string => "'{$item}'", $allowed));
		return "Property '{$path}' should be one of: {$list}, but is '{$value}'. Please choose one of the allowed values.";
	}//end enumMessage()

	/**
	 * The enum list read from the failing schema.
	 *
	 * @param ValidationError $error The validation error.
	 *
	 * @return array The allowed values, empty when the schema has no list.
	 */
	private function enumFromSchema(ValidationError $error): array {
		$schemaData = $error->schema()->info()->data();
		if (is_object($schemaData) === true && isset($schemaData->enum) === true && is_array($schemaData->enum) === true) {
			return $schemaData->enum;
		}

		return [];
	}//end enumFromSchema()

	/**
	 * The message for any other keyword: its first sub-error, or a general one.
	 *
	 * @param ValidationError $error The validation error.
	 * @param string          $path  The property path.
	 *
	 * @return string The message.
	 */
	private function otherMessage(ValidationError $error, string $path): string {
		$subErrors = $error->subErrors();
		if (empty($subErrors) === false) {
			return $this->forError(error: $subErrors[0]);
		}

		return "Property '{$path}' failed validation for rule '{$error->keyword()}'. Please check the property value and schema requirements.";
	}//end otherMessage()
}//end class
