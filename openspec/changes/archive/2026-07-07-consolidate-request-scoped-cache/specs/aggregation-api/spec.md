## ADDED Requirements

### Requirement: The register catalog scan runs once per request

The register catalog scan SHALL be memoized per request — resolving the set of
magic-table register/schema pairs (the `information_schema` scan) runs once. It
SHALL NOT be re-executed once per register in a list response or once per
`findBySchema()` call.

#### Scenario: Registers list scans the catalog once

- **WHEN** `GET /api/registers` with `@self.stats` returns a page of registers
- **THEN** the magic-table catalog is scanned once for the request
- **AND** register statistics are produced by a grouped query set, not one
  `getStatistics()` call per register

### Requirement: A single request-scoped entity cache

Per-request memoization of schemas, registers, and objects SHALL use one shared
cache. There SHALL NOT be multiple uncoordinated per-request caches for the same
entities.

#### Scenario: Repeated entity resolution hits one cache

- **WHEN** a request resolves the same schema/register multiple times across
  mappers and the render path
- **THEN** the resolution is served from a single request-scoped cache

### Requirement: Full-table name warmup is background-only

The name-cache warmup that loads the full object table SHALL run only in its
background job, never be auto-triggered synchronously within a user-facing
request.

#### Scenario: A request never triggers the full warmup

- **WHEN** a controller path needs object names and the in-memory name cache is empty
- **THEN** it does not synchronously load the entire objects table
