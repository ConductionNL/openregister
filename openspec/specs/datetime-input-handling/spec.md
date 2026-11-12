---
status: in-progress
---

# Datetime Input Handling

## Purpose

@e2e exclude backend datetime normalization — covered by PHPUnit
Defines how OpenRegister converts user-supplied datetime input at every stage of the object lifecycle — write, read, bulk, and search — so that empty, null, and whitespace-only values consistently normalize to `null` rather than being silently interpreted as the current date-time. Establishes a single canonical normalization helper that all code paths delegate to, eliminating the class of bug where PHP's `new DateTime('')` / `new DateTime(null)` silently produces "now" for user-cleared fields.

**OpenSpec changes**
- `fix-empty-string-date-conversion` (active) — introduces the `DateTimeNormalizer` helper, migrates identified call sites (read, bulk, search, metadata) to delegate to it, and pins the contract with unit + integration tests.

## Requirements

### Requirement: Datetime normalization is governed by the active change
While this capability is in-progress, normative requirements MUST be sourced from the active change `fix-empty-string-date-conversion` under `openspec/changes/`. Implementers MUST treat this canonical spec as a placeholder until the change is archived and its delta is merged here.

#### Scenario: Implementer needs the canonical contract
- **WHEN** an implementer needs the normative behavior for datetime input handling
- **THEN** they MUST consult the active change `fix-empty-string-date-conversion`
- **AND** they MUST NOT rely on this placeholder body for normative behavior

_Requirements for this capability are introduced by the active change above and will be merged here on archive._

### Requirement: Canonical DateTimeNormalizer Entry Points

The system MUST provide a single canonical `DateTimeNormalizer` service that all
OpenRegister code paths delegate to when converting user-supplied datetime input to a
`DateTimeImmutable` or to a formatted string. Direct use of `new DateTime($value)` on user
data is forbidden because PHP silently interprets `''` and `null` as "now".

`normalize(mixed $value): ?DateTimeImmutable` MUST apply the following rules in order:
`null` returns `null`; a `DateTimeImmutable` is returned unchanged; any other
`DateTimeInterface` is converted to a `DateTimeImmutable` of the same instant; a string is
trimmed and an empty/whitespace-only result returns `null`; a parseable string returns a
`DateTimeImmutable`; an unparseable string or any non-string/non-DateTime type returns
`null` (with a debug-level log). `formatForDatabase(mixed $value): ?string` MUST normalize
then format as `Y-m-d H:i:s`, returning `null` for empty/invalid input.

`normalize()` MUST accept an optional `?DateTimeZone $assumeTimezone`. It MUST be applied
ONLY to a string that carries no offset or timezone name of its own; a string that carries
one keeps it, and a `DateTimeInterface` is never re-zoned.

#### Scenario: Empty and whitespace input normalizes to null
- **GIVEN** the value `''`, a whitespace-only string, or `null`
- **WHEN** `normalize()` is called
- **THEN** it MUST return `null` rather than the current date-time

#### Scenario: Parseable string round-trips to formatted output
- **GIVEN** a parseable datetime string
- **WHEN** `formatForDatabase()` is called
- **THEN** it MUST return the value formatted as `Y-m-d H:i:s`

#### Scenario: Unparseable or unsupported input degrades to null
- **GIVEN** an unparseable string or a non-string, non-DateTime value
- **WHEN** `normalize()` is called
- **THEN** it MUST return `null`
- **AND** a debug-level log entry MUST be written

### Requirement: Database Datetime Columns Are UTC

A `date` / `date-time` schema property is backed by a DATETIME column
(`timestamp without time zone` on PostgreSQL) that stores a clock time and carries no
offset. Both directions of the round-trip MUST therefore agree on ONE timezone for that
clock time, and that timezone MUST be UTC — never the server's `date.timezone`, which
differs per deployment.

