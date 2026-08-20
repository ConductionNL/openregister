---
retrofit_extensions:
  - REQ-001
---

# Authentication and Authorization System — Retrofit Delta

Adds 1 REQ extending `auth-system` with the operational backfill OCC command for the `__system__` owner sentinel introduced in #1645. The sentinel makes admin RBAC filtering surface legacy rows; the OCC command backfills `_owner = ''` rows to `OrganisationService::SYSTEM_USER_ID_DEFAULT` on tables created before the sentinel existed.

## Requirements

### REQ-001: The system SHALL provide an idempotent OCC command to backfill the `__system__` owner sentinel on legacy magic-table rows

`occ openregister:backfill-system-owner` is a one-shot operational command that scans every magic table reachable by combining (register, schema) pairs and, for each row where `_owner = ''`, sets `_owner` to `OrganisationService::SYSTEM_USER_ID_DEFAULT` (the `__system__` sentinel). The command is idempotent by design: re-running on already-backfilled tables produces a per-table `scanned=N updated=0` line and the grand total returns `0` updates.

The command is implemented in `OCA\OpenRegister\Command\BackfillSystemOwnerCommand`, registered through Nextcloud's symfony console wiring. Its three options are: `--dry-run` (count without writing), `--register=<slug|uuid|id>` (limit scope to one register), `--schema=<slug|uuid|id>` (limit scope to one schema). Both mappers are resolved via `RegisterMapper::find()` / `findAll()` and `SchemaMapper::find()` / `findAll()` with `_rbac: false, _multitenancy: false` — the command bypasses RBAC and tenancy so legacy rows belonging to suspended/inactive tenants are still backfilled. Magic-table existence is verified per (register, schema) via `MagicMapper::tableExistsForRegisterSchema()`; missing tables are silently skipped. The actual DML uses `IDBConnection::getQueryBuilder()` with a count query (selecting rows where `_owner = ''`) followed (unless `--dry-run`) by an UPDATE that sets `_owner = OrganisationService::SYSTEM_USER_ID_DEFAULT` on the same predicate.

On failure to resolve a register or schema, the command writes the error message to stderr in `<error>...</error>` tags and exits with `Command::FAILURE`. On success (including the no-op idempotent case) it exits with `Command::SUCCESS`. A final `<info>Done. Tables=N scanned=M updated=K (dry run — no writes performed)</info>` summary is always written; the `(dry run — no writes performed)` suffix is conditional on the `--dry-run` flag.

#### Scenario: Run backfill across all tables

- **GIVEN** an OpenRegister deployment with 3 magic tables, some carrying rows from before #1645 (i.e. `_owner = ''`)
- **WHEN** an admin runs `occ openregister:backfill-system-owner`
- **THEN** the command iterates every register × every schema in the register's `getSchemas()` allow-list
- **AND** for each (register, schema) pair where `MagicMapper::tableExistsForRegisterSchema()` returns true, the rows with `_owner = ''` are counted and then UPDATEd to `_owner = '__system__'` via the query builder
- **AND** for each table a `register-slug/schema-slug (table-name): scanned=N updated=N` line is written
- **AND** the final summary reports `Tables=<count> scanned=<grand-total> updated=<grand-total>`
- **AND** the command exits with `Command::SUCCESS` (0)

#### Scenario: Idempotent re-run on a fully backfilled deployment

- **GIVEN** an OpenRegister deployment where the backfill command was already executed and every magic table now has `_owner != ''`
- **WHEN** the admin re-runs `occ openregister:backfill-system-owner`
- **THEN** every table's count query returns `scanned=0`
- **AND** the UPDATE statement is skipped (early return on `$scanned === 0`)
- **AND** the summary reports `Tables=<count> scanned=0 updated=0`

#### Scenario: Scope to a single register

- **GIVEN** a deployment with 5 registers
- **WHEN** the admin runs `occ openregister:backfill-system-owner --register=meldingen`
- **THEN** `resolveRegisters()` returns the single register matching slug/uuid/id `meldingen` via `RegisterMapper::find()`
- **AND** only that register's tables are scanned
- **AND** unrelated registers are not touched

#### Scenario: Dry-run reports counts without writing

- **GIVEN** a magic table with 100 rows where `_owner = ''`
- **WHEN** the admin runs `occ openregister:backfill-system-owner --dry-run`
- **THEN** `backfillTable()` runs the count query and returns `[100, 0]`
- **AND** the UPDATE statement is NOT executed
- **AND** the per-table line reports `scanned=100 updated=0`
- **AND** the summary suffix is `(dry run — no writes performed)`

#### Scenario: Register or schema lookup failure

- **GIVEN** the admin runs `occ openregister:backfill-system-owner --register=does-not-exist`
- **WHEN** `RegisterMapper::find('does-not-exist', _multitenancy: false)` throws (entity not found)
- **THEN** the throwable's message is written to stderr wrapped in `<error>...</error>`
- **AND** the command exits with `Command::FAILURE` (1)
- **AND** no writes are performed

#### Scenario: Register without allowed schemas is a no-op

- **GIVEN** a register whose `getSchemas()` allow-list is empty (or resolves to ids absent from `SchemaMapper::findAll()`)
- **WHEN** `resolveRegisters()` and `resolveSchemas()` succeed but no pair survives the `isset($schemasById[$allowedId])` filter
- **THEN** the inner loop iterates zero times for that register
- **AND** the command continues with the next register

### Notes

- **No multi-tenancy isolation by design.** Both `resolveRegisters()` and `resolveSchemas()` pass `_rbac: false, _multitenancy: false`. This is correct because legacy rows pre-date the tenant model — they have no owner to scope against. Surfaced here so future readers don't "fix" it.
- **The sentinel constant `OrganisationService::SYSTEM_USER_ID_DEFAULT` is the single source of truth.** The command does not hardcode `__system__` — it reads the constant. Re-shipping the sentinel value via another path would re-introduce drift.
- **Table-existence skipping is silent.** A schema in `findAll()` that has no magic table for a given register simply moves on without a log line. This keeps the output focused on tables that were actually visited.
