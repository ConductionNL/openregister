## Why

PHP's `new DateTime('')` and `new DateTime(null)` silently return the current date-time instead of failing or returning `null`. Several OpenRegister code paths pass user-supplied datetime strings directly into this constructor without guarding against empty input. When a form submits `""` for a cleared date field (common default frontend behavior), the backend interprets the value as "now" and persists/renders the current timestamp. From the user's perspective a field they never filled in has silently been populated with the moment they pressed save.

The effect is subtle, silent, and hard to notice: audit trails, retention calculations, RBAC conditions comparing `$now`, and any UI showing the date all misreport the field. Fixing this class of bug is a small, focused change that removes a whole category of data-corruption footguns.

## What Changes

- Introduce a single normalization helper that converts user-supplied datetime input to either a valid `DateTime` (or formatted string) or `null` — treating `null`, `''`, and whitespace-only strings uniformly as `null`, and parsing failures as `null`.
- Replace direct `new DateTime($value)` calls on user-supplied property values with this helper in the identified call sites (read path, bulk formatter, search normalization; metadata path already has a partial guard but gets aligned too).
- Guard the helper behind a single point so future call sites cannot reintroduce the footgun.
- Add regression tests that cover `null`, `""`, `"   "`, valid ISO-8601, and malformed inputs for both user-defined properties and metadata fields.

**Not breaking**: no API contract changes; the fix is a behavior correction from silently-wrong to correctly-null.

## Capabilities

### New Capabilities

- `datetime-input-handling`: Normalization contract for datetime-typed user input across save, read, bulk, and search paths. Defines how `null`, empty strings, whitespace, valid ISO-8601, and malformed inputs are treated uniformly across the codebase.

### Modified Capabilities

_None._ Behavior change is a bug fix, not a requirement change to existing specs.

## Impact

**Affected code:**
- `lib/Db/MagicMapper/MagicStatisticsHandler.php` (primary: lines ~583, 590 — render path for user-defined `date`/`date-time` properties; the empty-string render defect is here).
- `lib/Db/MagicMapper/MagicBulkHandler.php` (bulk formatter `formatDateTimeForDatabase`, line ~766).
- `lib/Db/ObjectHandlers/MariaDbSearchHandler.php` (search normalization, line ~637).
- `lib/Db/MagicMapper.php` (metadata fields `created`/`updated`/`expires`, line ~2986 — for consistency with the new helper).
- New utility (location TBD in design): a `DateTimeNormalizer` — or similar — that the above sites delegate to.
- Tests under `tests/Unit/` for the helper and affected handlers.

**Affected data:**
- Objects that currently contain empty-string date fields on disk will, on next read, return `null` instead of the current datetime. This is the intended correction.
- No data migration required (reads become correct; re-saving converts empty-string to `null` naturally).

**Affected dependents:**
- `opencatalogi` and `softwarecatalog` consumers that relied on the (wrong) current-datetime behavior would need verification — expected to be zero; the behavior was unintended.
