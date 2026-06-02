---
retrofit: true
retrofit_extensions:
  - Integration list responses MUST be normalised into a canonical pagination envelope
---

# generic-integrations

## Purpose

This delta extends the merged `generic-integrations` capability with the single genuinely-uncovered behavior in the `lib/Service/Integration/` cluster: the canonical pagination envelope that `PaginatedResult` applies to every provider `list()` response. The provider contract, registry, router, and per-leaf CRUD paths are already owned upstream (`pluggable-integration-registry` and the per-leaf `integration-*` changes) and are only annotated, not re-authored here.

**Source**: Reverse-spec retrofit of shipped code — `lib/Service/Integration/PaginatedResult.php`. Behavior is documented as observed, not changed.

## ADDED Requirements

### Requirement: Integration list responses MUST be normalised into a canonical pagination envelope

Provider `list()` methods MAY return either a flat row array or a partial envelope (`{items|results, total?, nextCursor?}`); the dispatch layer MUST normalise any such value into the single canonical envelope `{items, total, nextCursor}` via `PaginatedResult::fromMixed()` before it reaches the client. Normalisation MUST be permissive: a flat list MUST yield `total = count(items)` and `nextCursor = null`; a `results` key MUST be treated as an alias of `items`; an absent `total` MUST fall back to the item count; a non-array value MUST yield an empty envelope (`items = []`, `total = 0`). The serialised form MUST additionally mirror `items` under a `results` key for backward-compatible frontend readers. This requirement documents existing behavior implemented by `lib/Service/Integration/PaginatedResult.php`.

#### Scenario: Flat list is wrapped into a single-page envelope

- **GIVEN** a provider's `list()` returns a flat array of 3 rows
- **WHEN** the value is passed through `PaginatedResult::fromMixed()`
- **THEN** the result MUST be `{items: <the 3 rows>, total: 3, nextCursor: null}`

#### Scenario: Partial envelope with results alias and explicit total is preserved

- **GIVEN** a provider returns `{results: [row], total: 42, nextCursor: '50'}`
- **WHEN** the value is normalised
- **THEN** `items` MUST equal `[row]`
- **AND** `total` MUST equal `42`
- **AND** `nextCursor` MUST equal `'50'`

#### Scenario: Absent total falls back to the item count

- **GIVEN** a provider returns `{items: [rowA, rowB]}` with no `total`
- **WHEN** the value is normalised
- **THEN** `total` MUST equal `2`
- **AND** `nextCursor` MUST be `null`

#### Scenario: Non-array value yields an empty envelope

- **GIVEN** a provider returns `null` (or a scalar)
- **WHEN** the value is normalised
- **THEN** the result MUST be `{items: [], total: 0, nextCursor: null}`

#### Scenario: Serialised envelope mirrors items under results

- **GIVEN** a normalised envelope with `items = [row]`
- **WHEN** it is serialised via `toArray()`
- **THEN** the array MUST contain both `items` and `results` equal to `[row]`
- **AND** MUST contain `total` and `nextCursor`

## Non-Functional

- **i18n (ADR-007)**: No user-facing strings (ADR-007 n/a) — the envelope keys (`items`, `results`, `total`, `nextCursor`) are machine-facing API contract fields, not display copy.
- **Backward compatibility**: The serialised envelope mirrors `items` under a `results` key so legacy frontend readers keep working; reverse-spec of already-shipped `PaginatedResult` code, no production behavior change.

## Acceptance Criteria

- `PaginatedResult::fromMixed()` and `toArray()` carry `@spec` annotations to this requirement.
- The four normalisation scenarios above (flat-list wrap, partial-envelope/`results`-alias preserve, absent-total fallback, non-array empty envelope) plus the serialised mirror hold for the shipped implementation.
