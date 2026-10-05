---
status: done
---

# Schema-Driven Read Coercion

## Purpose

@e2e exclude backend type coercion service — covered by PHPUnit
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

### Requirement: A decoded `string` property is restored before a write

The decode above is only safe if it has an inverse. A read hands back an array for a `type: string` property, so any read-merge-save cycle feeds that array into validation, which refuses it. The converter SHALL therefore expose `restoreStringTypedValues()`, and every merging write path (`ObjectService::patchObject()`, `ObjectsController::patch()`, `ObjectsController::postPatch()`) SHALL call it after its merge and before its save.

@e2e exclude backend write-path symmetry, covered by PHPUnit and by a live probe against both patch doors.

The restore SHALL apply only to keys the caller did NOT supply, and only to declared types the converter routes through its string path. It MUST encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, so the stored bytes match what `JSON.stringify()` wrote. It MUST NOT descend below the top level, because the read decodes one column per declared property and nothing deeper. Where it cannot answer confidently it MUST return the data unchanged, leaving the validation refusal in place rather than guessing.

#### Scenario: A patch that never mentions the property leaves it as stored

- **WHEN** a `{ "type": "string" }` property holds `'[{"status":"open"}]'` and a caller patches only `title`
- **THEN** the save receives that property as the string `'[{"status":"open"}]'`, byte-identical to what was stored

#### Scenario: An array the caller supplied is refused, not rewritten

- **WHEN** a caller patches a `{ "type": "string" }` property with an array value
- **THEN** the array reaches validation unchanged and the write is refused with a type error

#### Scenario: A property the schema really calls an array is untouched

- **WHEN** a `{ "type": "array" }` property holds `["a", "b"]` and a caller patches only `title`
- **THEN** the save receives that property as the array `["a", "b"]`

#### Scenario: A union that admits arrays is untouched

- **WHEN** a property declares `{ "type": ["string", "array"] }` and holds an array
- **THEN** the value is left as an array, because the array form is valid there

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

### Requirement: Object display-name cache resolution endpoints
The system SHALL expose cached endpoints that resolve object and organisation UUIDs to
their display names so frontends can render names instead of raw UUIDs.
`NamesController` provides `index` (`GET /api/names`: all names, or a subset via an
`ids` query param that accepts a comma-separated string, a JSON-array string, or a PHP
array) and `create` (`POST /api/names` with a JSON `ids` array for sets too large for
the URL). Both require a logged-in user (`#[NoAdminRequired]`, `#[NoCSRFRequired]`,
HTTP 401 without a session) and return execution timing in the response.

A caller MUST get the name of every object they may read, and MUST get nothing for an
object they may not read. Whether the caller may read an object SHALL be decided by the
object read path itself (the RBAC and multitenancy filter of
`GET /api/objects/{register}/{schema}/{id}`), never by a separate rule of the name
cache. An organisation's name SHALL be returned only within the caller's organisation
scope (the active organisation and its parents). The answer is a partial map: a name the
caller may not see is absent, never null and never an error. The same rule SHALL apply
to names served from the in-memory and the distributed cache, and to every internal
caller of the name lookup.

#### Scenario: Bulk name lookup by ids query param
- **GIVEN** a caller who may read the objects `uuid-1` and `uuid-2` requests `GET /api/names?ids=uuid-1,uuid-2`
- **WHEN** `NamesController::index` runs
- **THEN** it MUST return `{names: {uuid-1: ..., uuid-2: ...}, total, cached, execution_time}` from the cache handler

#### Scenario: POST bulk lookup requires an ids array
- **GIVEN** a caller POSTs a body without an `ids` array
- **WHEN** `NamesController::create` runs
- **THEN** it MUST return HTTP 400 with an example payload

#### Scenario: A non-admin gets the name of an object they may read
- **GIVEN** a non-admin whose active organisation does not own object `M`
- **AND** the schema of `M` grants `read` to a group the caller is in
- **WHEN** the caller POSTs `{"ids": ["M"]}` to `/api/names`
- **THEN** the response MUST contain the name of `M`

#### Scenario: A non-admin gets nothing for an object they may not read
- **GIVEN** a non-admin and an object `S` in the caller's own organisation whose schema grants `read` to admins only
- **WHEN** the caller POSTs `{"ids": ["S"]}` to `/api/names`
- **THEN** the response MUST NOT contain `S`

#### Scenario: Admin is unchanged
- **GIVEN** an admin and an object the admin may read
- **WHEN** the admin requests its name
- **THEN** the response MUST contain the name

#### Scenario: A cached name is only served to a caller who may read the object
- **GIVEN** the name of object `M` was cached while serving a caller who may read it
- **WHEN** a caller who may not read `M` requests its name, from the same process or from another one sharing the distributed cache
- **THEN** the response MUST NOT contain `M`

#### Scenario: A cache entry without a source is resolved again
- **GIVEN** a distributed-cache entry for `M` written before names followed read rights
- **WHEN** a caller requests the name of `M`
- **THEN** the entry MUST be treated as a miss and the name resolved from the database under the rule above

#### Scenario: Single name not found
- **GIVEN** a caller requests the name of an id that resolves to nothing, or to something they may not see
- **WHEN** `NamesController::index` or `NamesController::create` runs
- **THEN** the id MUST be absent from `names`, with HTTP 200 (the single-id route `GET /api/names/{id}` no longer exists)

#### Scenario: Manual cache warmup
- **GIVEN** an admin
- **WHEN** the admin calls `POST /api/settings/cache/warmup-names`
- **THEN** the name cache MUST be cleared and re-populated (the public `warmup` route on `NamesController` no longer exists)
