---
status: in-progress
---

# Schema-Driven Read Coercion

## Purpose
Defines how OpenRegister coerces database column values to JSON-Schema-typed PHP values when reconstructing an `ObjectEntity` from a magic-table row. Establishes a single canonical converter (`SchemaTypeConverter`) that all read paths delegate to, eliminating the class of bugs where the schema declares one type but the API returns another — `boolean` properties arriving as `int 0`/`1` from MariaDB, or `string` properties whose values look like JSON literals being silently decoded back into the original primitive.

**OpenSpec changes**
- `fix-magic-table-type-coercion` (active) — introduces the `SchemaTypeConverter` service, refactors both `MagicStatisticsHandler::convertRowToObjectEntity` and `MagicSearchHandler::convertRowToObjectEntity` to delegate to it, and pins the contract with unit + integration tests.
## Requirements
### Requirement: Read coercion is governed by the active change
While this capability is in-progress, normative requirements MUST be sourced from the active change `fix-magic-table-type-coercion` under `openspec/changes/`. Implementers MUST treat this canonical spec as a placeholder until the change is archived and its delta is merged here.

#### Scenario: Implementer needs the canonical contract
- **WHEN** an implementer needs the normative behavior for schema-driven read coercion
- **THEN** they MUST consult the active change `fix-magic-table-type-coercion`
- **AND** they MUST NOT rely on this placeholder body for normative behavior

_Requirements for this capability are introduced by the active change above and will be merged here on archive._

### Requirement: Single shared schema-type converter on read

The system SHALL provide a single `SchemaTypeConverter` service in `lib/Service/Object/` whose `convertValue(mixed $value, string $schemaType): mixed` method is the only place that converts a database column value to a JSON-Schema-typed PHP value.

Both magic-table read paths — `MagicStatisticsHandler::convertRowToObjectEntity` (single-object find, UNION search across schemas, cross-table lookup) and `MagicSearchHandler::convertRowToObjectEntity` (search) — MUST delegate every per-property conversion to this service. Inline per-type converters (`convertValueByType`, `convertStringValue`, `convertBooleanValue`, `convertNumberValue`, `convertIntegerValue`, `convertArrayOrObjectValue`) MUST NOT remain on either handler after this change lands.

#### Scenario: Single source of truth for read coercion

- **WHEN** a developer searches the codebase for type-conversion logic that runs against a magic-table row
- **THEN** the only implementation found is `SchemaTypeConverter::convertValue` and its private helpers
- **AND** both handlers reference the converter via constructor-injected service

#### Scenario: Both read paths produce identical output for identical input

- **WHEN** the same `(value, schemaType)` pair is passed to both `MagicStatisticsHandler` and `MagicSearchHandler` row converters
- **THEN** the resulting property value on the `ObjectEntity` is identical in both PHP type and content

### Requirement: `string` properties always return PHP `string`

When the schema declares a property as `type: string`, the converter SHALL return a PHP `string` for any non-null value. Numeric, boolean, and other scalar inputs MUST be cast via `(string) $value`. Inputs that are already strings MUST be returned unchanged unless they begin with `[` or `{` and parse as valid JSON, in which case they MAY be decoded for backward compatibility with schemas that historically stored array/object data under a `string` type.

#### Scenario: Numeric DB value coerces to string

- **WHEN** the schema property is `{ "type": "string" }` and the row contains the integer `45`
- **THEN** the returned property value is the string `"45"`

#### Scenario: Boolean-literal JSON is not decoded

- **WHEN** the schema property is `{ "type": "string" }` and the row contains the string `"true"`
- **THEN** the returned property value is the string `"true"` (not the boolean `true`)

#### Scenario: Null-literal JSON is not decoded

- **WHEN** the schema property is `{ "type": "string" }` and the row contains the string `"null"`
- **THEN** the returned property value is the string `"null"` (not PHP `null`, and the property is not silently dropped)

#### Scenario: Quoted-string JSON is not unwrapped

- **WHEN** the schema property is `{ "type": "string" }` and the row contains the string `'"foo"'` (six characters including the literal quotes)
- **THEN** the returned property value is the string `'"foo"'` with quotes intact

#### Scenario: Array-shaped string is decoded for backward compatibility

- **WHEN** the schema property is `{ "type": "string" }` and the row contains the string `'[1,2,3]'`
- **THEN** the returned property value is the array `[1, 2, 3]` (preserves historical behavior for schemas with mismatched type declarations)

### Requirement: `boolean` properties always return PHP `bool`

When the schema declares a property as `type: boolean`, the converter SHALL return a PHP `bool` for any non-null value. The conversion SHALL accept:

- native `bool` (returned unchanged),
- the strings `"true"`, `"1"`, `"yes"` (case-insensitive) → `true`; any other string → `false`,
- any other type → `(bool) $value` (so int `0` → `false`, int `1` → `true`).

#### Scenario: MariaDB TINYINT(1) result coerces to bool

