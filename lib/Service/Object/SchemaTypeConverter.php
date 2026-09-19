<?php

/**
 * Schema-Driven Type Converter
 *
 * Coerces a database column value to the runtime PHP type declared by the
 * JSON Schema for the corresponding object property. Single source of truth
 * for type coercion across the magic-table read paths.
 *
 * Contract spec: openspec/specs/schema-driven-read-coercion/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Object
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-13
 *
 * @since 2.x.x  Extracted from MagicSearchHandler::convertValueByType so both
 *               magic-mapper read paths (statistics + search) share one
 *               converter. Fixes the read-side type drift where booleans came
 *               back as int 0/1 and string properties were silently
 *               JSON-decoded when their values looked like JSON literals.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Stateless converter that maps a database column value onto the runtime PHP
 * type declared by the JSON Schema for the corresponding property.
 *
 * Used by both `MagicStatisticsHandler::convertRowToObjectEntity` (single
 * GET, list GET, UNION search across schemas, cross-table find) and
 * `MagicSearchHandler::convertRowToObjectEntity` (search) so the runtime
 * type returned to API consumers always matches the schema declaration.
 *
 * The converter is intentionally narrow: a single public entry point taking a
 * raw value and the schema-declared type. Format-specific normalisation
 * (e.g. `format: date`) stays in the calling handler — see design D5.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Match expression unavoidable for typed dispatch
 */
class SchemaTypeConverter {
	/**
	 * Convert a database column value to the type declared in the JSON Schema.
	 *
	 * Dispatch table:
	 *
	 *   $schemaType         | result
	 *   --------------------|----------------------------------------------------
	 *   'array' / 'object'  | JSON-decode if string-shaped, else pass through
	 *   'number'            | (float) when is_numeric, else pass through
	 *   'integer'           | (int) when is_numeric, else pass through
	 *   'boolean'           | true for {true, '1', 1, 'true', 'yes'} (case-insensitive
	 *                       | for strings), false for everything else; passes native bools
	 *   default (string + ??) | pass strings through; cast int/float to (string);
	 *                          | only decode when value starts with '[' or '{' AND parses
	 *
	 * `null` always returns `null` regardless of `$schemaType`.
	 *
	 * @param mixed $value The raw column value as returned by the DB driver.
	 * @param string $schemaType The declared JSON-Schema property type.
	 *
	 * @return mixed The schema-typed PHP value.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-13
	 */
	public function convertValue(mixed $value, string $schemaType): mixed {
		// Null is preserved across all schema types - see spec scenario "Null integer column".
		if ($value === null) {
			return null;
		}

		return match ($schemaType) {
			'array', 'object' => $this->convertArrayOrObject(value: $value),
			'number' => $this->convertNumber(value: $value),
			'integer' => $this->convertInteger(value: $value),
			'boolean' => $this->convertBoolean(value: $value),
			// Extended field types `color` and `recurrence` are typed strings:
			// persisted and returned verbatim via the string path. The `recurrence`
			// `_occurrences` enrichment is materialised by the render layer
			// (RenderObject), not here, since it is a sibling virtual field on the
			// parent object rather than a transform of the value itself.
			'color', 'recurrence' => $this->convertString(value: $value, schemaType: $schemaType),
			default => $this->convertString(value: $value, schemaType: $schemaType),
		};
	}//end convertValue()

	/**
	 * Convert a value to the `string` schema type (and the unknown-type fallback).
	 *
	 * Strings are passed through. Numeric scalars are cast via `(string)`. Strings
	 * that look like JSON arrays/objects (start with `[` or `{` and parse) are
	 * decoded for backward compatibility with schemas that historically declared
	 * `type: string` but stored array/object data; the same compatibility window
	 * the previous `MagicSearchHandler::convertStringValue` provided.
	 *
	 * Crucially, scalar JSON literals (`"123"`, `"true"`, `"null"`, `'"foo"'`)
	 * are NOT decoded — that was the regression in `MagicStatisticsHandler` this
	 * change fixes.
	 *
	 * @param mixed $value The value to convert.
	 * @param string $schemaType The declared schema type (kept for symmetry; unused).
	 *
	 * @return mixed Converted value.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-13
	 */
	private function convertString(mixed $value, string $schemaType): mixed {
		if (is_string($value) === true) {
			$trimmed = trim($value);
			$startsWithArrObj = (
				str_starts_with($trimmed, '[') === true
				|| str_starts_with($trimmed, '{') === true
			);

			if ($startsWithArrObj === true) {
				$decoded = json_decode($value, true);
				if (json_last_error() === JSON_ERROR_NONE && ($decoded !== null || $value === 'null')) {
					return $decoded;
				}
			}

			return $value;
		}

		// Schema expects string but database returned numeric - cast to string.
		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return $value;
	}//end convertString()