`formatForDatabase()` MUST convert the normalized value to UTC BEFORE rendering it as
`Y-m-d H:i:s`, so that a non-UTC offset is APPLIED rather than discarded. Rendering
directly is forbidden: `format()` renders in whatever timezone the instance carries, so
`2026-10-20T00:00:00+02:00` produced `2026-10-20 00:00:00` and the read path then read
that offset-less value back as UTC, moving the instant two hours forward.

The system MUST provide `formatDatabaseValueForIso8601(mixed $value): ?string` as the read
counterpart. It MUST interpret an offset-less column value as UTC and format it as ISO 8601
with offset. Every read path that lifts a `date-time` property straight out of its column
MUST use it, rather than normalising the naive string, whose reading depends on the
server's configured timezone.

#### Scenario: A non-UTC offset is converted, not dropped
- **GIVEN** the `date-time` value `2026-10-20T00:00:00+02:00`
- **WHEN** `formatForDatabase()` is called
- **THEN** it MUST return `2026-10-19 22:00:00`
- **AND** a negative offset MUST shift the other way: `2026-10-20T00:00:00-05:00` MUST
  return `2026-10-20 05:00:00`

#### Scenario: An offset-less column value is read back as UTC
- **GIVEN** the column value `2026-10-19 22:00:00`
- **WHEN** `formatDatabaseValueForIso8601()` is called
- **THEN** it MUST return `2026-10-19T22:00:00+00:00`

#### Scenario: The round-trip preserves the instant regardless of server timezone
- **GIVEN** a `date-time` value with any offset
- **AND** a server whose `date.timezone` is not UTC
- **WHEN** the value is written with `formatForDatabase()` and read back with
  `formatDatabaseValueForIso8601()`
- **THEN** the result MUST denote the SAME INSTANT as the input

### Requirement: Empty input SHALL normalize to null

User-supplied datetime input values of `null`, the empty string `""`, or a string consisting only of whitespace characters SHALL normalize to `null` when passed through the datetime normalization helper. No value SHALL be synthesized (no defaulting to "now" or any other timestamp).

#### Scenario: Null input

- **WHEN** the normalizer receives `null`
- **THEN** it SHALL return `null`
- **AND** no `DateTime` object SHALL be constructed

#### Scenario: Empty-string input

- **WHEN** the normalizer receives the empty string `""`
- **THEN** it SHALL return `null`
- **AND** no `DateTime` object SHALL be constructed

#### Scenario: Whitespace-only input

- **WHEN** the normalizer receives a string containing only whitespace (e.g. `"   "`, `"\t"`, `"\n"`)
- **THEN** it SHALL return `null`
- **AND** no `DateTime` object SHALL be constructed

### Requirement: Valid datetime input SHALL parse correctly

User-supplied datetime input that is a non-empty, parseable string SHALL be normalized into a `DateTimeImmutable` (or the equivalent formatted string for the requested output format).

#### Scenario: ISO 8601 with timezone

- **WHEN** the normalizer receives `"2026-04-20T14:00:00+02:00"`
- **THEN** it SHALL return a `DateTimeImmutable` representing that instant

#### Scenario: ISO 8601 UTC Zulu

- **WHEN** the normalizer receives `"2026-04-20T14:00:00Z"`
- **THEN** it SHALL return a `DateTimeImmutable` representing that instant

#### Scenario: Database datetime format

- **WHEN** the normalizer receives `"2026-04-20 14:00:00"`
- **THEN** it SHALL return a `DateTimeImmutable` representing that instant (local/default timezone)

#### Scenario: Date-only format

- **WHEN** the normalizer receives `"2026-04-20"`
- **THEN** it SHALL return a `DateTimeImmutable` at midnight of that date

#### Scenario: Existing DateTime instance

- **WHEN** the normalizer receives an existing `DateTimeInterface` instance
- **THEN** it SHALL return a `DateTimeImmutable` equal to that instant

### Requirement: Malformed input SHALL normalize to null without raising

Input that is a non-empty string but cannot be parsed as a datetime SHALL normalize to `null`. The normalizer SHALL NOT throw; it SHALL log at debug level for traceability.

