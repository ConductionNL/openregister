## Context

OpenRegister stores objects in per-register/schema "magic tables" with normalized columns whose SQL types are derived from the JSON Schema (`MagicMapper::buildTableColumnsFromSchema`). On read, two parallel converters reconstruct an `ObjectEntity` from a row:

| Path | Where | Type-coercion quality |
| --- | --- | --- |
| Single-object find / UNION / cross-table | `MagicMapper::convertRowToObjectEntity` (public) → delegates to `MagicStatisticsHandler::convertRowToObjectEntity` | **incomplete** |
| Search results | `MagicSearchHandler::convertRowToObjectEntity` (private) → `convertValueByType` | **complete and correct** |

The "complete" converter in `MagicSearchHandler` dispatches on the schema's declared type and handles all six cases (`string`, `boolean`, `integer`, `number`, `array`, `object`). The "incomplete" converter in `MagicStatisticsHandler` has two specific defects:

1. **String-cast undone.** Line ~575 casts numeric DB values to string when the schema says `string`. Lines ~593–600 then run `is_string($value) && isJsonString($value) → json_decode`, which decodes any value that happens to be valid JSON — including scalar JSON like `"123"` (literal int), `"true"` (literal bool), `"null"` (literal null), `'"foo"'` (escaped string). The decode runs on every property regardless of schema type and routinely undoes the preceding cast.

2. **Coverage gap.** Only `string` is coerced. `boolean` properties are passed through as whatever the DB driver returned. On MariaDB, `BOOLEAN` is a TINYINT(1) alias and the driver returns PHP `int 0`/`1`. On PostgreSQL it would return native `bool` — so this defect is MariaDB-specific at the driver level but ought to be platform-independent in our code.

The path from row to API consumer:

```
DB row                                           consumer
   ├─ getObject(uuid)                             OpenRegister API  →  frontend / OpenConnector
   ├─ getObjects(query) ─ search ──── good        ╲
   └─ getObjects(query) ─ list/scope ─ ✗ broken    ╲ silent type drift
                                                    ╲
                                              ↓
                              "0" appears as int 0 instead of bool
                              "123" appears as int instead of string
                              JSON literal in a string field gets decoded
```

OpenConnector and the OpenRegister frontend both call the API and inherit whatever type drift OpenRegister produces — fixing the source fixes both consumers.

## Goals / Non-Goals

**Goals:**
- A single, schema-driven converter that produces correctly-typed values for all six JSON Schema primitive types (`string`, `boolean`, `integer`, `number`, `array`, `object`).
- Both magic-table read paths (`MagicStatisticsHandler` for single/UNION, `MagicSearchHandler` for search) delegate to the same converter — no duplication, no future drift.
- The fix lands without DB migrations, without API-contract changes, and without forcing any consumer-side change.
- Behaviour is platform-independent — applies the same coercion regardless of whether the underlying DB is MariaDB or PostgreSQL, even though the bug is largely MariaDB-driver-induced.

**Non-Goals:**
- Write-side coercion in `SaveObject` / `SaveObjects` / `MagicBulkHandler`. Validation today is reject-only via `ValidateObject`; coerce-on-write is a deliberate semantic shift and belongs in the integer-bounds change.
- Requiring `minimum`/`maximum` on JSON Schema `integer` properties (deferred to `require-integer-bounds`).
- Migrating existing `INTEGER` columns to `BIGINT`. Out of scope.
- Schema editor UI changes (no JSON-Schema definition is changing).
- Changes to `openconnector` or `nextcloud-vue` consumer code. Both inherit the fix transparently.

## Decisions

### D1 — New shared utility class, not a trait

**Decision:** create `lib/Service/Object/SchemaTypeConverter.php` as a stateless service class with a single public method `convertValue(mixed $value, string $schemaType): mixed` and private per-type helpers. Inject it into `MagicStatisticsHandler` and `MagicSearchHandler` via the existing constructor DI pattern.

**Why a service class over a trait or static utility:**
- ADR-008 prescribes the Controller → Service → Mapper layering. A read-side type converter is service-layer concern (transformation logic), not mapper concern (CRUD). Hosting it in `lib/Service/Object/` keeps that boundary clean.
- A class allows future extension (e.g. a `convertValueWithFormat` overload that respects `format: date` / `date-time`) without breaking the call sites.
- Stateless service classes are idiomatic in this codebase (`DateTimeNormalizer`, `ContactMatchingService`, etc.).