	/**
	 * Re-encode the string-typed properties that the read path decoded.
	 *
	 * This is the inverse of `convertString()`'s decode branch, and it lives
	 * beside it deliberately: a decode with its encode written somewhere else
	 * drifts, and that drift is the defect this closes. `convertString()`
	 * decodes any `type: string` value that looks like a JSON array or object,
	 * so a read hands back an ARRAY where the schema declares a string. Every
	 * read-merge-save cycle then feeds that array straight back into validation,
	 * which refuses it — so a PATCH that never mentioned the property fails
	 * because of it, and the "a key absent from the payload leaves the stored
	 * value untouched" promise breaks.
	 *
	 * WHICH KEYS. Only the ones the caller did NOT supply. `$suppliedKeys` are
	 * skipped on purpose: this method restores the shape of values nobody
	 * touched, which is the promise being repaired. A caller that deliberately
	 * passes an array for a `type: string` property still gets the same loud
	 * validation refusal it gets today. Silently rewriting a value the caller
	 * chose would replace a visible refusal with an invisible transform, which
	 * is the worse of the two failures.
	 *
	 * WHICH TYPES. Every declared type that `convertValue()` routes to
	 * `convertString()` — so `string`, the extended field types, and anything
	 * unknown, but never `array`, `object`, `number`, `integer` or `boolean`. A
	 * union that admits `array` or `object` is left alone: the array form is
	 * valid there, so there is nothing to restore.
	 *
	 * WHICH DEPTH. Top level only, because that is the only depth the read
	 * decodes: the magic-table read applies `convertValue()` once per column,
	 * i.e. once per declared property. A `type: string` nested inside an
	 * `object` property was never decoded and must not be encoded here.
	 *
	 * A non-array value is left untouched — there is nothing to re-encode — and
	 * so is a value `json_encode()` refuses. Both fall through to validation,
	 * which is the failure direction this method must preserve: loud, not
	 * silent.
	 *
	 * @param array $data         The object data about to be saved.
	 * @param array $properties   The schema's property definitions, keyed by property name.
	 * @param array $suppliedKeys Keys the caller actually sent; these are left exactly as merged.
	 *
	 * @return array The data with untouched string-typed JSON values restored to their stored form.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function restoreStringTypedValues(array $data, array $properties, array $suppliedKeys=[]): array {
		foreach ($data as $key => $value) {
			if (is_array($value) === false) {
				continue;
			}

			if (in_array($key, $suppliedKeys, true) === true) {
				continue;
			}

			if (array_key_exists($key, $properties) === false || is_array($properties[$key]) === false) {
				continue;
			}

			if ($this->isStringTyped(declaredType: ($properties[$key]['type'] ?? 'string')) === false) {
				continue;
			}

			// Flags chosen to match what wrote these values in the first place:
			// JavaScript's JSON.stringify escapes neither slashes nor non-ASCII,
			// and consuming apps store `JSON.stringify(...)` into these columns.
			$encoded = json_encode($value, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			if ($encoded === false) {
				continue;
			}

			$data[$key] = $encoded;
		}//end foreach

		return $data;
	}//end restoreStringTypedValues()

	/**
	 * Whether a declared schema type is one `convertValue()` routes to `convertString()`.
	 *
	 * Mirrors the dispatch table in `convertValue()` rather than listing the
	 * string-ish types: the decode happens in the `default` arm, so the set is
	 * "everything that is not one of the five typed arms". `null` members of a
	 * union are ignored, since `type: ['string', 'null']` is still a string
	 * property; a union of nothing but `null` is not.
	 *
	 * @param mixed $declaredType The schema's declared type: a string, or a union as an array.
	 *
	 * @return bool True when a value of this type would have been JSON-decoded on read.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function isStringTyped(mixed $declaredType): bool {
		$types = $declaredType;
		if (is_array($types) === false) {
			$types = [$types];
		}

		$named = [];
		foreach ($types as $type) {
			if (is_string($type) === false || strtolower($type) === 'null') {
				continue;
			}

			$named[] = strtolower($type);
		}

		if ($named === []) {
			return false;
		}

		foreach ($named as $type) {
			if (in_array($type, ['array', 'object', 'number', 'integer', 'boolean'], true) === true) {
				return false;
			}
		}

		return true;
	}//end isStringTyped()

	/**
	 * Convert a value to the `boolean` schema type.
	 *
	 * Native bools pass through. Strings `'true'`, `'1'`, `'yes'` are truthy
	 * (case-insensitive); every other string is falsy. Anything else falls back
	 * to PHP's `(bool)` cast, so int `0` becomes `false`, int `1` becomes `true`,
	 * `0.0` becomes `false`, and so on.
	 *
	 * Mirrors `MagicSearchHandler::convertBooleanValue` exactly. Per design D7,
	 * the HTML-form literal `'on'` is intentionally NOT accepted — form
	 * normalisation belongs in the controller layer.
	 *
	 * @param mixed $value The value to convert.
	 *
	 * @return bool Coerced boolean.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-13
	 */
	private function convertBoolean(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return in_array(strtolower($value), ['true', '1', 'yes'], true);
		}

		return (bool)$value;
	}//end convertBoolean()

	/**
	 * Convert a value to the `integer` schema type.
	 *
	 * `is_numeric` inputs cast to `(int)`. Non-numeric inputs pass through
	 * unchanged so the JSON-Schema validator can flag them at a higher layer.
	 *
	 * @param mixed $value The value to convert.
	 *
	 * @return mixed Integer value or original on non-numeric input.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-13
	 */
	private function convertInteger(mixed $value): mixed {
		if (is_numeric($value) === true) {
			return (int)$value;
		}

		return $value;
	}//end convertInteger()

	/**
	 * Convert a value to the `number` schema type.
	 *
	 * `is_numeric` inputs cast to `(float)`. Non-numeric inputs pass through
	 * unchanged for downstream validation.
	 *
	 * @param mixed $value The value to convert.
	 *
	 * @return mixed Float value or original on non-numeric input.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-13
	 */
	private function convertNumber(mixed $value): mixed {
		if (is_numeric($value) === true) {
			return (float)$value;
		}

		return $value;
	}//end convertNumber()

	/**
	 * Convert a value to the `array` or `object` schema type.
	 *
	 * Strings are JSON-decoded. Already-array values pass through. Strings that
	 * fail to parse return unchanged so the JSON-Schema validator can flag them.
	 *
	 * @param mixed $value The value to convert.
	 *
	 * @return mixed Decoded array, original array, or original string.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-13
	 */
	private function convertArrayOrObject(mixed $value): mixed {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				return $decoded;
			}
		}

		return $value;
	}//end convertArrayOrObject()
}//end class
