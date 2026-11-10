# Tasks

## 1. Prove the defect before fixing it

- [ ] Write a failing test asserting `searchObjectsBySlug('document','anonymizationLink')` resolves schema `9177`, not `5084` — and confirm it FAILS against unmodified code first
- [ ] Write a failing test asserting a register-scoped miss throws instead of returning a foreign schema

Acceptance criteria:
- Both tests fail before any production code changes. A test that passes on unmodified code is measuring nothing.
- The assertions check the resolved schema **id**, not merely that the call succeeded — success is the pre-fix behaviour too.

## 2. The refusal

- [ ] Add `SchemaNotInRegisterException extends DoesNotExistException`, carrying register id, register slug, requested slug and same-slug candidate count
- [ ] Replace the global fallback with a throw in `ObjectService::setSchema()`
- [ ] Replace the global fallback with a throw in `ObjectService::searchObjectsBySlug()`
- [ ] Replace the global fallback with a throw in `Service\Flow\Nodes\ObjectWriteNode`
- [ ] Replace the global fallback with a throw in `Service\Flow\Nodes\ObjectReadNode`
- [ ] Replace the global fallback with a throw in `Controller\SchemasController`
- [ ] Verify no register-scoped call site retains a fallback — grep every `findBySlugInIds` caller and confirm each one throws

Acceptance criteria:
- Register-less callers still reach `SchemaMapper::find()` unchanged.
- Numeric ids and uuids bypass scoping entirely; only slug resolution is scoped.
- The exception message names the register, the slug, the candidate count, and the repair command.

## 3. Linkage repair

- [ ] Add `RegisterSchemaLinkageRepairService` reconstructing a register's schema ids from `oc_openregister_table_<reg>_<schema>` names, reporting live row count per id
- [ ] Add a platform switch so the catalogue query works on both Postgres and MySQL, and mark the MySQL arm unverified if it is not exercised
- [ ] Add `occ openregister:registers:relink-schemas` — dry-run by default, `--write` to apply, `--register=<id>` to target one
- [ ] Make the repair strictly additive — never remove an id already in a register's list

Acceptance criteria:
- Default invocation mutates nothing and prints register, ids-to-add and row counts.
- `--write` applies exactly what the dry run printed.
- A register with no physical tables yields zero recoverable ids and no guessing from slug or ownership.

## 4. Tests

- [ ] Unit tests for all five call sites: scoped hit resolves the register's schema, scoped miss throws, register-less caller unchanged, numeric id unaffected
- [ ] Unit tests for the repair: reconstruction from table names, additive-only, empty-evidence case, dry-run mutates nothing
- [ ] Playwright E2E proving the fix end to end against the real instance — read `anonymizationLink` from register `document` and get the 4 rows that are currently unreachable

Acceptance criteria:
- The E2E asserts the four rows are returned. Today it returns zero, so the test distinguishes fixed from broken.
- `composer check:strict` passes; hydra gates measured against the base run, not against zero.

## 5. Verify on real data

- [ ] Run the dry run against the development instance and confirm it reports 17 registers, register `6` gaining `9173`/`9174`/`9177`
- [ ] Apply `--write` to register `6` only, then confirm the slug read returns the 4 rows

Acceptance criteria:
- The measured numbers match the proposal's table, or the proposal is corrected to match reality.
- Registers other than `6` are untouched by the targeted write.
