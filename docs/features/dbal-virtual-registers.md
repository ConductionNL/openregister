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
