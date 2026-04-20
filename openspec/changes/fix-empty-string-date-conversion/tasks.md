## 1. Audit & pinning

- [ ] 1.1 Ripgrep `new DateTime\(` across `lib/` and produce a complete inventory of call sites; classify each as "user-supplied input", "internal/trusted value", or "literal/now"
- [ ] 1.2 Confirm the user-supplied sites listed in `design.md` are exhaustive; add any newly discovered sites to the inventory
- [ ] 1.3 Add a regression test that demonstrates the current defect: write an object with `publishedAt = ""` for a `date-time` property, read it back, assert value is NOT the current datetime (this test SHALL fail before the fix and pass after)

## 2. Implement the normalizer

- [ ] 2.1 Create `lib/Service/DateTimeNormalizer.php` (final location confirmed at code review) with the contract from `design.md` D2
- [ ] 2.2 Implement `normalize(mixed $value): ?DateTimeImmutable` with rules: `null` → null, trim + empty → null, `DateTimeInterface` → pass through as immutable, parse failures → null + debug log
- [ ] 2.3 Implement `formatForDatabase(mixed $value): ?string` returning `Y-m-d H:i:s` or `null`
- [ ] 2.4 Implement `formatForIso8601(mixed $value): ?string` returning ISO 8601 with offset or `null`
- [ ] 2.5 Register as an injectable service (no static state; DI via constructor)
- [ ] 2.6 Add a class docblock stating that all user-datetime conversion MUST go through this class and referencing this change

## 3. Unit tests for the normalizer

- [ ] 3.1 Null input → null
- [ ] 3.2 Empty string → null
- [ ] 3.3 Whitespace-only string (`"   "`, `"\t"`, `"\n"`) → null
- [ ] 3.4 Valid ISO 8601 with offset → correct DateTimeImmutable
- [ ] 3.5 Valid ISO 8601 Zulu → correct DateTimeImmutable
- [ ] 3.6 Database format `"Y-m-d H:i:s"` → correct DateTimeImmutable
- [ ] 3.7 Date-only `"Y-m-d"` → correct DateTimeImmutable at midnight
- [ ] 3.8 Existing `DateTime`/`DateTimeImmutable` passthrough → immutable instance
- [ ] 3.9 Garbled string → null + debug log
- [ ] 3.10 Numeric/array/object input → null + debug log
- [ ] 3.11 `formatForDatabase` and `formatForIso8601` with each of the above inputs

## 4. Migrate call sites (read path — primary fix)

- [ ] 4.1 `lib/Db/MagicMapper/MagicStatisticsHandler.php`: replace the `date` branch at ~line 583 with a delegation to the normalizer (format to `Y-m-d`)
- [ ] 4.2 `lib/Db/MagicMapper/MagicStatisticsHandler.php`: replace the `date-time` branch at ~line 590 with a delegation to the normalizer (format to ISO 8601)
- [ ] 4.3 Add/extend a MagicStatisticsHandler test covering: empty-string stored value → rendered as `null`; valid stored value → rendered correctly

## 5. Migrate call sites (write + bulk + search)

- [ ] 5.1 `lib/Db/MagicMapper/MagicBulkHandler.php::formatDateTimeForDatabase` (~line 766) → delegate to `DateTimeNormalizer::formatForDatabase`; remove direct `new DateTime($value)`
- [ ] 5.2 `lib/Db/MagicMapper.php` metadata handling (~line 2986) → delegate to the normalizer; keep the existing "default to now when key is absent" logic for `created`/`updated` exactly as-is (D3)
- [ ] 5.3 `lib/Db/ObjectHandlers/MariaDbSearchHandler.php::normalizeDateValue` (~line 637) → delegate to the normalizer; return `null` on empty/invalid input (D5)
- [ ] 5.4 Verify: grep for any remaining unguarded `new DateTime($` on user-supplied paths; migrate or document why each remaining site is safe (e.g. on a literal, on an internal `DateTime` instance, etc.)

## 6. Regression tests for migrated sites

- [ ] 6.1 Integration test: POST object with `publishedAt: ""` → GET object → `publishedAt` is `null`
- [ ] 6.2 Integration test: POST object with `publishedAt: "2026-04-20T14:00:00Z"` → GET object → value round-trips correctly
- [ ] 6.3 Integration test: POST object WITHOUT `publishedAt` in payload → GET object → `publishedAt` is `null` (unchanged behavior)
- [ ] 6.4 Integration test: bulk import path with empty-string date values → no current-datetime substitution
- [ ] 6.5 Integration test: search with `publishedAt=""` filter → no SQL predicate for empty filter (or no-op predicate)
- [ ] 6.6 Integration test: metadata — POST with `expires: ""` → persisted as `null`
- [ ] 6.7 Integration test: metadata — POST without `created` in payload → `created` defaulted to now (existing behavior preserved)

## 7. Cross-app verification

- [ ] 7.1 Run `opencatalogi` test suite against the patched backend; confirm no regressions
- [ ] 7.2 Run `softwarecatalog` test suite against the patched backend; confirm no regressions
- [ ] 7.3 Spot-check `docudesk` flows that consume OpenRegister datetime values (where applicable)

## 8. Quality & documentation

- [ ] 8.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes
- [ ] 8.2 Update `CHANGELOG.md` with a user-facing note on the behavior correction
- [ ] 8.3 Release note: "Empty-string date fields now correctly round-trip as null; previously, empty-string dates on existing objects rendered as the current datetime. On next read/save, the value normalises to null."
- [ ] 8.4 (Optional) File a follow-up issue for the stored-data normalisation maintenance command (`UPDATE ... SET col = NULL WHERE col = ''`) flagged in `design.md` §Migration Plan

## 9. Wrap-up

- [ ] 9.1 Run `openspec verify fix-empty-string-date-conversion` and resolve any findings
- [ ] 9.2 Open PR referencing this change; link the failing-then-passing regression test from task 1.3
