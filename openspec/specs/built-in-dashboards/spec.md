---
status: redirect
---
# Built-in Dashboards

## Purpose

@e2e exclude redirect stub — no scenarios
This spec is a redirect stub. The canonical specification for built-in dashboards lives in the root openspec (cross-app pattern). This stub exists to preserve the spec slug locally and MUST NOT be treated as authoritative.
## Requirements
### Requirement: Consult the canonical built-in-dashboards spec
Implementers MUST consult the canonical `built-in-dashboards` specification owned by the root openspec instead of treating this stub as authoritative.

#### Scenario: Locating the canonical spec
- **WHEN** a developer needs the requirements for built-in dashboards
- **THEN** they MUST refer to the canonical spec in the root openspec
- **AND** they MUST NOT derive normative behavior from this stub

### Requirement: DashboardService MUST assemble OR-canned statistics and chart payloads with fail-soft semantics

`DashboardService` MUST provide the read-side aggregation that powers OpenRegister's
built-in dashboards. It MUST produce per-register and per-schema statistics rollups, detect
orphaned items (objects and audit entries referencing register/schema combinations that no
longer exist), expose a size-recalculation pass, and assemble chart-ready
`{labels, series}` payloads by delegating to the underlying mappers. Every aggregation
method MUST be fail-soft: an underlying error MUST be logged and a well-formed
zero/empty envelope returned, except the register-listing and size-recalculation entry
points which MUST surface a wrapped exception to the caller.

#### Scenario: Registers-with-schemas rollup includes synthetic totals and orphaned rows
- **WHEN** `getRegistersWithSchemas()` is called
- **THEN** the result MUST begin with a synthetic `totals` register carrying system-wide statistics
- **AND** each real register MUST carry register-level stats and its schemas each carry schema-level stats
- **AND** the result MUST end with a synthetic `orphaned` register carrying statistics for items referencing non-existent register/schema combinations

#### Scenario: Statistics envelope is fail-soft
- **GIVEN** an underlying mapper throws while computing statistics
- **WHEN** `getStats()` (or `getOrphanedStats`, `getAuditTrailStatistics`, `getAuditTrailActionDistribution`, `getMostActiveObjects`) runs
- **THEN** the error MUST be logged
- **AND** a structurally-complete envelope with zero/empty values MUST be returned rather than propagating the exception

#### Scenario: Size recalculation re-saves entities and tallies outcomes
- **WHEN** `recalculateSizes()` / `recalculateLogSizes()` runs over the filtered entity set
- **THEN** each entity MUST be re-saved to trigger recomputation of its stored size
- **AND** the result MUST report `processed` and `failed` counts, a per-entity failure being logged and counted rather than aborting the run
- **AND** `recalculateAllSizes()` MUST combine object and log tallies into a `total` block

#### Scenario: Calculate endpoint validates scope and reports a success rate
- **WHEN** `calculate($registerId, $schemaId)` runs
- **THEN** an unknown register or schema, or a schema not belonging to the given register, MUST raise a wrapped exception
- **AND** on success the response MUST include `status`, `timestamp`, `scope`, `results`, and a `summary` carrying `total_processed`, `total_failed`, and a computed `success_rate` percentage

#### Scenario: Chart-data assemblers return a labels-and-series envelope
- **WHEN** `getObjectsByRegisterChartData`, `getObjectsBySchemaChartData`, `getObjectsBySizeChartData`, or `getAuditTrailActionChartData` is called
- **THEN** the mapper-produced `{labels, series}` envelope MUST be returned
- **AND** on an underlying error an empty `{labels: [], series: []}` envelope MUST be returned after logging

