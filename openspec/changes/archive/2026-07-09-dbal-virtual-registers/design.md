## Context

OpenRegister already has a read-only object-source seam: a schema whose `configuration['x-openregister-object-source']` block names a provider is served live by that provider instead of from the magic tables. The seam is fully wired:

- **Interface** `lib/Service/ObjectSource/ObjectSourceProvider.php` — `getId(): string`, `isEnabled(): bool`, `find(Register $r, Schema $s, string $id, array $config): ?ObjectEntity`, `findAll(Register $r, Schema $s, array $query, array $config): array`, `count(Register $r, Schema $s, array $query, array $config): int`.
- **Registry** `ObjectSourceRegistry` — `addProvider($provider)`, `get($id)`; shared instance.
- **Binding** `Schema::getObjectSource()` (~line 1592) returns `['provider' => …, 'config' => …]` or null.
- **Dispatch** `ObjectService::paginateObjectSource()` (~line 2489) calls `provider->findAll(...)` and — importantly — currently computes `total = count($results)` and returns `page: 1, pages: 1`. There is a parallel single-object dispatch on the get path. The standard `ObjectsController` API serves these schemas transparently; writes are rejected upstream.
- **Boot** `Application::bootObjectSourceProviders()` (~line 2882) iterates a hard-coded `$providerClasses` list and `addProvider($server->get($class))`, guarded so a failing provider never takes down the app. Eight providers exist today (Contacts, CalendarEvent, CalDavVtodo, Deck, Files, Group, Talk, UserDirectory).

Doctrine DBAL `^3.8` is already a composer dependency and is used in `MagicMapper` (imports `Doctrine\DBAL\Platforms\*`). OCP exposes no API for *external* connections, so we use `Doctrine\DBAL\DriverManager::getConnection()` directly. The `Source` entity (`lib/Db/Source.php`) already models an external DB connection (`databaseUrl`, `type`, `authType`, `authConfig`, `targetRegister`…) but its DB fetch path was never implemented — only `RestApiSourceFetcher` exists. The credential custody seam (`lib/Service/Credential/CredentialStore` → `get/put/delete(uuid, scope)`, resolved via `CredentialStoreResolver::resolve()` — `DoriathCredentialStore` when the Doriath custody leaf is eligible, `NextcloudVaultCredentialStore` as fallback) is the ADR-004 custody home for secrets.

This change adds external SQL databases as a **new object-source provider** plus the introspection that turns SQL structure into JSON Schema. Every seam above is reused; the only core modification is a minimal, backward-compatible fix to `paginateObjectSource()` page/total metadata (D4b).

## Goals / Non-Goals