- **WHEN** the schema property is `{ "type": "boolean" }` and the row contains the integer `1` (as returned by mysqlnd from a TINYINT(1) column)
- **THEN** the returned property value is PHP `true`

#### Scenario: MariaDB false coerces to bool

- **WHEN** the schema property is `{ "type": "boolean" }` and the row contains the integer `0`
- **THEN** the returned property value is PHP `false`

#### Scenario: PostgreSQL native bool passes through

- **WHEN** the schema property is `{ "type": "boolean" }` and the row contains the PHP boolean `true` (as returned by the PostgreSQL driver from a `BOOLEAN` column)
- **THEN** the returned property value is PHP `true`

#### Scenario: String "yes" coerces to bool

- **WHEN** the schema property is `{ "type": "boolean" }` and the row contains the string `"yes"`
- **THEN** the returned property value is PHP `true`

### Requirement: `integer` properties return PHP `int` for numeric input

When the schema declares a property as `type: integer`, the converter SHALL return a PHP `int` for any value that PHP's `is_numeric()` accepts. Non-numeric values are returned unchanged so that a downstream JSON-Schema validator can reject them.

#### Scenario: Numeric string coerces to int

- **WHEN** the schema property is `{ "type": "integer" }` and the row contains the string `"42"`
- **THEN** the returned property value is the integer `42`

#### Scenario: Already-integer passes through

- **WHEN** the schema property is `{ "type": "integer" }` and the row contains the integer `42`
- **THEN** the returned property value is the integer `42`

### Requirement: `number` properties return PHP `float` for numeric input

When the schema declares a property as `type: number`, the converter SHALL return a PHP `float` for any value that PHP's `is_numeric()` accepts.

#### Scenario: Integer DB value coerces to float

- **WHEN** the schema property is `{ "type": "number" }` and the row contains the integer `7`
- **THEN** the returned property value is the float `7.0`

#### Scenario: Decimal string coerces to float

- **WHEN** the schema property is `{ "type": "number" }` and the row contains the string `"3.14"`
- **THEN** the returned property value is the float `3.14`

### Requirement: `array` and `object` properties are JSON-decoded only under their schema type

When the schema declares a property as `type: array` or `type: object`, the converter SHALL JSON-decode any string-shaped value and return the resulting PHP array. Values that are already arrays MUST be returned unchanged. Strings that fail to decode MUST be returned as the original string so the JSON-Schema validator can flag them.

JSON decoding MUST NOT run for any other schema type. The converter MUST NOT contain a fall-through "if value is JSON, decode it" path.

#### Scenario: JSON-string array column is decoded

- **WHEN** the schema property is `{ "type": "array" }` and the row contains the string `'[1,2,3]'`
- **THEN** the returned property value is the PHP array `[1, 2, 3]`

#### Scenario: Already-array column passes through

- **WHEN** the schema property is `{ "type": "object" }` and the row already holds the PHP array `['key' => 'value']` (e.g. from a JSON column the driver pre-decoded)
- **THEN** the returned property value is the PHP array `['key' => 'value']` unchanged

#### Scenario: Numeric-looking value under string type is not decoded

- **WHEN** the schema property is `{ "type": "string" }` and the row contains the string `"123"`
- **THEN** the returned property value is the string `"123"` and is NOT passed through `json_decode`

### Requirement: `null` is preserved across all schema types

When the row value is `null`, the converter SHALL return `null` regardless of the schema type. No coercion runs on a `null` input.

#### Scenario: Null integer column

- **WHEN** the schema property is `{ "type": "integer" }` and the row contains `null` (nullable column with no value)
- **THEN** the returned property value is `null`

#### Scenario: Null boolean column

- **WHEN** the schema property is `{ "type": "boolean" }` and the row contains `null`
- **THEN** the returned property value is `null` (NOT `false`)

### Requirement: Unknown schema types fall through unchanged

When the schema declares a property with an unrecognized `type` (e.g. a typo, a custom type, or an empty string), the converter SHALL apply the same backward-compatible handling as the `string` branch — pass strings through and only attempt JSON decoding if the value begins with `[` or `{`.

#### Scenario: Unknown type passes the value through

- **WHEN** the schema property has `{ "type": "mystery" }` and the row contains the string `"hello"`
- **THEN** the returned property value is the string `"hello"`

### Requirement: Read-side format handling stays in the handler

Format-specific normalization (e.g. `format: date`, `format: date-time`) SHALL NOT be the converter's responsibility. The handler that calls the converter MUST continue to apply its existing format normalization (via `DateTimeNormalizer`) after the converter has produced the schema-typed value.

#### Scenario: Date-formatted string column

- **WHEN** the schema property is `{ "type": "string", "format": "date" }` and the row contains the string `"2026-04-30T10:00:00+02:00"`
- **THEN** the converter returns the string unchanged
- **AND** the handler applies `DateTimeNormalizer::normalize` and produces `"2026-04-30"` on the resulting `ObjectEntity`

