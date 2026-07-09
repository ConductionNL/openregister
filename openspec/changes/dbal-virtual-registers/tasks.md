## 1. Connection factory & credential custody

- [x] 1.1 Add `DbalConnectionFactory` (`lib/Service/Dbal/DbalConnectionFactory.php`): resolve a `type: database` Source → password via `CredentialStoreResolver::resolve()->get(uuid, scope)` (Doriath custody leaf when eligible, NC vault fallback — ADR-004) → `DriverManager::getConnection([...])`; per-request connection cache keyed by sourceId; fail closed (typed `DbalConnectionException`) on decrypt/secret-missing; drivers restricted to `pdo_mysql`/`pdo_pgsql`/`pdo_sqlite`.
  - Acceptance: connecting to the SQLite fixture succeeds; a source with an unresolvable credential throws, never opens an unauthenticated connection; password never appears in logs; factory depends only on the `CredentialStore` interface.
- [x] 1.2 Wire `Source` type `database` to store non-secret metadata (driver/host/port/dbname/user) + credential UUID in `authConfig`; keep the password in the credential custody seam, not in `databaseUrl`/`ICrypto`. Document the two-path split (legacy harvest vs. virtual DB) in code.
  - Acceptance: a database Source round-trips with no plaintext password persisted in the row or any OR object.

## 2. Seed data (demo fixture)

- [x] 2.1 Add `tests/fixtures/dbal/build-permits-sqlite.php` that builds `permits.sqlite` (tables `permit_types`, `applicants`, `permits` with FKs + view `active_permits`) and `tests/fixtures/dbal/expected-introspection.json` (golden register+schemas). Do not commit a binary DB — generate it in the test bootstrap.
  - Acceptance: running the builder produces a SQLite DB whose introspection matches the golden JSON.

## 3. Introspection & mapping

- [x] 3.1 Add `SqlTypeMapper` (`lib/Service/Dbal/SqlTypeMapper.php`): DBAL `Types::*` → JSON-Schema `type`/`format`/`maxLength`/precision per the design table; unknown types → `string` fallback + logged warning.
  - Acceptance: unit tests cover every row of the mapping table for mysql/pgsql/sqlite type names.
- [x] 3.2 Add `DatabaseIntrospectionService` (`lib/Service/Dbal/DatabaseIntrospectionService.php`): `createSchemaManager()` → Register (`source=sourceId`) + one Schema per table/view; `NOT NULL` (non-PK, non-defaulted) → `required`; single-column PK → `idColumn`; tag each schema `x-openregister-object-source = {provider:'dbal-source', config:{sourceId, table, idColumn}}`; re-introspection updates in place.
  - Acceptance: introspecting the fixture yields schemas matching `expected-introspection.json`; a second run creates no duplicates.
- [x] 3.3 Map foreign keys to the canonical relation dialect (`type:string` + `format` + `$ref` + `objectConfiguration.handling: related-object`), add inverse side with `inversedBy`; skip multi-column FKs.
  - Acceptance: `permits.applicant_id` gains a `$ref` to `applicants` and `_extend` resolves it via the existing RelationHandler.
- [x] 3.4 Handle composite PK (deterministic ordered-join id + `idColumns` in config) and no-PK tables (list-only, `idColumn` null); log both at introspection.
  - Acceptance: composite-PK table `find` reconstructs the predicate; no-PK table `find(id)` returns null while `findAll`/`count` work.
- [x] 3.5 Add `DbalIntrospectionJob` (NC `TimedJob`, configurable interval): re-introspect every `type: database` source, apply diffs via `SchemaDiffService`, log drift; per-source failures caught and logged so the job continues; `run()` does the real work (no stub body — hydra stub-scan gate); register the job.
  - Acceptance: invoking the job against the fixture after adding a column applies the diff and logs the drift; an unreachable source does not block the others.

## 4. Object-source provider

- [x] 4.1 Add `DbalObjectSourceProvider implements ObjectSourceProvider` (`lib/Service/ObjectSource/DbalObjectSourceProvider.php`): `getId()='dbal-source'`, `isEnabled()` false when the driver extension is absent.
  - Acceptance: provider satisfies the interface signatures verified against `ObjectSourceProvider.php`.
- [x] 4.2 Implement `findAll`/`find`/`count`: OR query → parameterised `QueryBuilder` (filters/search/sort as bound params), `setMaxResults()/setFirstResult()` for limit/offset, `count()` via `SELECT COUNT(*)` with the same WHERE; identifier quoting via the platform; per-source table allowlist; hard result cap; rows → `ObjectEntity`.
  - Acceptance: a filter value with SQL metacharacters is matched literally; limit/offset applied in SQL; count reflects the full predicate.
- [x] 4.3 Enforce read authorization parity (denied/absent both null in `find`, denied omitted in `findAll`) and read-only (writes rejected via the existing object-source path).
  - Acceptance: null-return contract holds; a write to a `dbal-source` schema is rejected.
- [x] 4.4 Map DBAL connection/query exceptions to 502/503 semantics (never a bare 500); log warnings; degrade to empty list when `isEnabled()` is false.
  - Acceptance: a stubbed connection exception surfaces a 503-class error, not a 500.
- [x] 4.5 Extend `ObjectService::paginateObjectSource()` (design D4b): pass limit/offset through to `findAll()`, consult the provider's existing `count()` for the true total, compute `page`/`pages`/`next`/`prev`; fall back to the current in-memory behaviour when a provider signals no count support. Minimal and backward-compatible — no interface change.
  - Acceptance: page-2/limit-50 over a 120-row fixture table returns total 120, pages 3; a regression test over a native provider (and a no-count stub) shows unchanged fallback behaviour.

## 5. Registration & routes

- [x] 5.1 Register `DbalObjectSourceProvider` DI in `Application::register()` and add it to the `$providerClasses` list in `Application::bootObjectSourceProviders()`; register the new `lib/Service/Dbal/*` services.
  - Acceptance: the provider is resolvable by `ObjectSourceRegistry::get('dbal-source')` at runtime.
- [x] 5.2 Add `test-connection` and `introspect` actions to `SourcesController` + `appinfo/routes.php` (admin-only, correct NC auth attributes); responses never expose the password.
  - Acceptance: routes are reachable, admin-guarded, and covered by route-auth/route-reachability gates.

## 6. UI (minimal)

- [x] 6.1 Add a minimal add-database-source flow: driver/host/db/user + credential selection, a "Test connection" button, table selection, and "Create virtual register" (introspect). Modals in their own files; `NcSelect` with `inputLabel`.
  - Acceptance: an admin can add the SQLite-fixture source, test it, pick tables, and browse the resulting virtual schemas through the standard objects UI.

## 7. Tests & quality gates

- [x] 7.1 Unit tests: `SqlTypeMapper` (all mapping rows), `DbalConnectionFactory` fail-closed, provider query-translation + injection-literal + count.
  - Acceptance: tests pass; each ADDED spec scenario is traceable to a test or carries an `@e2e exclude`.
- [x] 7.2 Integration test: introspect the SQLite fixture and assert against `expected-introspection.json`; read objects and resolve a relation via `_extend`.
  - Acceptance: golden-file assertion passes.
- [ ] 7.3 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Hydra mechanical gates; fix all findings including any pre-existing issues touched.
  - Acceptance: `composer check:strict` and the gates pass green.