**Goals:**
- Connect to an external database over DBAL (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`) and expose it as a **read-only, live** virtual register: database = register, table/view = schema, row = object.
- Introspect columns → JSON-Schema properties, `NOT NULL` → `required`, foreign keys → canonical `$ref` relations, so validation and `_extend` work identically to native schemas.
- Keep DB credentials behind the ADR-004 custody seam (`CredentialStoreResolver` → Doriath leaf, NC vault fallback); never store a DB password in plaintext in a `Source` row or an OR object.
- Prevent SQL injection (parameterised `QueryBuilder` + platform identifier quoting) and IDOR (RBAC parity with native schemas; provider re-applies the schema's read authorization).
- Turn connection/introspection failures into 502/503 semantics, never a bare 500.

**Non-Goals:**
- No writes (create/update/delete) to virtual schemas in v1 — read-only.
- No copy/sync/materialisation — objects are served live only. (The existing harvest `Source` path is untouched.)
- No cross-database joins beyond single-column foreign-key `$ref` resolution via the existing `_extend` machinery.
- No connection pooling across requests (per-request cache only).
- Drivers beyond the three named (e.g. `pdo_sqlsrv`, `oci8`) are out of scope for v1.

## Declarative-vs-imperative decision (ADR-031)

ADR-031 prefers declarative configuration over imperative code. Here the split is:

- **Declarative (data):** the binding itself — a schema's `x-openregister-object-source` annotation `{provider: 'dbal-source', config: {sourceId, table, idColumn}}` — is pure schema data, produced by introspection and stored like any other schema config. Dispatch is data-driven through the existing registry. No imperative branch is added to `ObjectService`.
- **Imperative (services), justified under the ADR-031 external-integration exception:** talking to a *foreign* database — building a connection, running DBAL `SchemaManager` introspection, translating an OR query into parameterised SQL, mapping rows to `ObjectEntity` — is inherently imperative I/O against a system OpenRegister does not own. This mirrors the existing eight object-source providers and the `IntegrationProvider` query-time strategy (ADR-019), all of which are imperative for exactly this reason. A declarative mapping DSL for arbitrary SQL dialects would be more complex and less correct than DBAL's own platform abstraction. The imperative surface is confined to `lib/Service/Dbal/*` and one new provider; nothing else in the object read path changes.

## Mixed-spec rationale

This change is `kind: code`. It ships no `lib/Settings/*register*.json` seed as substance — the virtual register/schema are *produced at runtime by introspection*, not seeded from a config register. The only "config-shaped" artifact is the demo SQLite fixture + example introspected JSON under the change dir, used by tests and docs (see Seed Data). That is thin test glue, not a schema-register config chain, so the change stays single-kind `code`.

## Seed Data (ADR-001)

Seed data for this change is a **demo external database fixture**, not an OR register import:

- **`tests/fixtures/dbal/permits.sqlite`** (built by a small `tests/fixtures/dbal/build-permits-sqlite.php` so no binary is committed): a municipality permit-tracking DB with three related tables —
  - `permit_types(id PK, code, name, max_duration_days)`
  - `applicants(id PK, full_name, email, kvk_number NULL)`
  - `permits(id PK, applicant_id FK→applicants.id NOT NULL, permit_type_id FK→permit_types.id NOT NULL, reference, status, submitted_at, decided_at NULL)`
  - plus a view `active_permits` selecting `permits WHERE status = 'active'` to exercise view support.
- **`tests/fixtures/dbal/expected-introspection.json`**: the register + three-schema (+ view schema) JSON the introspection service must produce from that fixture, used as the golden output for `DatabaseIntrospectionService` tests (idColumn `id`, FK `$ref`s, `required` excluding PK/nullable/defaulted columns).

These fixtures are the demo path too: a reviewer points a `type: database` Source at `permits.sqlite`, introspects, and browses the three virtual schemas through the normal UI. Placeholder DSNs in docs use `postgres://user:PASS@host:5432/db` and the nil UUID `00000000-0000-0000-0000-000000000000`.

## Decisions

### D1 — Reuse the `Source` entity (type `database`), no new entity
A `Source` with `type = 'database'` holds non-secret connection metadata. **Rationale:** it already has the fields and lifecycle (controller, repository, RBAC). Alternative (new `DatabaseConnection` entity) duplicates all of that. **Change to current behaviour:** today `SourcesController` encrypts `databaseUrl` at rest with `ICrypto`. For `type = database` we instead store **non-secret** connection parts (driver, host, port, dbname, user) in the Source and custody the **password through the `CredentialStore` abstraction**, obtained via `CredentialStoreResolver::resolve()` — the **Doriath custody leaf** (`DoriathCredentialStore`) is the intended store for DB credentials, with `NextcloudVaultCredentialStore` as the fallback when Doriath is not eligible on the instance — keyed by a `credential` UUID referenced from the Source `authConfig`. This is full ADR-004 custody (secrets live in the custody leaf, not encrypted-in-a-row) and means a leaked DB row exposes no password. The `ICrypto` path stays for legacy harvest sources. The connection factory depends only on the `CredentialStore` interface; store selection stays entirely inside the resolver.

### D2 — `DbalConnectionFactory` with a per-request connection cache
`Source → resolve secret from CredentialStore → DriverManager::getConnection([...])`. Connections are cached per `sourceId` for the lifetime of the request only (no cross-request pool). **Rationale:** introspection and a paginated read may open the same source several times in one request; a persistent pool adds lifecycle/security complexity we don't need for v1. On decrypt/secret-missing errors the factory **fails closed** (throws a typed `DbalConnectionException`) — it never falls back to an unauthenticated connection.

### D3 — `DatabaseIntrospectionService` via DBAL `SchemaManager`
`Connection::createSchemaManager()` → `listTableNames()` + views, `introspectTable()/listTableColumns()/listTableForeignKeys()`. Produces a `Register` (`source = sourceId`) and one `Schema` per table/view, each tagged `x-openregister-object-source = {provider: 'dbal-source', config: {sourceId, table, idColumn}}`. Re-introspection **updates** existing schemas (matched by table name) rather than duplicating, applying changes as diffs through the existing `SchemaDiffService`/`SchemaVersioningService` path so drift is versioned and logged. Optionally emits an importable `Configuration` bundle (register + schemas) through the existing `ConfigurationService`/`ImportHandler`. **Rationale:** DBAL's `SchemaManager` already normalises dialect differences; reimplementing per-driver introspection would be error-prone.

### D3b — Scheduled re-introspection (drift detection) via BackgroundJob
A `DbalIntrospectionJob` (NC `TimedInterval`/`TimedJob`) re-introspects every `type: database` Source on a configurable interval, applies schema diffs via the `SchemaDiffService` path, and logs detected drift (added/dropped/retyped columns, new/removed tables). The job's `run()` performs the real work — no stub body (hydra stub-scan gate). A manual `introspect` action on `SourcesController` remains for on-demand refresh. **Rationale (user decision):** scheduled drift detection keeps virtual schemas honest against a database OpenRegister does not own; manual-only was rejected. Failures for one source are caught and logged so the job continues with the next source.

### D4 — `DbalObjectSourceProvider` translates OR query → parameterised `QueryBuilder`
Implements `ObjectSourceProvider` with `getId() = 'dbal-source'`. `findAll` builds `$conn->createQueryBuilder()->select(quoted cols)->from(quoted table)`, maps OR filters/search/sort to `where()/andWhere()/orderBy()` with **named/positional bound parameters only**, and applies `setMaxResults()/setFirstResult()` from the OR `limit/offset`. The provider honours `limit`/`offset` in SQL and exposes a real `count()` that issues `SELECT COUNT(*)` with the same WHERE; `paginateObjectSource()` consults it for the true total (see D4b). `find()` issues `WHERE <idColumn> = :id`. Rows → `ObjectEntity` (register/schema/uuid set from `idColumn`, `object` = column→property map). **Guardrails:** identifier quoting via `$platform->quoteIdentifier()`/QueryBuilder; a per-source **table allowlist** (only introspected tables may be queried); a hard result cap; read-only (no write methods exist); RBAC parity — the provider applies the same read authorization the native path would (denied → omitted / null, no enumeration oracle).

### D4b — Fix `paginateObjectSource()` page/total metadata for all providers
**User decision:** rather than documenting the `total = count($results)` / `pages: 1` behaviour as a limitation, extend `ObjectService::paginateObjectSource()` minimally and backward-compatibly: pass the query's `limit`/`offset` through to `findAll()` (already part of the query array), call the provider's existing `count()` (the interface already defines it) for the true total, and compute `page`/`pages`/`next`/`prev` from `total`+`limit`+`offset`. **Backward compatibility:** the existing 8 providers already implement `count()`; when a provider's `count()` throws or returns a value smaller than the returned window (signalling no real count support), the dispatch falls back to the current in-memory behaviour (`total = count($results)`, single page). No provider interface change; this fixes page metadata for ALL object-source providers, not just `dbal-source`.

### D5 — SQL type → JSON-Schema mapping (`SqlTypeMapper`)
Mapping keyed on DBAL `Types::*` (dialect-normalised), so one table covers MySQL/PostgreSQL/SQLite:

| DBAL type (`Doctrine\DBAL\Types\Types`) | JSON-Schema `type` | `format` | Extra |
|---|---|---|---|
| `STRING`, `ASCII_STRING` | `string` | — | `maxLength` from column length |
| `TEXT` | `string` | — | no maxLength |
| `GUID` | `string` | `uuid` | |
| `INTEGER`, `SMALLINT`, `BIGINT` | `integer` | — | BIGINT documented as JS-precision-lossy |
| `DECIMAL`, `FLOAT` | `number` | — | `DECIMAL` carries precision/scale in description |
| `BOOLEAN` | `boolean` | — | |
| `DATE_MUTABLE`/`DATE_IMMUTABLE` | `string` | `date` | |
| `TIME_MUTABLE`/`TIME_IMMUTABLE` | `string` | `time` | |
| `DATETIME_MUTABLE`/`DATETIMETZ_*`/`DATETIME_IMMUTABLE` | `string` | `date-time` | |
| `JSON` | `object` | — | free-form (no sub-schema) |
| `BINARY`, `BLOB` | `string` | `binary` | flagged unsupported for filtering |
| `SIMPLE_ARRAY`, `ARRAY` | `array` | — | items untyped |
| unknown/vendor-specific | `string` | — | fallback + logged warning (see D7) |

`NOT NULL` columns become `required`, **excluding** the primary key and any column with a database default (those are server-populated, so absence in a read projection is valid). Length → `maxLength`; DECIMAL precision/scale surfaced in `description`.

### D6 — Foreign keys → canonical relation dialect
A single-column FK from `permits.applicant_id` → `applicants.id` produces on the `permits` schema a property whose value stays the raw key but is typed as a relation per the hydra gate-31 / OR canonical dialect: `type: string`, `$ref` pointing at the related virtual schema, and `objectConfiguration.handling: 'related-object'` (`format` is carried over only when the column's own type mapping produced one — external primary keys are commonly plain integers/strings with no JSON-Schema format, and relation resolution keys on `$ref` + `handling`). The inverse side is added on the target schema with `inversedBy`. **Rationale:** this is exactly the shape `RenderObject`/`RelationHandler` resolve via `_extend`, so relation expansion and validation work with zero changes. Multi-column FKs are **not** mapped to `$ref` in v1 (documented under D7).

### D7 — Composite PK and unsupported types
- **Composite / no primary key:** a table with a composite PK gets a **synthesised deterministic id** = the ordered PK column values joined with a reserved separator (e.g. `col1::col2`), stored as the object `id`/`uuid`; the introspected schema records `idColumns: [...]` in its object-source config and the provider reconstructs the WHERE from the parts. A table with **no** PK at all is introspected as **read-list-only**: `findAll`/`count` work, but `find(id)` returns null and the schema is flagged (`x-openregister-object-source.config.idColumn = null`) — no stable object identity. Both cases are logged at introspection time. (Alternative — refuse composite-PK tables entirely — rejected as too restrictive for real municipal databases.)
- **Unsupported column types** (vendor geometry, enums, arrays of composite types): mapped to the `string` fallback with a logged warning and excluded from the filterable set; they are surfaced read-only as their string cast, never used in a WHERE.

### D8 — Error semantics
Connection refused / auth failure / driver missing → the provider catches the DBAL exception, logs it, and surfaces a **503 Service Unavailable** (source temporarily unreachable) or **502 Bad Gateway** (upstream DB returned an error) through the controller — never a bare 500. `isEnabled()` returns false when the DBAL driver extension is absent, degrading a bound schema to an empty list (matching existing provider behaviour). Introspection failures return a structured error on the `introspect` action, not a fatal.

## Risks / Trade-offs

- **[Pagination dispatch change touches all providers]** `paginateObjectSource()` is extended per D4b (decision, not a limitation): provider `count()` supplies the true total and limit/offset are pushed down. → Fallback to the current in-memory behaviour when a provider signals no count support keeps the existing 8 providers working unchanged; covered by a regression test over a native provider.
- **[Schema drift between scheduled runs]** the external DB can change shape between job runs. → `DbalIntrospectionJob` (D3b) re-introspects on an interval and applies diffs via `SchemaDiffService`; within a window, a dropped column yields a null property and a logged warning rather than a crash.
- **[Credential custody deviation from existing Source]** legacy harvest sources encrypt `databaseUrl` with `ICrypto`; virtual DB sources custody the password via `CredentialStoreResolver` (Doriath leaf, NC vault fallback). → Two code paths keyed on `type`; documented in D1, no migration of legacy rows.
- **[SQL injection]** any identifier/value reaching SQL. → No string interpolation anywhere; values are bound parameters, identifiers pass through `quoteIdentifier()` and are checked against the introspected allowlist.
- **[Untrusted external DB / host]** an admin could point a source at an internal host (SSRF-adjacent). → Only admins can create sources; document an optional host allowlist config; the connection is read-only and scoped to the named database. Fail closed on any credential-resolution error.
- **[BIGINT precision]** JS/JSON number precision. → Documented; BIGINT ids are carried as strings.

## Migration Plan

Additive, no data migration. Deploy = ship new services + provider + background job + routes; register the provider by adding `DbalObjectSourceProvider::class` to the `$providerClasses` list in `Application::bootObjectSourceProviders()`, its DI in `Application::register()`, and register `DbalIntrospectionJob`. The `paginateObjectSource()` change is backward-compatible (fallback path preserves current behaviour), so it needs no migration. Rollback = remove the provider and job from registration (bound schemas degrade to empty lists via the existing guard) and disable the `test-connection`/`introspect` routes; no schema/table changes to undo. Feature is inert until an admin creates a `type: database` Source and introspects it.

## Open Questions

None — all questions were decided with the user:

- Host allowlist: **advisory** config, documented; fail-closed on credentials regardless.
- Re-introspection: **scheduled background job** (`DbalIntrospectionJob`, D3b) plus the manual `introspect` action.
- Configuration bundle: introspection writes register/schemas **directly**; bundle export is an opt-in action.
- Credential custody: **Doriath leaf via `CredentialStoreResolver`**, NC vault fallback (D1).
- Pagination: **fix `paginateObjectSource()`** for all providers (D4b), backward-compatible fallback.
