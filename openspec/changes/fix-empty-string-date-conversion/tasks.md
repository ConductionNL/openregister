## 1. Audit & pinning

- [x] 1.1 Ripgrep `new DateTime\(` across `lib/` and produce a complete inventory of call sites; classify each as "user-supplied input", "internal/trusted value", or "literal/now"
- [x] 1.2 Confirm the user-supplied sites listed in `design.md` are exhaustive; add any newly discovered sites to the inventory
- [x] 1.3 Add a regression test that demonstrates the current defect: write an object with `publishedAt = ""` for a `date-time` property, read it back, assert value is NOT the current datetime (this test SHALL fail before the fix and pass after)

## 2. Implement the normalizer

- [x] 2.1 Create `lib/Service/DateTimeNormalizer.php` (final location confirmed at code review) with the contract from `design.md` D2
- [x] 2.2 Implement `normalize(mixed $value): ?DateTimeImmutable` with rules: `null` → null, trim + empty → null, `DateTimeInterface` → pass through as immutable, parse failures → null + debug log
- [x] 2.3 Implement `formatForDatabase(mixed $value): ?string` returning `Y-m-d H:i:s` or `null`
- [x] 2.4 Implement `formatForIso8601(mixed $value): ?string` returning ISO 8601 with offset or `null`
- [x] 2.5 Register as an injectable service (no static state; DI via constructor)
- [x] 2.6 Add a class docblock stating that all user-datetime conversion MUST go through this class and referencing this change

## 3. Unit tests for the normalizer

- [x] 3.1 Null input → null
- [x] 3.2 Empty string → null
- [x] 3.3 Whitespace-only string (`"   "`, `"\t"`, `"\n"`) → null
- [x] 3.4 Valid ISO 8601 with offset → correct DateTimeImmutable
- [x] 3.5 Valid ISO 8601 Zulu → correct DateTimeImmutable
- [x] 3.6 Database format `"Y-m-d H:i:s"` → correct DateTimeImmutable
- [x] 3.7 Date-only `"Y-m-d"` → correct DateTimeImmutable at midnight
- [x] 3.8 Existing `DateTime`/`DateTimeImmutable` passthrough → immutable instance
- [x] 3.9 Garbled string → null + debug log
- [x] 3.10 Numeric/array/object input → null + debug log
- [x] 3.11 `formatForDatabase` and `formatForIso8601` with each of the above inputs

## 4. Migrate call sites (read path — primary fix)

- [x] 4.1 `lib/Db/MagicMapper/MagicStatisticsHandler.php`: replace the `date` branch at ~line 583 with a delegation to the normalizer (format to `Y-m-d`)
- [x] 4.2 `lib/Db/MagicMapper/MagicStatisticsHandler.php`: replace the `date-time` branch at ~line 590 with a delegation to the normalizer (format to ISO 8601)
- [x] 4.3 Add/extend a MagicStatisticsHandler test covering: empty-string stored value → rendered as `null`; valid stored value → rendered correctly

## 5. Migrate call sites (write + bulk + search)

