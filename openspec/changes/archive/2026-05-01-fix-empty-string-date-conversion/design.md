## Context

OpenRegister stores objects as register+schema-scoped rows with schema-defined properties. Properties declared with JSON Schema `format: "date"` or `format: "date-time"` are stored as strings and are converted to/from `DateTime` at several points in the pipeline. Additionally, object metadata fields (`created`, `updated`, `expires`, etc.) flow through similar conversion code.

The conversion consistently uses the `new DateTime($value)` constructor. PHP treats an empty string and `null` as "now" rather than throwing, so any unguarded call site turns an empty-string input into the current timestamp — silently overwriting what the user intended to be an unset field.

Identified call sites on user-data paths (all unguarded today):

| File | Line | Direction | Affects |
|---|---|---|---|
| `lib/Db/MagicMapper/MagicStatisticsHandler.php` | ~583 | Read | User-defined `date` property — primary culprit for the observed bug |
| `lib/Db/MagicMapper/MagicStatisticsHandler.php` | ~590 | Read | User-defined `date-time` property |
| `lib/Db/MagicMapper/MagicBulkHandler.php` | ~766 (`formatDateTimeForDatabase`) | Write (bulk) | Generic helper used during bulk writes |
| `lib/Db/ObjectHandlers/MariaDbSearchHandler.php` | ~637 (`normalizeDateValue`) | Search | Empty-string search parameters |
| `lib/Db/MagicMapper.php` | ~2986 | Write | Metadata fields (created/updated/expires) |

Existing guarded reference: `lib/Db/Schema.php:1186` already checks `is_string($value) === true && $value !== ''` for Schema-level metadata fields — confirming the pattern is known and just inconsistently applied.

## Goals / Non-Goals

**Goals:**
- Guarantee that `null`, `''`, and whitespace-only strings are treated as absence (→ `null`) at every user-input datetime conversion point.
- Guarantee that malformed datetime strings become `null` (already mostly true via `catch`) rather than silently producing a bogus value.
- Eliminate the defect class by channelling every call site through one helper; make the correct path easier than the broken path.
- Add tests that pin the behavior.

**Non-Goals:**
- Canonicalising the stored datetime format across the codebase (separate concern — flagged in the earlier "normalize datetimes for RBAC matchers" discussion; may become its own change).
- Aligning `$now` format between `MagicRbacHandler` and `ConditionMatcher` (same separate concern).
- Migrating existing stored data (the read-path fix makes current bad data render as `null` automatically on next read; re-save persists `null`).
- Changing frontend behavior (the backend becoming robust also protects other consumers; the frontend emitting `null` is a parallel improvement owned elsewhere).

## Decisions

### D1 — Centralise in a new helper, don't inline-guard each site

**Decision**: Add a `DateTimeNormalizer` (exact class name/location TBD among `lib/Service/` or `lib/Formats/` — leaning `lib/Service/DateTimeNormalizer.php` for discoverability alongside other normalization services, while `lib/Formats/` is reserved for *format validators* like `BsnFormat`/`SemVerFormat` per ADR-011). All identified call sites delegate to it.

**Rationale**: Three inline guards in three files *look* the same today but drift. A helper removes that drift and gives us one obvious place to add unit tests.

**Alternatives considered**:
- *Guard each site in-place with `if ($value === '' || $value === null)`*. Smaller diff, but multiplies the invariant across the codebase and leaves the next developer free to reintroduce the bug in a new site. Rejected.
- *Extend PHP's `DateTime` via a custom subclass*. Over-engineered and surprises callers who expect standard PHP semantics. Rejected.

### D2 — Normalizer contract

`DateTimeNormalizer` exposes (at minimum):

```php
public function normalize(mixed $value): ?DateTimeImmutable;
public function formatForDatabase(mixed $value): ?string; // 'Y-m-d H:i:s'
public function formatForIso8601(mixed $value): ?string;  // ISO 8601 with offset
```

Rules enforced in `normalize()`:

1. `null` → `null`
2. `string` → `trim()`; if empty after trim → `null`
3. `DateTimeInterface` instance → returned as-is (normalised to `DateTimeImmutable`)
4. Any other input or parse failure → `null` and a debug-level log entry (not warning — this fires during normal user input)

**Rationale**: Single source of truth. Immutability prevents accidental mutation. Debug logging avoids alerting noise for expected empty-string inputs while still offering visibility when investigating.

### D3 — Metadata fields keep their existing "default to now for created/updated"

`MagicMapper::prepareMetadata` currently fills `created`/`updated` with `$now` when absent (line ~2977). Do NOT change this — that is *correct* defaulting, unrelated to the bug. The bug is only in the branch that tries to parse a *provided* string.

**Rationale**: Keep scope tight. Conflating "default on absent" with "default on empty-string" would grow the change and risk regressions on auto-managed timestamps.

### D4 — Parse failures log at debug, not warn

Empty-string input is expected (from forms); parse failures on a non-empty string are the legitimately interesting case but still user-caused. Use debug level; escalate to warning only if a caller explicitly opts in.

**Rationale**: Prevents log-spam on routine object writes; still leaves a trail for debugging.

### D5 — Search normalization returns `null` (not the original value) on empty input

`MariaDbSearchHandler::normalizeDateValue` currently returns the *original* value when parsing fails. After the change, empty input returns `null` so the caller can skip the filter rather than send a stale value into the SQL layer.

**Rationale**: An empty date filter should match no constraint, not the value `""`. Callers that relied on the old string passthrough (expected: zero) would need to handle `null`; grep-verifiable.

## Risks / Trade-offs

- **[Risk]** Hidden dependents that relied on empty-string → now behavior. → **Mitigation**: ripgrep for `new DateTime\(` across `lib/` and verify each remaining unguarded site is non-user-input; run the existing test suite; spot-check `opencatalogi` and `softwarecatalog`.
- **[Risk]** Existing objects with empty-string date values on disk will "change" from returning-today to returning `null` on the next read. → **Mitigation**: this is the *intended* correction; documented in the proposal. Flag in release notes.
- **[Risk]** `DateTimeNormalizer` gains scope creep (timezone handling, canonical format, etc.). → **Mitigation**: keep the contract minimal in this change; defer canonicalisation to the separately-tracked "normalize datetimes for RBAC matchers" change.
- **[Trade-off]** A helper adds one indirection vs. inline guards. Worth it for the invariant-enforcement and testability.

## Migration Plan

No data migration required. Deployment steps:

1. Land the normalizer + call-site migrations as a single PR.
2. Run full PHPUnit suite and integration tests.
3. Merge; on next object read, previously empty-string date values render as `null` automatically.
4. (Optional later) A one-shot maintenance command could `UPDATE ... SET col = NULL WHERE col = ''` for date-typed schema property columns to normalise stored values. Not required; filed as a follow-up.

**Rollback**: revert the PR. No schema or data changes to unwind.

## Open Questions

- Final home for `DateTimeNormalizer` — `lib/Service/` (preferred) vs. `lib/Formats/` vs. a new `lib/Util/` directory. Decide in code review.
- Should `normalize()` accept `int` (Unix timestamp) input? Defer — no current caller needs it; YAGNI.
- Should the search path (`MariaDbSearchHandler::normalizeDateValue`) also benefit from a query-planner signal when the result is `null` (i.e. drop the predicate entirely)? Likely yes but out of scope here; surface during implementation.

## Seed Data

**Not applicable.** This change does not introduce or modify any OpenRegister schemas. It corrects conversion behavior on existing user-defined datetime properties regardless of which schema they belong to. Per ADR-016, the seed data requirement applies when schemas are introduced or materially modified; none are here.
