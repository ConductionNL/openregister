---
kind: code
---

## Why

Organisations that adopt OpenRegister already run authoritative data in relational databases (permit systems, HR, finance, line-of-business apps). Today the only way to surface that data as OpenRegister objects is to copy it in via a sync/harvest Source, which duplicates data, goes stale, and requires write access to a target register. There is no way to point OpenRegister at an existing database and treat it — read-only and live — as a set of registers and schemas. The read-only object-source seam (`ObjectSourceProvider`) that already backs Nextcloud entities (Contacts, Deck, Talk, …) is exactly the mechanism needed to close this gap for arbitrary external SQL databases.

## What Changes

- Add the ability to connect OpenRegister to an **external relational database** over Doctrine DBAL and expose it as a **virtual register**: the database is a register, each table/view is a schema, and rows are served as objects **live** — no copy, no sync.
- **Introspect SQL structure into JSON Schema** so virtual schemas obey the same validation and relation rules as native schemas: columns → properties (with type/format/length/precision), `NOT NULL` (non-PK, non-defaulted) → `required`, foreign keys → `$ref` relations in the canonical relation dialect with `objectConfiguration.handling: related-object` and an `inversedBy` reverse side.
- Add a `DbalConnectionFactory` service that builds cached `Doctrine\DBAL\Connection`s from a `Source` (type `database`), resolving the DB password through the `CredentialStore` abstraction via `CredentialStoreResolver` (Doriath custody leaf when eligible, Nextcloud vault fallback — ADR-004) — never from plaintext in a Source row or OR object.
- Add a `DatabaseIntrospectionService` that turns a live connection into an importable Register + one Schema per table/view, each tagged with the `x-openregister-object-source` annotation `{provider: 'dbal-source', config: {sourceId, table, idColumn}}`. Re-introspection runs both on demand and via a scheduled `DbalIntrospectionJob` background job that detects schema drift and applies diffs through the existing `SchemaDiffService` path.
- Fix `ObjectService::paginateObjectSource()` page/total metadata for **all** object-source providers: push limit/offset down to `findAll()`, consult the provider's existing `count()` for the true total, and compute page/pages/next/prev — with a backward-compatible in-memory fallback for providers without real count support.
- Add a `DbalObjectSourceProvider implements ObjectSourceProvider` that translates an OpenRegister query (filters, search, sort, limit/offset) into a **parameterised** DBAL `QueryBuilder`, maps rows to `ObjectEntity` in memory, and enforces read-only, identifier quoting, per-source table allowlist, and result limits. Registered in `Application.php` alongside the existing providers.
- Add a `test-connection` action and a `introspect` action to `SourcesController` (new routes) plus a minimal UI flow to add a database source, test it, pick tables, and create the virtual register.
- **Read-only v1.** Writes to virtual schemas stay rejected by the existing object-source path. Drivers: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`. Views are supported like tables.

## Capabilities

### New Capabilities
- `dbal-virtual-registers`: Connecting to an external SQL database over Doctrine DBAL and exposing it — read-only and live — as an OpenRegister register whose schemas and objects are introspected from tables/views/columns/foreign keys.

### Modified Capabilities
<!-- No spec-level requirement changes to existing capabilities. The object-source
     dispatch, credential-broker vault, and Source entity are reused as-is; this
     change adds a new provider and services within their existing contracts. -->

## Impact

- **New code:** `lib/Service/Dbal/DbalConnectionFactory.php`, `lib/Service/Dbal/DatabaseIntrospectionService.php`, `lib/Service/Dbal/SqlTypeMapper.php` (SQL→JSON-Schema type/format), `lib/Service/ObjectSource/DbalObjectSourceProvider.php`, `DbalIntrospectionJob` background job.
- **Modified code:** `lib/AppInfo/Application.php` (register the provider in the object-source boot list + DI for the new services + job), `lib/Controller/SourcesController.php` (+ `appinfo/routes.php`) for `test-connection` and `introspect`, `ObjectService::paginateObjectSource()` (backward-compatible pagination-metadata fix for all providers), minimal Vue add-source flow.
- **Reused as-is:** `ObjectSourceProvider`/`ObjectSourceRegistry` seam (no interface change), `Schema::getObjectSource()` binding, `Source` entity (type `database`), `CredentialStoreResolver`/Doriath custody seam (ADR-004), `SchemaDiffService`, `Register.source`.
- **Dependencies:** `doctrine/dbal ^3.8` (already required). No new runtime dependency.
- **Security surface:** external DB credentials (vault-custodied), SQL injection (parameterised QueryBuilder + platform identifier quoting), and connection-failure error semantics (502/503, never a bare 500).
- **Downstream:** opencatalogi/softwarecatalog consume virtual schemas transparently through the standard objects API; `_extend` relation resolution works because FKs map onto the canonical relation dialect.
