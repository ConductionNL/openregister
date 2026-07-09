---
status: done
---

# dbal-virtual-registers Specification

## Purpose

Expose external relational databases as read-only **virtual registers** over Doctrine DBAL: the database becomes a Register, its tables and views become Schemas, and objects are served live through the existing object-source-provider seam (`x-openregister-object-source`, ADR-049 mechanism) — no copy or sync. SQL structure is introspected into JSON Schema so virtual schemas follow the same validation rules and relations as the underlying tables (columns → properties, NOT NULL → required, foreign keys → `$ref` relations resolvable via `_extend`). Credentials are custodied per ADR-004; reads enforce RBAC parity and 502/503 failure semantics.

**OpenSpec changes**: [dbal-virtual-registers](../../changes/archive/2026-07-09-dbal-virtual-registers/) _(archived 2026-07-09)_

## Requirements

### Requirement: Connect to an external database as a virtual register
The system SHALL allow an administrator to configure a `Source` of type `database` that references an external relational database over Doctrine DBAL, and SHALL build a read-only connection to it on demand. Supported drivers SHALL be `pdo_mysql`, `pdo_pgsql`, and `pdo_sqlite`. The database password SHALL be custodied through the `CredentialStore` abstraction resolved via `CredentialStoreResolver` (Doriath custody leaf when eligible, Nextcloud vault fallback — ADR-004) and referenced from the Source by credential UUID; it SHALL NOT be stored in plaintext in the Source row or any OpenRegister object.

#### Scenario: Admin creates a database source and tests the connection
- **WHEN** an administrator submits a database source (driver, host, port, dbname, user, credential reference) and invokes the test-connection action
- **THEN** the system resolves the password through the credential custody seam, opens a DBAL connection, runs a trivial read, and returns success without exposing the password in the response
- @e2e exclude no external database exists in the Playwright e2e stack; covered by SourcesControllerTest contract tests against the SQLite permits fixture

#### Scenario: Test connection fails without leaking secrets
- **WHEN** the test-connection action runs against an unreachable host or with wrong credentials
- **THEN** the system returns a 503 (unreachable) or 502 (upstream error) with a non-sensitive message and no bare 500 and no credential value in the response or logs
- @e2e exclude requires an unreachable external host in the e2e stack; covered by SourcesControllerTest (unreachable → 503, no secret leakage)

#### Scenario: Missing or undecryptable credential fails closed
- **WHEN** the password cannot be resolved from the vault for a database source
- **THEN** the connection factory throws a typed connection exception and never opens an unauthenticated connection
- @e2e exclude vault-decrypt-failure requires fault injection into the credential store; covered by a unit test on the connection factory

### Requirement: Introspect SQL structure into JSON Schema
The system SHALL introspect a connected database via the DBAL schema manager and produce a `Register` (whose `source` is the source id) plus one `Schema` per table and per view. Each produced schema SHALL carry an `x-openregister-object-source` annotation of the form `{provider: 'dbal-source', config: {sourceId, table, idColumn}}`. Column types SHALL map to JSON-Schema `type`/`format` per the change's type-mapping table; `NOT NULL` columns that are neither the primary key nor database-defaulted SHALL become `required`; column length SHALL map to `maxLength`. Re-introspection SHALL update existing schemas matched by table name rather than duplicating them.

#### Scenario: Introspecting a table produces a schema with typed properties
- **WHEN** an administrator introspects a database source containing an `applicants(id PK, full_name TEXT NOT NULL, email VARCHAR(255) NOT NULL, kvk_number VARCHAR NULL)` table
- **THEN** a schema `applicants` is created with `full_name` and `email` in `required`, `kvk_number` optional, `email` typed `string` with `maxLength` 255, `idColumn` set to `id`, and the `x-openregister-object-source` annotation naming provider `dbal-source`
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DatabaseIntrospectionServiceTest golden-file assertions against the SQLite permits fixture

#### Scenario: Views are exposed as schemas
- **WHEN** the source contains a view `active_permits`
- **THEN** introspection creates a schema `active_permits` served by the same provider
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DatabaseIntrospectionServiceTest (view exposure) against the SQLite permits fixture

