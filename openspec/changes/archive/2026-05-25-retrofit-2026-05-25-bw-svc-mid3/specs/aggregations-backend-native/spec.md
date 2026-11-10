---
retrofit_extensions:
  - REQ-ABN-006
  - REQ-ABN-007
---

# Aggregations Backend-Native Specification (delta)

**Status**: implemented (retrofit — code already exists)
**Scope**: openregister

## ADDED Requirements

### Requirement: The system MUST validate aggregation-API request shapes and widget annotations before execution

`TimeseriesRequestValidator::validate(array $input, Schema $schema): AggregationQuery` MUST guard the REST timeseries-aggregation request shape and return a validated `AggregationQuery` value object, throwing a client-safe `InvalidArgumentException` (mapped to HTTP 400) on any violation. It MUST require a non-empty `field`, and the `field` MUST be a declared property of `$schema` (allow-listed) — an undeclared field MUST be rejected. When `metric !== 'count'` it MUST require a `metricField` that is also a declared schema property; for `count` the value-object field MUST be null. When an `interval` is supplied it MUST be one of the closed interval vocabulary; a sub-day interval (e.g. hour/minute) MUST require the bucket field to have `format: date-time`; and `from` / `to` MUST be present and parseable ISO-8601 datetimes. An interval request MUST produce a `dateBucket` (and null `groupBy`); a no-interval request MUST produce a categorical `groupBy` on the field — never both (the value object rejects the combination).

`WidgetAnnotationValidator::validate(array $schema): array` MUST validate the `x-openregister-widgets` schema annotation and return a list of `{code, message}` error descriptors (empty list = valid). When the annotation is absent it MUST return `[]`. When present but empty or non-array it MUST return a single `widgets-empty` error. For each widget it MUST require a `type` from the supported set (`kpi`, `chart`, `table`, `stats`, `sparkline`, `tile`), a non-empty `title`, and a `dataSource` object whose `mode` is one of `aggregation`, `graphql`, `statistics`. Each violated rule MUST emit its own coded error so the caller can surface field-level feedback.

#### Scenario: Timeseries validator rejects an undeclared field
- **GIVEN** a schema declaring properties `status` and `created`
- **WHEN** `TimeseriesRequestValidator::validate(['field' => 'nope'], $schema)` runs
- **THEN** an `InvalidArgumentException` MUST be thrown noting `field "nope" is not a declared property of the schema`

#### Scenario: Sub-day interval requires a date-time field
- **GIVEN** `field = 'createdDate'` whose schema `format` is `date` (not `date-time`)
- **WHEN** `validate(['field' => 'createdDate', 'interval' => 'HOUR', 'from' => '2026-01-01', 'to' => '2026-02-01'], $schema)` runs
- **THEN** an `InvalidArgumentException` MUST be thrown stating the sub-day interval requires a `date-time` field

#### Scenario: Interval request produces a dateBucket, no-interval produces a groupBy
- **GIVEN** `field = 'created'` (a declared `date-time` property)
- **WHEN** `validate` is called with a valid `interval` + `from`/`to`
- **THEN** the returned `AggregationQuery` MUST carry a `dateBucket` and a null `groupBy`
- **AND** when `interval` is omitted the returned query MUST carry a categorical `groupBy` on `field` and a null `dateBucket`

#### Scenario: Widget validator flags bad type, missing title, and bad dataSource mode
- **GIVEN** a schema with `x-openregister-widgets: [{type: 'gauge', dataSource: {mode: 'rest'}}]`
- **WHEN** `WidgetAnnotationValidator::validate($schema)` runs
- **THEN** the returned list MUST include a `widget-bad-type` error (`gauge` not in the supported set)
- **AND** a `widget-title-missing` error
- **AND** a `widget-datasource-bad-mode` error (`rest` not in `aggregation`/`graphql`/`statistics`)

#### Scenario: Absent widget annotation is valid
- **GIVEN** a schema with no `x-openregister-widgets` key
- **WHEN** `WidgetAnnotationValidator::validate($schema)` runs
- **THEN** the result MUST be `[]`

