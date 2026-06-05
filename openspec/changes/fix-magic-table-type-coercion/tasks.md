## 1. Pre-implementation audit

- [x] 1.1 Grep `apps-extra/` for code that depends on a boolean property arriving as int `0`/`1` (patterns: `=== 0`, `=== 1`, `=== '0'`, `=== '1'` near suspected boolean fields). Document any hits in this task list and flag for follow-up; expected zero hits in Conduction code.
- [x] 1.2 Grep `apps-extra/` for code that depends on a `string`-typed property silently being JSON-decoded (e.g. `if (is_array($obj['someStringField']))` patterns). Document any hits.
- [x] 1.3 Confirm the spec list in `proposal.md` matches the actual file shape: only `specs/schema-driven-read-coercion/spec.md` is created, no other specs touched.

## 2. Implement the shared converter

- [x] 2.1 Create `lib/Service/Object/SchemaTypeConverter.php` with public `convertValue(mixed $value, string $schemaType): mixed`.
- [x] 2.2 Implement private `convertString(mixed $value, string $schemaType): mixed` — passes strings through; only decodes strings starting with `[` or `{` when they are valid JSON (preserving the historical compatibility window from `MagicSearchHandler::convertStringValue`); casts `int` and `float` inputs to string.
- [x] 2.3 Implement private `convertBoolean(mixed $value): bool` — returns native bools unchanged; treats strings `"true"` / `"1"` / `"yes"` (case-insensitive) as `true`, every other string as `false`; falls back to `(bool) $value` for everything else.
- [x] 2.4 Implement private `convertInteger(mixed $value): mixed` — returns `(int) $value` when `is_numeric($value)`, otherwise returns the value unchanged for downstream validation.
- [x] 2.5 Implement private `convertNumber(mixed $value): mixed` — returns `(float) $value` when `is_numeric($value)`, otherwise unchanged.
- [x] 2.6 Implement private `convertArrayOrObject(mixed $value): mixed` — JSON-decodes string-shaped values; passes already-array values through; returns the original string when JSON decoding fails.
- [x] 2.7 Wire the dispatch in `convertValue` using a `match` on `$schemaType` that mirrors the table in design D3, with `null` short-circuited at the top.
- [x] 2.8 Add PHPDoc on the class and every method; include the `@SuppressWarnings(PHPMD.CyclomaticComplexity)` annotation on the dispatch method only if PHPMD flags it.

## 3. Refactor `MagicStatisticsHandler`

- [x] 3.1 Inject `SchemaTypeConverter` via the constructor (follow existing DI pattern; `services.xml` registration if applicable).
- [x] 3.2 In `convertRowToObjectEntity` (around lines 575–600), replace the `if ($schemaType === 'string' && (is_int($value) || is_float($value)))` block and the subsequent `is_string && isJsonString` decode block with a single call to `$this->schemaTypeConverter->convertValue($value, $schemaType)`.
- [x] 3.3 Keep the pre-existing format-handling block (`DateTimeNormalizer` for `format: date` / `date-time`) AFTER the converter call. The converter does not take a `format` argument.
- [x] 3.4 Delete the now-unused `isJsonString` private helper (line ~755) if no other call sites remain after the refactor; verify with grep.

## 4. Refactor `MagicSearchHandler`

- [x] 4.1 Inject `SchemaTypeConverter` via the constructor.
- [x] 4.2 In `convertRowToObjectEntity` (line ~1337), replace the `convertValueByType` call at line ~1375 with `$this->schemaTypeConverter->convertValue($value, $propertyType)`.
- [x] 4.3 Delete `convertValueByType`, `convertStringValue`, `convertNumberValue`, `convertIntegerValue`, `convertBooleanValue`, `convertArrayOrObjectValue` from `MagicSearchHandler` — they are private and the converter is now the source of truth.
- [x] 4.4 Search for any other callers of the deleted methods within `MagicSearchHandler.php` and the broader `lib/Db/MagicMapper/`; if found, retarget them to the converter.

## 5. Unit tests