#### Scenario: Re-introspection updates rather than duplicates
- **WHEN** an administrator introspects the same source twice
- **THEN** the second run updates the existing schemas in place and does not create duplicate schemas
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DatabaseIntrospectionServiceTest idempotency assertions

### Requirement: Scheduled re-introspection detects schema drift
The system SHALL run a background job on a configurable interval that re-introspects every `type: database` source, applies structural changes to the affected schemas as diffs via the existing schema-diff path, and logs detected drift (added, dropped, or retyped columns; new or removed tables). A failure for one source SHALL NOT prevent the job from processing the remaining sources. A manual introspect action SHALL remain available for on-demand refresh. The job's run method SHALL perform this work directly (no stub body).

#### Scenario: Background job picks up an added column
- **WHEN** a column is added to an external table and the scheduled introspection job runs
- **THEN** the corresponding schema gains the new property via a schema diff and the drift is logged
- @e2e exclude background-job timing cannot be driven deterministically through the UI; covered by an integration test invoking the job class directly against the SQLite fixture

#### Scenario: One failing source does not block the rest
- **WHEN** the job runs while one of several database sources is unreachable
- **THEN** the unreachable source is logged as failed and the remaining sources are still re-introspected
- @e2e exclude requires fault injection across multiple sources; covered by a unit test on the job

### Requirement: Map foreign keys to canonical relations
The system SHALL map each single-column foreign key to a relation property on the owning schema using the canonical relation dialect: `type: string`, a `$ref` to the related virtual schema, and `objectConfiguration.handling: 'related-object'`. A `format` SHALL be emitted only when the underlying column's type mapping yields one (external primary keys are commonly plain integers/strings with no JSON-Schema format; relation resolution keys on `$ref` + `handling`, not `format`). The inverse side SHALL be added on the target schema with `inversedBy`. Multi-column foreign keys SHALL NOT be mapped to `$ref` in v1.

#### Scenario: A foreign key becomes a resolvable relation
- **WHEN** introspection encounters `permits.applicant_id` referencing `applicants.id`
- **THEN** the `permits` schema gains an `applicant_id` property with a `$ref` to the `applicants` schema and `objectConfiguration.handling: related-object`, and `applicants` gains an inverse property with `inversedBy`
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DatabaseIntrospectionServiceTest relation-dialect assertions

#### Scenario: Related object expands via _extend
- **WHEN** a client reads a `permits` object with `_extend=applicant_id`
- **THEN** the referenced `applicants` object is resolved and embedded, using the existing relation-resolution machinery unchanged
- @e2e exclude no external database exists in the Playwright e2e stack; relation expansion reuses the existing `_extend` machinery, covered by its existing tests plus the provider find-by-id tests

### Requirement: Serve external rows as live read-only objects
The system SHALL serve objects of a `dbal-source` schema live through the existing object-source dispatch, translating OpenRegister queries (filters, search, sort, limit, offset) into a parameterised DBAL query builder. The provider SHALL apply `limit`/`offset` in SQL, expose a real `count()` via `SELECT COUNT(*)` with the same predicate, quote all identifiers via the platform, and restrict queried tables to the introspected allowlist. Writes to a `dbal-source` schema SHALL be rejected.

#### Scenario: List objects from an external table
- **WHEN** a client requests objects of the `permits` schema with a filter on `status` and a page limit
- **THEN** the provider returns the matching rows as objects, having applied the filter as a bound parameter and the limit/offset in SQL
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DbalObjectSourceProviderTest filter/limit SQL assertions against the SQLite permits fixture

#### Scenario: Fetch a single object by id
- **WHEN** a client requests a `permits` object by its `id`
- **THEN** the provider issues `WHERE <idColumn> = :id` with a bound parameter and returns the object, or null when absent
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DbalObjectSourceProviderTest find-by-id and absent-returns-null tests

#### Scenario: Filter values are bound, not interpolated
- **WHEN** a client supplies a filter value containing SQL metacharacters (e.g. `' OR 1=1 --`)
- **THEN** the value is passed as a bound parameter and matched literally, returning no injected rows
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DbalObjectSourceProviderTest injection-literal-match test

