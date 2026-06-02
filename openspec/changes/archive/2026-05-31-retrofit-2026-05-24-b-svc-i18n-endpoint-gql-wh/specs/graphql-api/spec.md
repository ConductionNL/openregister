## ADDED Requirements

### Requirement: The resolver MUST provide aggregation, DataLoader-flush, and cursor helpers

`GraphQLResolver` MUST expose the supporting helpers that back list queries: an aggregation resolver that dispatches `groupBy` to the timeseries pipeline, a DataLoader buffer flush that batch-loads buffered relation UUIDs, and an opaque cursor encoder for Relay pagination.

#### Scenario: Resolve a groupBy aggregation

- **GIVEN** a list query with a `groupBy` argument and a schema bound to a register
- **WHEN** `resolveGroupBy()` runs
- **THEN** it MUST normalize the raw args (field, interval, from, to, metric lowercased, metricField) and validate them through the timeseries validator
- **AND** it MUST run the aggregation via the aggregation runner and return buckets as `[{key: string, value: float}]`
- **AND** a validation error or RBAC denial MUST be re-thrown as a GraphQL `Error` (field-level), and a schema with no register MUST return `null` rather than erroring

#### Scenario: Flush the relation buffer in one batch

- **GIVEN** buffered relation UUIDs collected during nested resolution
- **WHEN** `flushRelationBuffer()` runs
- **THEN** it MUST clear the buffer, batch-load all UUIDs via `RelationHandler::bulkLoadRelationshipsBatched()`, and store each loaded object in `relationCache` keyed by UUID
- **AND** an empty buffer MUST be a no-op, and a load failure MUST be caught and logged as a warning rather than propagated

#### Scenario: Encode an opaque pagination cursor

- **GIVEN** an object UUID and an offset position
- **WHEN** `encodeCursor()` runs
- **THEN** it MUST return a base64-encoded JSON string containing `{uuid, offset}`
