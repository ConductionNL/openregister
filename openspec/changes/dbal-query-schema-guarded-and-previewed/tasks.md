# Tasks: dbal-query-schema-guarded-and-previewed

## 1. Guard and read-only reads

- [x] 1.1 `ReadOnlyQueryGuard::assert()` with the rules of design D-1. Verify: `tests/Unit/Service/Dbal/ReadOnlyQueryGuardTest.php` accepts a join and a `WITH`, refuses two statements, an `UPDATE` inside a CTE, `SELECT ... INTO`, `FOR UPDATE` and a placeholder, and accepts the keyword inside a string literal. (built: the guard class; strips comments and string/identifier literals first, then applies the one-statement / SELECT-or-WITH-head / no-forbidden-keyword / no-placeholder rules. Runs on schema save for `config.query` (ConfigurationValidator, fails the whole save; `tests/Unit/Db/Schema/ObjectSourceQueryGuardOnSaveTest.php`), on preview and on every query-backed read.)
- [ ] 1.2 Read-only transaction and statement timeout around every query-backed read, PostgreSQL and MariaDB. Verify: an integration test on both databases where a slow query is stopped by the timeout and a data-changing function call is refused by the database. (built: `ReadOnlyReadScope` wraps every query-backed list, count, find and aggregation read, and the preview, in a read-only transaction with a statement timeout that is rolled back; per-platform statements and a SQLite rollback are unit-tested; the guard now also runs on every query-backed read. NOT YET run: the integration test on live PostgreSQL and MariaDB.)

## 2. Preview

- [x] 2.1 `SourcesController::queryPreview()` and its route beside `sources#introspect`, administrator and organisation gate, 50 rows, columns with mapped types, fixed error sentences. Verify: `SourcesControllerTest` for 200, 403 for a non-administrator, 404 for another organisation's source, and a refused statement.

## 3. Spec, proof and docs

- [ ] 3.1 Add the query-backed requirement to `openspec/specs/dbal-virtual-registers/spec.md` at archive time.
- [x] 3.2 Newman against the test database: preview a join, save it as a schema, list its objects.
  - 2026-11-25: `tests/integration/openregister-query-backed-schema.postman_collection.json`, 16 requests and 23 assertions green on a sandbox Nextcloud 34 against the permits fixture (`tests/fixtures/dbal/build-permits-sqlite.php`, path passed as `permits_db_path`): the preview of a permits-to-types join answers the typed columns and both rows; an `UPDATE` is refused 400 naming the rule; a missing table answers 502 with `TableNotFoundException` and not the database's message; a non-administrator's preview is 403; the join saved as a schema lists both rows with `total` 2, filters on the joined `type` column and reads one object.
- [x] 3.3 Document query-backed schemas, the guard and the read-only connection advice in `docs/`.

Acceptance:
- No read of a query-backed schema can change data in the connected database.
