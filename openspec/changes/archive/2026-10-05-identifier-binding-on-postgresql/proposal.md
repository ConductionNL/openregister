# Proposal: a text identifier is never bound as an integer

## Why

The live pass of 5 Oct (defect O4) found `GET /api/views/{uuid}` answering 500 on PostgreSQL: `ViewMapper::find()` built `id = :p OR uuid = :q` and bound the uuid as an integer for the `id` side. PostgreSQL refuses that cast for the whole query (`invalid input syntax for type integer`), so the uuid side never runs. MySQL and SQLite cast silently, which is why only the PostgreSQL instance saw it. A grep of `lib/` for an `orX` that binds an `id` as `PARAM_INT` beside a `uuid` found one more: `SchemaMapper::loadSchema()`, which resolves `allOf`/`anyOf`/`oneOf` references by id, uuid or slug.

## What changes

- `ViewMapper::find()` compares `id` only when the value is an integer (or a string of digits); otherwise it compares `uuid` alone.
- `SchemaMapper::loadSchema()` compares `uuid` and `slug` always and `id` only for an integer.

## Impact

- `lib/Db/ViewMapper.php`, `lib/Db/SchemaMapper.php`.
- No API change: the same identifiers find the same rows; a uuid or slug no longer fails the query on PostgreSQL.