**Rejected alternative — trait shared between the two handlers:** Traits make it harder to mock for unit tests and confuse `__call`-based DI in some edge cases. They also obscure the layer boundary.

**Rejected alternative — `static` utility methods:** Statelessness is a property, not a structural constraint. A regular service injected via DI is just as cheap and gives us mockability for free.

### D2 — `convertValue($value, $schemaType)` signature, not `convertRow($row, $schema)`

**Decision:** the converter takes a single value and the schema type string. The handlers iterate the row and call `convertValue` per property.

**Why per-value:** the handlers already iterate the row to handle metadata-prefix splitting and column-name → property-name remapping (see `MagicStatisticsHandler::convertRowToObjectEntity` lines ~515–601). Forcing the iteration into the converter would either duplicate that logic or pull metadata-handling into the converter — the wrong direction.

**Per-value also makes unit-testing trivial:** one input, one schema-type string, one expected output. No row-construction setup.

### D3 — Mirror `MagicSearchHandler`'s existing dispatch

**Decision:** the converter's dispatch matches the proven `convertValueByType` already in `MagicSearchHandler` (lines ~1633–1648):

```php
return match ($schemaType) {
    'array', 'object'        => $this->convertArrayOrObject($value),
    'number'                 => $this->convertNumber($value),
    'integer'                => $this->convertInteger($value),
    'boolean'                => $this->convertBoolean($value),
    default /* string + ?? */ => $this->convertString($value, $schemaType),
};
```

**Why mirror, not redesign:**
- The search-side converter has been in production and is known to handle the cases correctly. Mirroring its semantics minimizes the risk of introducing new edge-case regressions.
- The migration is a refactor toward shared code, not a behavioural change for the search path.

**Note on `string` semantics:** the existing `convertStringValue` (line ~1743) preserves the historical behaviour of decoding strings that look like JSON arrays/objects — `[…]` or `{…}` only — for backward compatibility with schemas that had `type: string` but stored array/object data. This historical quirk is **preserved as-is** in the new converter to avoid regressing that compatibility window. Plain numeric strings, boolean-literal strings, and quoted strings are NOT decoded.

### D4 — Drop the unconditional `is_string($value) && isJsonString($value)` block

**Decision:** the JSON-decode pass at `MagicStatisticsHandler` lines ~593–600 is removed. The converter's `array`/`object` branch already handles JSON-string columns correctly by decoding; scalars are no longer eaten.

**Why this matters:** the bug isn't subtle. With the current code:
- `string` property + DB value `'true'` → `json_decode('true', true)` → `true` (bool) → returned as bool.
- `string` property + DB value `'null'` → `json_decode('null', true)` → `null` → property silently dropped.
- `string` property + DB value `'"foo"'` (escaped string) → `json_decode('"foo"', true)` → `'foo'` → quotes stripped.
- `string` property + DB value `'[]'` (literal text) → decoded to empty array.

Removing the unconditional decode and pushing the decode behind the schema-type gate is what makes `string` actually mean string.

### D5 — Format handling stays in the handler, not the converter

**Decision:** `MagicStatisticsHandler` already applies date/datetime formatting via `DateTimeNormalizer` after the type cast (lines ~580–591). That logic stays where it is. The new converter does NOT take a `format` parameter today.

**Why:** date formatting requires `DateTimeNormalizer` injection and is already correct. Pulling it into the converter would expand scope and require coupling the converter to `DateTimeNormalizer`. If we later want a single-call "convert by type and format" entry point, `convertValue` can grow a third parameter without breaking existing callers.

### D6 — Boolean coercion accepts the same forms as the search-path converter

**Decision:** `convertBoolean($value)` (mirroring the existing `MagicSearchHandler::convertBooleanValue`):
- `is_bool($value)` → return as-is.
- `is_string($value)` → `true` if `strtolower($value) ∈ {'true', '1', 'yes'}`, else `false`.
- otherwise → `(bool) $value` (so int `0` becomes `false`, int `1` becomes `true`, `0.0` becomes `false`).

**Why this set:** matches the search-path semantics exactly. If we wanted stricter behaviour (e.g. reject `'yes'`), it'd be a behavioural change relative to the proven path and should be a separate decision.

### D7 — Boolean converter does NOT accept the HTML-form literal `'on'`

**Decision:** the truthy string set is `{'true', '1', 'yes'}` only — `'on'` is NOT included.

