## MODIFIED Requirements

### Requirement: Serve external rows as live objects with opt-in writes
The system SHALL serve objects of a `dbal-source` schema live through the existing object-source dispatch (filters, search, sort, limit, offset as parameterized SQL — unchanged from v1). Writes to a `dbal-source` schema SHALL be rejected by default. When the backing `type: database` Source has `writable` enabled AND the schema's `x-openregister-object-source.readOnly` is `false`, create, update and delete SHALL be delegated to the writable provider and pushed to the external database. The writable state SHALL be re-resolved from the Source at write time so disabling the flag re-locks writes immediately; resolution failures SHALL fail closed (rejected). Views SHALL never be writable.

#### Scenario: Writes are rejected on a read-only source (default)
- **WHEN** a client attempts to create, update, or delete an object on a `dbal-source` schema whose source is not writable
- **THEN** the request is rejected exactly as in v1 because the schema is served by a read-only object source
- @e2e exclude no external database exists in the Playwright e2e stack; covered by SourcesControllerTest/SaveObject dispatch unit tests and live-verified on an isolated instance

#### Scenario: Disabling the writable flag re-locks writes immediately
- **WHEN** an administrator turns `writable` off on a database source and a client immediately attempts an update on one of its schemas
- **THEN** the write is rejected without contacting the external database, even though the schema annotations still carry `readOnly: false` until the next introspection
- @e2e exclude requires toggling admin source config mid-flow; covered by a dispatch unit test stubbing the source resolution

## ADDED Requirements

### Requirement: Create external rows through the standard objects API
On a writable `dbal-source` schema, `POST /api/objects/{register}/{schema}` SHALL validate the payload against the introspected JSON Schema, reject properties that are not introspected scalar columns with a 400, and execute a parameterized INSERT containing only the supplied allowlisted columns (absent columns take external defaults). A generated single-column primary key SHALL be returned (PostgreSQL `RETURNING`, `lastInsertId` elsewhere) and the response SHALL reflect the row as re-read from the external database. Tables without a primary key SHALL accept inserts (append-only).

#### Scenario: Create a permit row in the external database
- **WHEN** a client POSTs `{"applicant_id":"1","permit_type_id":"1","status":"submitted"}` to a writable `permits` schema
- **THEN** a new row exists in the external `permits` table, the response carries the database-generated id and DB-applied defaults (e.g. `submitted_at`), and a subsequent GET by that id returns the row
- @e2e exclude no external database exists in the Playwright e2e stack; covered by writable-fixture integration tests and live-verified (API + UI) on an isolated instance

#### Scenario: Unknown property is rejected
- **WHEN** a client POSTs a payload containing a property that is not an introspected column of the table
- **THEN** the request fails with a 400 naming the offending property and no statement reaches the external database
- @e2e exclude covered by a provider unit test asserting the allowlist rejection

### Requirement: Update and delete external rows through the standard objects API
On a writable `dbal-source` schema, `PUT/PATCH` and `DELETE` on `/api/objects/{register}/{schema}/{id}` SHALL execute a parameterized UPDATE/DELETE whose predicate is the single-column primary key, or the composite primary key reconstructed from the joined object id (part-count mismatch → 400). Zero affected rows SHALL surface as the same 404 an absent native object produces. Tables without a primary key SHALL reject update and delete. Deletes of external rows are hard deletes (the external system has no OpenRegister soft-delete).

#### Scenario: Update a permit's status in the external database
- **WHEN** a client updates object `2` of a writable `permits` schema setting `status` to `revoked`
- **THEN** the external row with id 2 has `status = 'revoked'` and the response reflects the updated row
- @e2e exclude no external database exists in the Playwright e2e stack; covered by writable-fixture integration tests and live-verified on an isolated instance

#### Scenario: Delete an absent external row yields 404
- **WHEN** a client deletes id `9999` on a writable `dbal-source` schema
- **THEN** the response is the same 404 an absent native object produces and no row is removed
- @e2e exclude covered by a provider unit test on the zero-affected-rows path

### Requirement: External constraint violations map to sanitized 4xx responses
Constraint violations raised by the external database during a write SHALL map to: unique (23505) and foreign-key (23503) violations → 409; not-null (23502), check (23514) and data/type errors (22xxx) → 422. Connection failures SHALL keep the v1 semantics (503 unreachable / 502 upstream). Client-visible messages SHALL name only the constraint class and never contain SQL fragments, DSNs, or credentials; the underlying database exception SHALL be logged server-side.

#### Scenario: Foreign-key violation surfaces as 409
- **WHEN** a client creates a permit whose `applicant_id` references a non-existent applicant and the external database enforces the foreign key
- **THEN** the response is a 409 with a sanitized foreign-key message, and the server log carries the underlying database error
- @e2e exclude no external database exists in the Playwright e2e stack; covered by writable-fixture integration tests and live-verified on an isolated instance

### Requirement: Write authorization precedes external contact and writes are audited
Schema-level `create`/`update`/`delete` authorization SHALL be enforced BEFORE the external database is consulted, with the same denial semantics as native schemas (no enumeration oracle). Successful external writes SHALL be recorded in the audit trail (or, where the audit mechanism cannot represent them, in a structured secret-free server log with a tracked follow-up).

#### Scenario: Denied create never reaches the external database
- **WHEN** a user without create permission POSTs to a writable `dbal-source` schema
- **THEN** the request is denied exactly like a native schema denial and no connection or statement is issued to the external database
- @e2e exclude RBAC seeding for a virtual register is not representable in the e2e stack; covered by a dispatch unit test proving the provider is never consulted
