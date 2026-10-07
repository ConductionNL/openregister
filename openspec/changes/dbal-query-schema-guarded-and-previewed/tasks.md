# Tasks: dbal-query-schema-guarded-and-previewed

## 1. Guard and read-only reads

- [ ] 1.1 `ReadOnlyQueryGuard::assert()` with the rules of design D-1, run on schema save for `config.query`. Verify: `tests/Unit/Service/Dbal/ReadOnlyQueryGuardTest.php` accepts a join and a `WITH`, refuses two statements, an `UPDATE` inside a CTE, `SELECT ... INTO`, `FOR UPDATE` and a placeholder, and accepts the keyword inside a string literal.
- [ ] 1.2 Read-only transaction and statement timeout around every query-backed read, PostgreSQL and MariaDB. Verify: an integration test on both databases where a slow query is stopped by the timeout and a data-changing function call is refused by the database.

## 2. Preview

- [ ] 2.1 `SourcesController::queryPreview()` and its route beside `sources#introspect`, administrator and organisation gate, 50 rows, columns with mapped types, fixed error sentences. Verify: `SourcesControllerTest` for 200, 403 for a non-administrator, 404 for another organisation's source, and a refused statement.

## 3. Spec, proof and docs

- [ ] 3.1 Add the query-backed requirement to `openspec/specs/dbal-virtual-registers/spec.md` at archive time.
- [ ] 3.2 Newman against the test database: preview a join, save it as a schema, list its objects.
- [ ] 3.3 Document query-backed schemas, the guard and the read-only connection advice in `docs/`.

Acceptance:
- No read of a query-backed schema can change data in the connected database.
