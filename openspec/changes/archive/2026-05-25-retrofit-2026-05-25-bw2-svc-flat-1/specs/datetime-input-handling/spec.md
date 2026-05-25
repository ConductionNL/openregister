## ADDED Requirements

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
`formatForIso8601(mixed $value): ?string` MUST normalize then format as ISO 8601 with
timezone offset (`DateTimeInterface::ATOM`), returning `null` for empty/invalid input.

#### Scenario: Empty and whitespace input normalizes to null
- **GIVEN** the value `''`, a whitespace-only string, or `null`
- **WHEN** `normalize()` is called
- **THEN** it MUST return `null` rather than the current date-time

#### Scenario: Parseable string round-trips to formatted output
- **GIVEN** a parseable datetime string
- **WHEN** `formatForDatabase()` is called
- **THEN** it MUST return the value formatted as `Y-m-d H:i:s`
- **AND** `formatForIso8601()` MUST return the value formatted as ISO 8601 with offset

#### Scenario: Unparseable or unsupported input degrades to null
- **GIVEN** an unparseable string or a non-string, non-DateTime value
- **WHEN** `normalize()` is called
- **THEN** it MUST return `null`
- **AND** a debug-level log entry MUST be written