- [x] 5.1 Create `tests/Unit/Service/Object/SchemaTypeConverterTest.php` with one test method per spec scenario.
- [x] 5.2 String coverage: `'45' (string)`, `45 (int)`, `4.5 (float)`, `'true' (string)`, `'null' (string)`, `'"foo"' (escaped)`, `'[1,2,3]'`, `'{"k":"v"}'`. Verify each produces the spec'd output.
- [x] 5.3 Boolean coverage: `0`, `1`, `'0'`, `'1'`, `true`, `false`, `'true'`, `'TRUE'`, `'yes'`, `'no'`, `'random string'`, `null`.
- [x] 5.4 Integer coverage: `'42'`, `42`, `'42.5'`, `'not a number'`, `null`.
- [x] 5.5 Number coverage: `7`, `'3.14'`, `'not a number'`, `null`.
- [x] 5.6 Array/object coverage: `'[1,2,3]'`, `'{"a":1}'`, `[1,2,3]` (already-array), `'not json'`, `null`.
- [x] 5.7 Unknown-type coverage: `'mystery'` schema type with `'hello'` string returns `'hello'`; with `'[1]'` returns `[1]` (matches the string fallback).
- [x] 5.8 Verify all tests pass: `docker exec nextcloud php /var/www/html/custom_apps/openregister/vendor/bin/phpunit tests/Unit/Service/Object/SchemaTypeConverterTest.php`.

## 6. Handler-level integration tests

- [x] 6.1 Update or extend tests for `MagicStatisticsHandler::convertRowToObjectEntity` (add file at `tests/Unit/Db/MagicMapper/MagicStatisticsHandlerTest.php` if not present): mock the converter and assert it's invoked once per non-metadata column, with the column value and the property's schema type.
- [x] 6.2 Update or extend tests for `MagicSearchHandler::convertRowToObjectEntity`: same mock-based assertion.
- [x] 6.3 Add a regression test for the date-format path: `format: date` property + raw string column → handler returns the formatted result, confirming the converter→DateTimeNormalizer ordering still holds.

## 7. Quality gates and verification

- [x] 7.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan, PHPUnit) — all must pass per ADR-009. Fix any pre-existing issues in touched files per project rules.
- [x] 7.2 Run the full magic-mapper test suite to confirm no regressions in adjacent code: `docker exec nextcloud php /var/www/html/custom_apps/openregister/vendor/bin/phpunit tests/Unit/Db/MagicMapper/`.
- [ ] 7.3 Manual API verification on a register/schema with one property of each primitive type: insert objects exercising the bug paths (numeric data in a string field, `0`/`1` in a boolean field, JSON-literal strings); call `GET /api/objects/<uuid>` and `GET /api/objects` (list); assert types in the JSON response.
- [ ] 7.4 Manual POST/PUT verification on the same register/schema: send a `POST /api/objects` and a `PUT /api/objects/<uuid>` and assert the **response body** carries schema-typed values (booleans as `true`/`false`, strings as strings) — the response is built from a re-fetch via `MagicMapper::findInRegisterSchemaTable` so it goes through the unified converter.
- [ ] 7.5 Manual search verification on the same register/schema: call `GET /api/search?q=…` and assert the same shapes appear in the search response (no regression on the path that was already correct).
- [ ] 7.6 Spot-check downstream: pull the same schema's data through OpenConnector's source proxy (one register-backed flow) — confirm booleans now arrive as `true`/`false` and strings stay strings.

## 8. Documentation and rollout

- [x] 8.1 Update `docs/` if there's an "API response types" or "data types" section that documented the buggy behaviour.
- [ ] 8.2 Add a release-notes entry mentioning the type-coercion fix and the consumer-impact note (booleans now arrive as PHP bool, not int).
- [ ] 8.3 Mention the OpenConnector knock-on effect in the OC release notes for the next OC release.

## 9. PR and archive

- [ ] 9.1 Open the PR against `development` (per Conduction default).
- [ ] 9.2 Run `/opsx:verify` to check this change against its artifacts.
- [ ] 9.3 After merge: run `/opsx:archive fix-magic-table-type-coercion` to move the change into `openspec/changes/archive/` and merge `schema-driven-read-coercion` requirements into the long-lived spec.