#### Scenario: Writes are rejected
- **WHEN** a client attempts to create, update, or delete an object on a `dbal-source` schema
- **THEN** the request is rejected because the schema is served by a read-only object source
- @e2e exclude write rejection is enforced by the pre-existing object-source dispatch and its tests; no external database exists in the Playwright e2e stack

### Requirement: Correct pagination metadata for object-source schemas
The object-source pagination dispatch SHALL pass the query's limit and offset through to the provider, SHALL consult the provider's `count()` for the true total, and SHALL compute `page`, `pages`, `next`, and `prev` from total, limit, and offset. When a provider signals no count support (its `count()` throws or returns a value inconsistent with the returned window), the dispatch SHALL fall back to the current in-memory behaviour (total = returned result count, single page) so the existing providers remain backward compatible.

#### Scenario: Paging through a large external table
- **WHEN** a client requests page 2 with a limit of 50 from a `dbal-source` schema backed by a table of 120 rows
- **THEN** the response contains 50 objects, `total` 120, `pages` 3, `page` 2, and working `next`/`prev` links
- @e2e exclude no external database exists in the Playwright e2e stack; covered by PaginateObjectSourceTest page-2/limit-50/120-rows regression test and DbalObjectSourceProviderTest count-with-paged-findAll test

#### Scenario: Provider without count support falls back
- **WHEN** a schema is served by a provider whose `count()` cannot report a real total
- **THEN** pagination metadata falls back to the pre-existing in-memory behaviour without an error
- @e2e exclude requires a synthetic provider stub without count support; covered by a unit test on the dispatch fallback

### Requirement: Read access parity and no enumeration oracle
The provider SHALL apply the same read authorization as native schemas for the acting user. When an object is absent or access is denied, `find` SHALL return null indistinguishably, and `findAll` SHALL omit denied objects, so no enumeration oracle is exposed.

#### Scenario: Denied and absent are indistinguishable
- **WHEN** a user without read access requests an existing `dbal-source` object and, separately, requests a non-existent id
- **THEN** both requests return null with no difference that reveals the object's existence
- @e2e exclude RBAC-parity for a virtual schema requires seeding an access-restricted virtual register; covered by a provider unit test asserting the null-return contract

### Requirement: Resilient failure semantics
When the external database is unreachable or returns an error during a read, the system SHALL surface 503 (unreachable) or 502 (upstream error) semantics and SHALL NOT return a bare 500. When the required DBAL driver extension is absent, the provider's `isEnabled()` SHALL return false and a bound schema SHALL degrade to an empty result with a logged warning.

#### Scenario: External database down during a read
- **WHEN** the external database becomes unreachable while a client lists a `dbal-source` schema
- **THEN** the system responds with a 503-class error and a logged warning, not a 500
- @e2e exclude simulating mid-request DB outage requires network fault injection; covered by a provider unit test that stubs a DBAL connection exception

#### Scenario: Driver extension missing degrades gracefully
- **WHEN** the `pdo_pgsql` extension is not installed and a schema bound to a pgsql source is read
- **THEN** the provider reports itself disabled and the read returns an empty list with a logged warning
- @e2e exclude the e2e container always ships the pdo drivers, so absence cannot be staged; covered by DbalObjectSourceProviderTest missing-driver degradation test

### Requirement: Composite and missing primary keys
The system SHALL handle tables without a single-column primary key. For a composite primary key, the object id SHALL be a deterministic ordered join of the key columns and the schema's object-source config SHALL record `idColumns`. For a table with no primary key, the schema SHALL be read-list-only with `idColumn` null: `findAll`/`count` work but `find(id)` returns null.

#### Scenario: Composite primary key yields a deterministic id
- **WHEN** introspecting a table whose primary key is `(tenant_id, code)`
- **THEN** each object's id is the ordered join of the two column values and `find` reconstructs the predicate from the parts
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DbalObjectSourceProviderTest composite-id round-trip test

#### Scenario: Table with no primary key is list-only
- **WHEN** introspecting a table with no primary key
- **THEN** the schema is created with `idColumn` null, `findAll` and `count` succeed, and `find(id)` returns null
- @e2e exclude no external database exists in the Playwright e2e stack; covered by DbalObjectSourceProviderTest list-only-schema test and DatabaseIntrospectionServiceTest no-PK assertions