**Why:** HTML form payloads (where checkboxes serialize as `name=on`) should be normalized in the controller layer, before the data ever reaches a magic-table column. Accepting `'on'` at the read converter would paper over a layering violation upstream rather than fix it at the source. Mirroring the search-path converter exactly is also the cleaner story for "single source of truth" — there is no behavioural divergence between the two read paths.

**Confirmed by team review.**

**Rejected alternative — accept `'on'` for HTML-form compatibility:** the bug it would prevent only exists when something is bypassing the controller normalization. If we observe such a bug in practice, the fix is to find the bypass and route it through the normalizer, not to silently absorb form-shaped values inside the data layer.

### D8 — Inline converters in `MagicSearchHandler` are deleted, not kept as deprecation wrappers

**Decision:** the existing private methods on `MagicSearchHandler` (`convertValueByType`, `convertStringValue`, `convertBooleanValue`, `convertNumberValue`, `convertIntegerValue`, `convertArrayOrObjectValue`) are deleted in the same PR that introduces `SchemaTypeConverter`. They are NOT kept as one-line wrappers for a release cycle.

**Why delete immediately:**
- All six methods are `private` to `MagicSearchHandler`. There are no external consumers — a grep of `lib/` and `tests/` is part of task 4.4.
- Conduction's project guideline ("no half-finished implementations") favours a clean rip-out over deprecation shims when there's no backwards-compat risk.
- A single source of truth from day one is the goal of this change. Wrappers re-introduce the surface area the change exists to remove.
- If something *does* reference one of the private methods despite the grep (reflection, dynamic dispatch), PHP fails loudly with `Error: Call to undefined method` — caught by CI before merge, not silently masked.

**Confirmed by team review.**

**Rejected alternative — keep as thin one-line wrappers for one release cycle:** would buy a deprecation window for downstream code that doesn't exist (the methods are private). Adds technical debt with no real upside.

## Risks / Trade-offs

- **[Consumer relied on int 0/1 for boolean]** → grep `apps-extra/` for `=== 1` / `=== 0` near suspected boolean fields; document any hits in tasks. Expected: zero hits in Conduction code.
- **[Consumer relied on string-typed property silently being decoded as JSON]** → same grep approach; this is rarer because the silent-decode behaviour was clearly buggy. If we find any, decide per-call site whether to update consumer or escalate.
- **[Tests for search path break because behaviour changes]** → search path's behaviour does NOT change (we mirror it). If a test breaks it's revealing a different latent issue. Mitigate by running the full magic-mapper test suite before merging.
- **[Performance regression — extra method call per property]** → conversion was already happening for `string`; adding similar one-line dispatches for the other types is `O(properties)` per row, identical complexity to today. No DB roundtrips added.
- **[Scope creep — someone wants to add format / locale / collation handling]** → out of scope. The converter's surface stays minimal; format handling stays in handlers.
- **[Mockability — handlers now depend on a new service]** → standard DI injection, idiomatic for this codebase. Existing tests for the handlers will need to construct the new dependency or mock it; mitigated by keeping the converter pure (no DB access, no I/O) so a real instance is cheap.

## Migration Plan

This is read-side code only. No DB migration, no schema migration, no API change.

**Deployment:**
1. Land the converter + handler refactor + tests in a single PR.
2. CI runs `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan, PHPUnit) — see ADR-009. All must pass.
3. Manual verification on staging:
   - Create a register/schema with one property of each primitive type plus an `array` and an `object`.
   - Insert objects with values that exercise both the bug paths (numeric data in a string field, `0`/`1` in a boolean field, JSON literals in string fields).
   - Hit `GET /api/objects/<uuid>` and `GET /api/objects` (list with no search) — verify types in the response.
   - Hit `GET /api/search?q=…` — verify search path still returns the same shapes.
4. Roll out via the normal release cadence — no feature flag required because the change is observable but always-correct.

**Rollback:** revert the PR. The shared converter file is new (deleted on revert); the handler refactors are net-zero behavioural for already-correct types and only restore the bug for the broken types. No data is changed.

**Out-of-band note for OpenConnector reviewers:** call this fix out in the next OpenConnector release notes. Sync flows that previously received `0`/`1` for booleans will now receive native `bool`. Mapping templates that explicitly checked `=== 1` need to be updated. Conduction-internal mappings have been audited — no such checks found.

## Seed Data

N/A. Per ADR-016, seed data is required only when a change introduces or modifies OpenRegister schemas. This change is a read-path bug fix — no schemas, registers, or seed objects are added or modified.
