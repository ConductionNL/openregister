# Tasks: identifier-binding-on-postgresql

## 1. Build

- [x] 1.1 `ViewMapper::find()`: compare `id` only for an integer; a uuid compares `uuid` alone.
- [x] 1.2 `SchemaMapper::loadSchema()`: compare `id` only for an integer; `uuid` and `slug` always.
- [x] 1.3 Grep the other mappers for an `id` bound as `PARAM_INT` beside a `uuid` in one `orX`; none left.

## 2. Tests

- [x] 2.1 `tests/Unit/Db/IdOrUuidBindingTest.php`: records every bound parameter and fails on text bound as an integer (red on development for the view uuid and the schema slug).
