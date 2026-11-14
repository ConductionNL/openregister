# External Databases as Virtual Registers (Doctrine DBAL)

Connect an external relational database and use it as a read-only **virtual register**: the database becomes a Register, its tables and views become Schemas, and objects are served live over Doctrine DBAL — no copy, no sync job, no duplicate storage.

## Standards & architecture references

- GEMMA Gegevensmagazijncomponent — [GEMMA](https://gemmaonline.nl/index.php/GEMMA/id-06321658-50d1-4153-b007-6630ffabcd80) (data warehouse over existing sources)
- Common Ground principle: data stays at the source; OpenRegister projects it.
- Hydra ADR-049 (virtual schemas via object-source providers), OpenRegister ADR-004 (credential custody).
- Spec: [`openspec/specs/dbal-virtual-registers/spec.md`](../../openspec/specs/dbal-virtual-registers/spec.md)

## Overview

An administrator creates a `Source` of type `database` (driver, host, port, database, user) with the password custodied in the credential vault (referenced by credential UUID — never stored in plaintext). A test-connection action verifies reachability. Introspection then reads the database structure through the DBAL schema manager and produces a Register plus one Schema per table/view, each bound to the `dbal-source` object-source provider via `x-openregister-object-source`. From that point the standard objects API lists, reads, filters, sorts, and paginates the external rows live — with schema-level RBAC parity and relation expansion via `_extend`.

## Key capabilities

- **Drivers**: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`; views supported (read-only by nature).
- **Credential custody (ADR-004)**: password lives behind the `CredentialStore` seam (Doriath leaf, Nextcloud vault fallback); submitted plaintext passwords are stripped before persistence; fail-closed on unresolvable credentials.
- **SQL → JSON Schema introspection**: column types map to JSON-Schema `type`/`format`; `NOT NULL` → `required`; length → `maxLength`; single-column foreign keys become `$ref` relations with `objectConfiguration.handling: related-object` (+ inverse `inversedBy`) so `_extend` resolution and validation work unchanged; composite primary keys get a deterministic joined id; tables without a primary key are list-only.
- **Live reads, real pagination**: filters/search/sort/pagination push down to parameterized SQL (platform-quoted identifiers, column allowlist); true totals via `COUNT(*)` — the pagination fix benefits all object-source providers.
- **Scheduled drift detection**: a background job re-introspects each database source on an interval and applies schema diffs; a manual introspect action also exists.
- **Failure semantics**: unreachable database → 503, upstream error → 502 — never a bare 500; denied list reads return 404 with no enumeration oracle.
- **Read-only by default, opt-in writes (v2)**: an admin can enable "Allow writes" on a database source; create/update/delete then push through to the external database as parameterized, column-allowlisted statements with RBAC before external contact, audit-trail rows, sanitized constraint errors (unique/FK → 409, not-null/check/type → 422), and generated keys via PostgreSQL `RETURNING` / `lastInsertId`. The flag is re-verified live at write time (disabling re-locks instantly); views are never writable, no-PK tables are append-only, and writes to external rows are hard deletes / last-write-wins (no OpenRegister locking).

## Query-backed schemas

A schema can also read a query instead of a table: its object-source config carries `query` (for example a join of two tables, or only the open orders) in place of `table`. The query is read as a derived table, `SELECT ... FROM (<query>) or_src`, so filters, search, sort and pagination work on its columns as they do on a table. A query-backed schema is always read-only.

Three things keep such a read from changing the connected database:

- **The statement guard.** Before a query is previewed or read, OpenRegister checks that it is one statement that starts with `SELECT` or `WITH`. It must not use `INSERT`, `UPDATE`, `DELETE`, `MERGE`, `UPSERT`, `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `GRANT`, `REVOKE`, `COPY`, `CALL`, `EXECUTE`, `SET`, `LOCK`, `INTO` or `FOR UPDATE`, and it must not contain `?` or `:name` placeholders. Comments and string literals are skipped, so `SELECT 'UPDATE' AS label` is fine. A refused query answers 422 on a read, naming the rule that failed, and is never sent to the database.
- **A read-only transaction with a time limit.** Every read of a query-backed schema runs in a transaction that the database itself holds to read-only, and is rolled back afterwards. On PostgreSQL that is `START TRANSACTION READ ONLY` with `SET LOCAL statement_timeout`. On MariaDB it is `START TRANSACTION READ ONLY` with `max_statement_time`, and on MySQL `START TRANSACTION READ ONLY` with `max_execution_time`. The session limit is reset afterwards. On Oracle it is `SET TRANSACTION READ ONLY`. On SQLite and other platforms the read runs in a plain transaction that is rolled back. A slow query is stopped after 30 seconds (10 seconds for a preview).
- **A read-only database user (advice).** Connect the source with a database user that may only `SELECT` the tables the queries read. The guard and the transaction are checks inside OpenRegister; a read-only user is the check the database applies whatever OpenRegister does.

### Previewing a query

Before saving a query-backed schema, an administrator can see what the query returns:

```
POST /api/sources/{id}/query-preview
{"query": "SELECT p.reference, t.name AS type FROM permits p JOIN permit_types t ON t.id = p.permit_type_id"}
```

The answer holds the columns with the type their values have (`integer`, `number`, `boolean`, `string`, or `null` when every value is empty) and at most 50 rows:

```json
{"columns": [{"name": "reference", "type": "string"}, {"name": "type", "type": "string"}], "rows": [{"reference": "OMV-2026-001", "type": "Bouw"}]}
```

Only an administrator may preview, and only on a source of their own organisation; another organisation's source answers 404. The possible answers are:

- 400: the guard refused the query, and the answer names the rule.
- 422: the source is not a database source.
- 503: the database cannot be reached.
- 502: the database refused the query. The answer reads `The database refused the query.` and gives the error class, such as `TableNotFoundException`. It never includes the database's own message, which can name other tables.