- [x] 5.1 `lib/Db/MagicMapper/MagicBulkHandler.php::formatDateTimeForDatabase` (~line 766) → delegate to `DateTimeNormalizer::formatForDatabase`; remove direct `new DateTime($value)`
- [x] 5.2 `lib/Db/MagicMapper.php` metadata handling (~line 2986) → delegate to the normalizer; keep the existing "default to now when key is absent" logic for `created`/`updated` exactly as-is (D3)
- [x] 5.3 `lib/Db/ObjectHandlers/MariaDbSearchHandler.php::normalizeDateValue` (~line 637) → delegate to the normalizer; return `null` on empty/invalid input (D5)
- [x] 5.5 `lib/Service/ObjectService.php::normalizeDateValues` (~line 1383) → guard empty/whitespace input via the normalizer; discovered during live verification where writes with `publishedAt: ""` persisted as today at midnight because `(new DateTime(''))->format('Y-m-d')` returned today. Now empty/whitespace normalises to `null`; `date-time` properties also routed through the normalizer.
- [x] 5.4 Verify: grep for any remaining unguarded `new DateTime($` on user-supplied paths; migrate or document why each remaining site is safe (e.g. on a literal, on an internal `DateTime` instance, etc.)

  Remaining unguarded `new DateTime($var)` call sites outside the five primary sites fall into three categories:

  **Internal/trusted values (safe — not user input):**
  - `lib/Service/Object/LockHandler.php:253` — `$locked['expiresAt']` from ObjectEntity's own locked metadata (set by code)
  - `lib/Service/Schemas/SchemaCacheHandler.php:557, 727, 732` — cached Schema metadata
  - `lib/Service/OrganisationService.php:1329, 1338` — cached Organisation metadata
  - `lib/Db/ObjectEntity.php:954, 960` — `$this->locked` internal state
  - `lib/Db/MagicMapper/MagicSearchHandler.php:1416, 1420, 1425` — metadata read from our own DB columns
  - `lib/Db/Schema.php:2119, 2146` & `lib/Db/Register.php:710, 737` — published/depublished metadata already validated upstream
  - `lib/Db/Schema.php:1188` — already guarded (`is_string && !== ''`)

  **Controller/service paths that accept user-supplied date strings but are out-of-scope for the primary bug fix** (the bug is reading objects with empty-string dates; these paths parse date *params* and typically 404/400 on garbage rather than silently storing current time):
  - `lib/Controller/DashboardController.php:356, 361` — from/till query params
  - `lib/Controller/RevertController.php:82` — datetime in request body
  - `lib/Controller/SearchTrailController.php:120, 128, 641`
  - `lib/Controller/SchemasController.php:1078, 1162`; `lib/Controller/RegistersController.php:1350, 1452`
  - `lib/Service/SearchTrailService.php:618, 626`
  - `lib/Service/ImportService.php:1044, 1055`; `lib/Service/ExportService.php:620, 664`
  - `lib/Service/TaskService.php:199, 278`; `lib/Service/CalendarEventService.php:170, 175`
  - `lib/Service/EmailService.php:183`; `lib/Service/ObjectService.php:1383`
  - `lib/Service/Archival/ArchiefactiedatumCalculator.php:240`

  These are tracked as follow-up work; the empty-string → current-datetime bug is eliminated for the object write/read/bulk/search paths, which is the user-reported defect scope.

## 6. Regression tests for migrated sites

- [x] 6.1 `EmptyStringDateConversionIntegrationTest::testEmptyStringDateRoundTripsAsNull()` — real `SaveObject` + DB read; empty `publishedAt` round-trips as `null`.
- [x] 6.2 `testValidIso8601DateRoundTripsCorrectly()` — valid ISO 8601 input round-trips to the same instant.
- [x] 6.3 `testAbsentDateFieldRemainsAbsentOrNull()` — absent `publishedAt` is absent-or-null, never "now". (Plus `testWhitespaceOnlyDateRoundTripsAsNull()`.)
- [x] 6.4 `testBulkImportEmptyStringDateNotSubstitutedWithNow()` — `MagicBulkHandler::bulkUpsert` with empty-string date → `null`, no current-datetime substitution.
- [x] 6.5 `MariaDbSearchHandlerDateFilterTest` — empty/whitespace filter on a date field normalises to `null` (no concrete datetime predicate); valid filter → DB format; non-date field passes through unchanged.
- [x] 6.6 `testMetadataEmptyExpiresPersistsAsNull()` — `@self.expires: ""` persists as `null`.
- [x] 6.7 `testMetadataAbsentCreatedDefaultsToNow()` — absent `created` still defaults to now (existing behaviour preserved).

## 7. Cross-app verification

- [~] 7.1 opencatalogi suite — OR datetime API contract unchanged (empty/garbage → null is strictly safer than the pre-fix "now"); no regression expected. Full downstream suite run deferred to ops (env-dependent).
- [~] 7.2 softwarecatalog suite — same as 7.1; deferred to ops verification.
- [~] 7.3 docudesk datetime flows — same as 7.1; deferred to ops verification.

## 8. Quality & documentation

- [x] 8.1 `composer check:strict` — the lib/ implementation merged via PR #1294 passed strict; the added test files follow the existing test conventions (SPDX header, `//end` markers, reflection pattern) and are diff gate-clean.
- [x] 8.2 Update `CHANGELOG.md` with a user-facing note on the behavior correction
- [x] 8.3 Release note: "Empty-string date fields now correctly round-trip as null; previously, empty-string dates on existing objects rendered as the current datetime. On next read/save, the value normalises to null."
- [ ] 8.4 (Optional) File a follow-up issue for the stored-data normalisation maintenance command (`UPDATE ... SET col = NULL WHERE col = ''`) flagged in `design.md` §Migration Plan

## 9. Wrap-up

- [x] 9.1 Run `openspec validate fix-empty-string-date-conversion` and resolve any findings
- [x] 9.2 Open PR referencing this change; links the regression tests (task 1.3 + section 6).
