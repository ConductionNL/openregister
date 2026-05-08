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
 * @category  Service
 * @package   OCA\OpenRegister\Service\Object
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
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
class SchemaTypeConverter
{
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
     * @param mixed  $value      The raw column value as returned by the DB driver.
     * @param string $schemaType The declared JSON-Schema property type.
     *
     * @return mixed The schema-typed PHP value.
     */
    public function convertValue(mixed $value, string $schemaType): mixed
    {
        // Null is preserved across all schema types - see spec scenario "Null integer column".
        if ($value === null) {
            return null;
        }

        return match ($schemaType) {
            'array', 'object' => $this->convertArrayOrObject(value: $value),
            'number'          => $this->convertNumber(value: $value),
            'integer'         => $this->convertInteger(value: $value),
            'boolean'         => $this->convertBoolean(value: $value),
            default           => $this->convertString(value: $value, schemaType: $schemaType),
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
     * @param mixed  $value      The value to convert.
     * @param string $schemaType The declared schema type (kept for symmetry; unused).
     *
     * @return mixed Converted value.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function convertString(mixed $value, string $schemaType): mixed
    {
        if (is_string($value) === true) {
            $trimmed          = trim($value);
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
            return (string) $value;
        }

        return $value;
    }//end convertString()

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
     */
    private function convertBoolean(mixed $value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_string($value) === true) {
            return in_array(strtolower($value), ['true', '1', 'yes'], true);
        }

        return (bool) $value;
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
     */
    private function convertInteger(mixed $value): mixed
    {
        if (is_numeric($value) === true) {
            return (int) $value;
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
     */
    private function convertNumber(mixed $value): mixed
    {
        if (is_numeric($value) === true) {
            return (float) $value;
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
     */
    private function convertArrayOrObject(mixed $value): mixed
    {
        if (is_string($value) === true) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }//end convertArrayOrObject()
}//end class
