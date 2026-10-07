# Design: dbal-query-schema-guarded-and-previewed

Read at openregister development 555af7212.

## Context

- `DbalObjectSourceProvider` (`lib/Service/ObjectSource/DbalObjectSourceProvider.php`)
  reads `config.query` (`isQueryBacked()`, `:1080-1083`) and emits it verbatim
  as `FROM (<query>) or_src` (`fromExpression()`, `:1099-1105`). Its docblock
  (`:1089-1092`): the query "MUST be trusted config authored by an
  administrator, never request input. It is read-only: writes are rejected in
  `writeContext()`" (`:1362`). Reads cap at `MAX_RESULTS = 1000` (`:75`).
- Shipped by #2043 (12a52c7fc); `openspec/specs/dbal-virtual-registers/spec.md`
  describes table-backed schemas and does not mention `query`.
- `SourcesController::introspect()` (`lib/Controller/SourcesController.php:602-610`)
  is administrator-only and organisation-scoped through `SourceMapper::find()`.
- No statement check, read-only transaction or statement timeout exists for
  these reads.

## D-1: a conservative statement guard

`ReadOnlyQueryGuard::assert(string $sql, string $platform)`:

1. strip comments and string literals into placeholders;
2. refuse a `;` that is not the last character, so one statement only;
3. require the first keyword to be `SELECT` or `WITH`;
4. refuse the keywords `INSERT`, `UPDATE`, `DELETE`, `MERGE`, `UPSERT`,
   `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `GRANT`, `REVOKE`, `COPY`, `CALL`,
   `EXECUTE`, `SET`, `LOCK`, `INTO` and `FOR UPDATE`, anywhere outside
   literals;
5. refuse `?` or `:name` placeholders, because the derived table binds none.

It is a guard, not the only defence: D-2 is. The guard runs on schema save and
on preview, and its refusal names the rule that failed.

## D-2: the database enforces read-only

Each read of a query-backed schema opens a transaction and sets it read-only
(`SET TRANSACTION READ ONLY` on PostgreSQL, `START TRANSACTION READ ONLY` on
MariaDB), with a statement timeout (`SET LOCAL statement_timeout` on
PostgreSQL, `max_statement_time` on MariaDB), and rolls back after reading. A
statement the guard missed still cannot change data. The connection user
should also be read-only; the docs say so.

## D-3: the preview

`queryPreview(id)` checks the administrator and loads the source like
`introspect()`, runs the guard, then reads `SELECT * FROM (<query>) or_src`
with limit 50 under D-2, and answers `{ columns: [{ name, type }], rows }`,
mapping types with `SqlTypeMapper`. Errors answer a fixed sentence with the
database's error class, not its message, which can carry schema details of
other tables.

## D-4: the spec catches up

`dbal-virtual-registers` gains the query-backed requirement, so the shipped
feature is specified, not only coded.
