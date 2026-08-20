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

