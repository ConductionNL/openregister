# enhanced-audit-trail Specification

## Purpose
TBD - created by archiving change add-hotpath-db-indexes. Update Purpose after archive.
## Requirements
### Requirement: Audit-trail queries are index-backed

Queries against the audit trail filtered by `register`/`schema` SHALL be
supported by a database index (covering statistics and history listings). The
append-only audit table SHALL NOT require a full table scan for
register/schema-scoped reads.

#### Scenario: Register-scoped audit stats use an index

- **WHEN** a register-detail view or dashboard requests audit statistics filtered
  by register (and schema)
- **THEN** the query uses a composite index on `(register, schema)` and does not
  full-scan the audit table

### Requirement: The universal soft-delete list predicate is index-backed on all supported databases

The common list predicate `_deleted IS NULL AND _owner = ?` SHALL be supported by
an index on every supported database platform, not only PostgreSQL.

#### Scenario: MySQL/MariaDB list query is indexed

- **WHEN** a list/search query runs on a MySQL/MariaDB install
- **THEN** the `(_deleted, _owner)` predicate is served by a composite index

### Requirement: Schema-scoped table resolution does not scan the whole catalog

Resolving the magic tables for a schema SHALL filter candidate tables in the
catalog query, not list every table in the database and discard non-matches in
PHP.

#### Scenario: findBySchema filters in the query

- **WHEN** objects are fetched by schema
- **THEN** the `information_schema` lookup is filtered to candidate tables for that
  schema

