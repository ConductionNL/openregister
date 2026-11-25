# Tasks: modelling-schema-exportable-flag

## 1. Schema

- [x] 1.1 Add `exportable` to `$boolFields`, fold a top-level `exportable` in `hydrate()`, and mirror it in `jsonSerialize()`. Verify: `tests/Unit/Db/SchemaTest.php` saves each place, both places with different values, and a string `"true"`, and reads both read places.
- [x] 1.2 Import keeps the flag. Verify: a `ConfigurationService` import test with a register fragment whose schema has top-level `exportable: true`.

## 2. Proof and docs

- [x] 2.1 Newman: `PUT /api/schemas/{id}` with `configuration.exportable: true`, then `GET` shows both places true.
  - Done (25 Nov): `tests/newman/openregister-schema-exportable-flag.postman_collection.json`, 5 requests and 14 assertions, 0 failed, against a sandbox Nextcloud 34 + OpenRegister main on MariaDB 10.11. It also pins that a schema saved without the flag reads `exportable: false` and that a top-level `false` clears it.
  - 2.2 stays open: no OpenRegister page sets `allowExport` (it is a `CnIndexPage` prop used by the leaf apps), so the Export menu this spec would assert has no host page in this repository.
- [ ] 2.2 Add `tests/e2e/ci/schema-exportable.spec.ts`: flag a schema, open an index page with `allowExport`, and assert the Export menu.
- [x] 2.3 Document the flag in `docs/` beside the schema configuration keys.

Acceptance:
- A schema saved without the flag reads `exportable: false` and is otherwise unchanged.