#### Scenario: Garbled string

- **WHEN** the normalizer receives `"not-a-date"`
- **THEN** it SHALL return `null`
- **AND** a debug-level log entry SHALL be emitted referencing the input

#### Scenario: Numeric input (not accepted)

- **WHEN** the normalizer receives a number (e.g. `1745150400`)
- **THEN** it SHALL return `null`
- **AND** a debug-level log entry SHALL be emitted

### Requirement: Normalizer SHALL offer canonical output formats

For callers that need a formatted string rather than a `DateTimeImmutable`, the normalizer SHALL expose deterministic formatters.

#### Scenario: Database format

- **WHEN** a caller requests the database format for a valid input
- **THEN** the result SHALL be `"Y-m-d H:i:s"`-formatted
- **AND** empty or invalid input SHALL produce `null` (not the empty string)

#### Scenario: ISO 8601 format

- **WHEN** a caller requests the ISO 8601 format for a valid input
- **THEN** the result SHALL be an ISO 8601 string with timezone offset
- **AND** empty or invalid input SHALL produce `null` (not the empty string)

### Requirement: User-defined date/date-time properties SHALL render null when empty

When OpenRegister renders an object whose user-defined property is declared with JSON Schema `format: "date"` or `format: "date-time"`, and the stored value is `null`, `""`, or whitespace-only, the rendered value SHALL be `null`. The backend SHALL NOT substitute the current date-time or any other synthesized value.

#### Scenario: Empty stored date-time on read

- **WHEN** an object has a user-defined property `publishedAt` with `format: "date-time"` whose stored value is `""`
- **AND** the object is rendered via the standard read path
- **THEN** the rendered `publishedAt` SHALL be `null`
- **AND** the response SHALL NOT contain the current date-time for that field

#### Scenario: Null stored date on read

- **WHEN** an object has a user-defined property `birthDate` with `format: "date"` whose stored value is `null`
- **AND** the object is rendered via the standard read path
- **THEN** the rendered `birthDate` SHALL be `null`

### Requirement: Metadata datetime fields SHALL not accept empty-string input as "now"

When writing an object, if the caller explicitly provides `""` or a whitespace-only string for a metadata field that accepts a datetime (e.g. `expires`), the field SHALL be persisted as `null`, not as the current date-time. The existing behavior of defaulting `created`/`updated` to the current date-time when the field is absent (key not present) is preserved.

#### Scenario: Absent created field (unchanged)

- **WHEN** an object is written without a `created` field in the incoming data
- **THEN** `created` SHALL be populated with the current date-time (existing behavior preserved)

#### Scenario: Empty-string expires field

- **WHEN** an object is written with `expires: ""`
- **THEN** `expires` SHALL be persisted as `null`
- **AND** the current date-time SHALL NOT be used

### Requirement: Search normalization SHALL treat empty input as no constraint

The MariaDB search path's date normalization SHALL treat `null`, empty-string, and whitespace-only input as "no constraint" by returning `null`, enabling callers to drop the predicate rather than injecting a bogus filter value.

#### Scenario: Empty-string date search parameter

- **WHEN** a search filter is invoked with `value = ""` for a date field
- **THEN** the normalization helper SHALL return `null`
- **AND** the SQL builder SHALL NOT emit a comparison against the empty string or the current date-time

### Requirement: Single canonical implementation point

All OpenRegister code paths that convert user-supplied datetime strings to `DateTime`/`DateTimeImmutable` or to a database datetime string SHALL delegate to the shared datetime normalization helper. Direct use of `new DateTime($value)` with user-supplied values SHALL be removed from the identified sites.

#### Scenario: No unguarded DateTime construction on user input

- **WHEN** the codebase is audited for `new DateTime(` on user-supplied values
- **THEN** every call site SHALL either delegate to the normalizer or demonstrably operate on values already produced by the normalizer
- **AND** new call sites SHALL be prevented by code review — documented in the helper's class docblock
