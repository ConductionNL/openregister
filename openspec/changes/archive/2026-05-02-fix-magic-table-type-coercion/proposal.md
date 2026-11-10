## Why

OpenRegister's API returns object-property values whose runtime types do not match the JSON Schema's declared property type. A property typed as `string` whose stored data is numeric comes back as a PHP `int`; a property typed as `boolean` comes back as `int 0`/`1`. Consumers (the OpenRegister frontend, OpenConnector sync mappings, downstream apps via the typed API) defensively cast to recover, or — more often — silently propagate the wrong type and break further downstream.

The schema is meant to be authoritative: a typed contract between producer and consumer. Today the magic-table read path violates that contract on every row.

## What Changes

- **Fix the over-eager JSON-decode** in `MagicStatisticsHandler::convertRowToObjectEntity`: the existing `is_string($value) && isJsonString($value)` block at lines ~593–600 decodes any string that happens to be valid JSON — including scalar JSON like `"123"`, `"true"`, `"null"`. This undoes the partial string-cast on the preceding lines and corrupts string properties whose values look like JSON literals.

- **Cover all schema types, not only `string`.** Today only `string` properties are coerced (lines ~575–578). `boolean`, `integer`, `number`, `array`, and `object` properties are returned as whatever the database driver produced — which on MariaDB means `boolean` → `int 0`/`1` and (in some drivers/configurations) numeric strings → `int`.

- **Unify the converter.** A schema-driven converter already exists for the search read path (`MagicSearchHandler::convertValueByType`) and handles all six types correctly. Extract that logic into a shared utility (`lib/Service/Object/SchemaTypeConverter.php`) and have both `MagicStatisticsHandler::convertRowToObjectEntity` (the public single-object/UNION path) and `MagicSearchHandler::convertRowToObjectEntity` (the search path) delegate to it. Single source of truth — no future drift between the two read paths.

- **Restrict JSON-decode to `array`/`object` schema types.** The decode is meant for columns that store nested structures as JSON text; gating it on the schema type instead of on `is_string($value)` prevents scalar-JSON corruption.

This change is **read-only**: write-side coercion is intentionally **out of scope** and will be tackled together with the integer-bounds-required design in the follow-up change `require-integer-bounds`.

## Capabilities

### New Capabilities
- `schema-driven-read-coercion`: When the magic-table read path produces an `ObjectEntity` from a row, every property's runtime PHP type matches its schema-declared type. Single converter is the source of truth across all read paths.

### Modified Capabilities
<!-- None — no existing spec covers row-to-object type coercion. -->

## Impact

**Code (OpenRegister):**
- `lib/Db/MagicMapper/MagicStatisticsHandler.php` — replace the partial coercion + over-eager JSON-decode with a delegated call to the shared converter.
- `lib/Db/MagicMapper/MagicSearchHandler.php` — replace the inline `convertValueByType` / `convertStringValue` / `convertNumberValue` / `convertIntegerValue` / `convertBooleanValue` / `convertArrayOrObjectValue` methods with a delegated call to the shared converter.
- `lib/Service/Object/SchemaTypeConverter.php` — **new** utility class. Single public method `convertValue(mixed $value, string $schemaType): mixed` plus type-specific helpers; no instance state, easy to unit-test.

**Tests (OpenRegister):**
- `tests/Unit/Service/Object/SchemaTypeConverterTest.php` — **new**. Cover each of the six schema types with normal cases plus the regression suite: `string` + `123` (int), `string` + `"true"` (must stay literal `'true'`), `string` + `"null"` (must stay literal `'null'`), `string` + `'"foo"'` (no quote-stripping), `boolean` + `0`/`1` (becomes `false`/`true`), `boolean` + `"true"`/`"yes"`/`"1"` (also `true`), `integer` + numeric string, `number` + int, `array` + JSON string, `object` + JSON string, all-types + `null`.
- Integration check that both `MagicStatisticsHandler` and `MagicSearchHandler` delegate (mock the shared converter or assert observable behaviour through `convertRowToObjectEntity`).

**Downstream apps (no code changes required):**
- **OpenConnector** — register-backed sync flows will start receiving correctly-typed values from OpenRegister's API. Any defensive `(string)` / `(bool)` casts in OC become no-ops; no regressions expected. Out of scope here; mention in the rollout note.
- **Frontend (`@conduction/nextcloud-vue`, openregister UI)** — `CnDataTable`, form widgets, and any explicit type checks that previously had to handle `0`/`1` for booleans no longer need to. Out of scope; downstream cleanup can be a follow-up if desired.

**Backwards compatibility:**
- Consumers that **depend on** the broken behaviour (e.g. JS `value === 1` for "true") will break. We expect zero such cases in Conduction code; a grep across `apps-extra/` is part of the verification.
- No DB migration. No magic-table schema change. No API contract change at the spec level — this brings the runtime in line with the spec.

**Endpoint coverage:**

`MagicMapper::insertObjectEntity` (line 5066–5086) and `MagicMapper::updateObjectEntity` re-fetch the saved row via `findInRegisterSchemaTable` after writing — explicitly so the response body carries DB-generated metadata. That re-fetch goes through `convertRowToObjectEntity` → `MagicStatisticsHandler`, which means **POST and PUT response bodies are produced by the same path this change unifies**. After the fix, every endpoint that returns an `ObjectEntity` (single GET, list GET, search GET, POST create, PUT update) is schema-typed consistently.

| Endpoint | Reads `ObjectEntity` via | Affected by fix |
| --- | --- | :-: |
| `GET /api/objects/<uuid>` | `MagicStatisticsHandler::convertRowToObjectEntity` | ✓ |
| `GET /api/objects` (list) | `MagicStatisticsHandler::convertRowToObjectEntity` | ✓ |
| `GET /api/search` | `MagicSearchHandler::convertRowToObjectEntity` | ✓ (refactored to share converter) |
| `POST /api/objects` | re-fetch via `MagicStatisticsHandler::convertRowToObjectEntity` | ✓ |
| `PUT /api/objects/<uuid>` | re-fetch via `MagicStatisticsHandler::convertRowToObjectEntity` | ✓ |

**Out of scope (deferred to follow-up changes):**
- Write-side coercion in `SaveObject` / `SaveObjects` / `MagicBulkHandler`.
- Requiring `minimum`/`maximum` on JSON Schema integer properties (`require-integer-bounds`).
- `number` precision/scale defaults.
- Schema editor UI changes.
- Migration of existing magic-table `INTEGER` columns to `BIGINT`.