### Requirement: The system MUST resolve time and session placeholders inside aggregation filters before dispatch

`PlaceholderResolver::resolve(mixed $value): mixed` MUST pass through any non-string or any string not starting with `$` unchanged. It MUST resolve `$currentUser` to the current session UID (or `''` when unauthenticated). It MUST resolve the date placeholders `$now`, `$startOfDay`, `$startOfWeek`, `$startOfMonth`, `$startOfYear` to a `DateTimeImmutable` anchored at the appropriate boundary (week starts Monday). It MUST support signed offset arithmetic via the `^(\$[a-zA-Z]+)([+-]\d+)([dwmy]?)$` grammar: the suffix selects days/weeks/months/years, and a bare integer offset MUST default to the placeholder's natural unit (`$startOfMonth-1` = one month earlier, `$startOfYear+1` = one year later, `$now-7d` = seven days earlier). An unrecognised `$`-prefixed string MUST be returned unchanged for the caller to surface.

`PlaceholderResolver::resolveArray(array $values): array` MUST recurse into nested arrays and apply `resolve()` to every leaf value, preserving keys. `AggregationRunner` MUST call `resolveArray()` on the filter map before the cache-key derivation and the native/PHP dispatch, so placeholder-bearing filters resolve to concrete values exactly once per request.

#### Scenario: Date placeholder with default-unit offset
- **WHEN** `PlaceholderResolver::resolve('$startOfMonth-1')` runs
- **THEN** the result MUST be a `DateTimeImmutable` at the first day of the month one month before the current month (the `m` unit is defaulted from the placeholder)

#### Scenario: Explicit-suffix offset overrides the default unit
- **WHEN** `PlaceholderResolver::resolve('$now-7d')` runs
- **THEN** the result MUST be a `DateTimeImmutable` seven days before now

#### Scenario: currentUser resolves to the session UID, unknown placeholder passes through
- **GIVEN** a logged-in user `alice`
- **WHEN** `resolve('$currentUser')` runs
- **THEN** the result MUST be `'alice'`
- **AND** `resolve('$notAThing')` MUST return the string `'$notAThing'` unchanged
- **AND** `resolve(42)` MUST return `42` unchanged

#### Scenario: resolveArray recurses and preserves keys
- **GIVEN** `['status' => 'open', 'createdAfter' => '$startOfWeek', 'nested' => ['by' => '$currentUser']]`
- **WHEN** `resolveArray(...)` runs
- **THEN** `status` MUST remain `'open'`
- **AND** `createdAfter` MUST be the Monday-anchored `DateTimeImmutable` for the current week
- **AND** `nested.by` MUST be the resolved session UID

## Non-Functional

- **i18n (ADR-007):** This delta covers backend validators and a placeholder
  resolver with no user-facing copy of its own. Validation errors
  (`InvalidArgumentException` messages, the `{code, message}` widget error
  descriptors) are developer/operator diagnostics; the stable machine-readable
  `code` (e.g. `widget-bad-type`) is the contract, leaving the human `message`
  free for the caller to localise. The DSL MUST NOT assume a locale — week
  boundaries are anchored on Monday (ISO-8601), independent of display locale.
- **Determinism:** placeholder resolution MUST be applied exactly once per
  request, before cache-key derivation, so identical filters yield identical
  cache keys.
- **Safety:** field allow-listing against declared schema properties MUST gate
  every timeseries request to prevent unbounded/arbitrary-field aggregation.

## Acceptance Criteria

- `TimeseriesRequestValidator::validate` rejects undeclared fields, enforces the
  closed interval vocabulary, requires `date-time` fields for sub-day intervals,
  and produces exactly one of `dateBucket` / `groupBy`.
- `WidgetAnnotationValidator::validate` returns `[]` for absent annotations and a
  coded error per violated rule otherwise.
- `PlaceholderResolver::resolve` handles `$currentUser`, the date anchors, signed
  offset arithmetic with unit defaulting, and passes unknown/non-string values
  through unchanged; `resolveArray` recurses preserving keys.
