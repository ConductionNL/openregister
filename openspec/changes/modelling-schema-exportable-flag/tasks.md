# Tasks: modelling-schema-exportable-flag

## 1. Schema

- [ ] 1.1 Add `exportable` to `$boolFields`, fold a top-level `exportable` in `hydrate()`, and mirror it in `jsonSerialize()`. Verify: `tests/Unit/Db/SchemaTest.php` saves each place, both places with different values, and a string `"true"`, and reads both read places.
- [ ] 1.2 Import keeps the flag. Verify: a `ConfigurationService` import test with a register fragment whose schema has top-level `exportable: true`.

## 2. Proof and docs

- [ ] 2.1 Newman: `PUT /api/schemas/{id}` with `configuration.exportable: true`, then `GET` shows both places true.
- [ ] 2.2 Add `tests/e2e/ci/schema-exportable.spec.ts`: flag a schema, open an index page with `allowExport`, and assert the Export menu.
- [ ] 2.3 Document the flag in `docs/` beside the schema configuration keys.

Acceptance:
- A schema saved without the flag reads `exportable: false` and is otherwise unchanged.
