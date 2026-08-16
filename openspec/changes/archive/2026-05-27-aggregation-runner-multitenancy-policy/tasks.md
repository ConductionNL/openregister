## 1. Align AggregationRunner with the metadata-read bypass policy

- [x] 1.1 Update `lib/Service/Aggregation/AggregationRunner.php::loadSchema()` (around L1924-L1934) to call `$this->schemaMapper->find($schemaRef, _multitenancy: false)`.
- [x] 1.2 Replace the misleading `// SECURITY: keep multitenancy filter on so a tenant user cannot resolve schemas owned by another tenant simply by knowing the slug.` comment with a corrected rationale that references the new `auth-system` requirement and notes that tenant isolation lives at the object-row level via `MultiTenancyTrait`.
- [x] 1.3 Update `lib/Service/Aggregation/AggregationRunner.php::loadRegister()` (around L1945-L1953) to call `$this->registerMapper->find($registerRef, _multitenancy: false)` and replace the `// SECURITY: keep multitenancy filter on (see loadSchema).` comment with the corrected rationale.
- [x] 1.4 Confirm via grep that `loadSchema` / `loadRegister` are the only schema/register lookups inside `lib/Service/Aggregation/` and that no other in-runner read path needs updating.

## 2. Sweep SchemasController read paths for consistency

- [x] 2.1 Update `lib/Controller/SchemasController.php::download()` (L938) to call `$this->schemaMapper->find($id, _multitenancy: false)`.
- [x] 2.2 Update `lib/Controller/SchemasController.php::related()` (L972 for the target lookup AND L974 for the `findAll()`) to pass `_multitenancy: false`.
- [x] 2.3 Update `lib/Controller/SchemasController.php::stats()` (L1031) to call `$this->schemaMapper->find($id, _multitenancy: false)`.
- [x] 2.4 Update `lib/Controller/SchemasController.php::publish()` (L1211) to call `$this->schemaMapper->find($id, _multitenancy: false)` for the read step. The subsequent mutation (`$this->schemaMapper->update($schema)`) is unchanged.
- [x] 2.5 Update `lib/Controller/SchemasController.php::depublish()` (L1297) to call `$this->schemaMapper->find($id, _multitenancy: false)` for the read step. The subsequent mutation is unchanged.
- [x] 2.6 Leave `lib/Controller/SchemasController.php::update()` (L535) at default `_multitenancy: true` — mutation-gating lookup MUST enforce tenancy per the spec.
- [x] 2.7 Leave `lib/Controller/SchemasController.php::destroy()` (L689) at default `_multitenancy: true` — mutation-gating lookup MUST enforce tenancy per the spec.
- [x] 2.8 Leave `lib/Controller/SchemasController.php::upload()` (L811) at default `_multitenancy: true` — mutation-gating lookup MUST enforce tenancy per the spec.
- [x] 2.9 Add an inline comment on each updated read-path lookup referencing the `auth-system` requirement name so future readers know why the bypass is intentional.

## 3. PHPUnit coverage

- [x] 3.1 Add a PHPUnit test under `tests/Unit/Service/Aggregation/AggregationRunnerTest.php` (create if missing) asserting that `loadSchema` invokes `SchemaMapper::find` with `_multitenancy === false`. Use a mock SchemaMapper and verify the argument.
- [x] 3.2 Add a parallel test for `loadRegister` against a mock RegisterMapper.
- [x] 3.3 Add a PHPUnit test under `tests/Unit/Controller/SchemasControllerTest.php` for each of the five read paths (`download`, `related`, `stats`, `publish`, `depublish`) asserting `_multitenancy === false` is passed to `SchemaMapper::find`.
- [x] 3.4 Add a PHPUnit test asserting `update`, `destroy`, and `upload` still pass the default `_multitenancy: true` to `SchemaMapper::find` so the safe-mutation default is locked in by a test.
- [x] 3.5 Add a PHPUnit test for the unknown-ref 404 path: `AggregationRunner::loadSchema('does-not-exist')` MUST throw `RuntimeException` with message exactly `Schema "does-not-exist" not found.` after `SchemaMapper::find` raises `DoesNotExistException`.

## 4. Quality gates

- [x] 4.1 Run `composer check:strict` and resolve any new PHPCS / PHPMD / PHPStan / Psalm findings introduced by the change. Per `feedback_fix-all-issues-encountered.md`, also fix any pre-existing issues that surface on the touched files.
- [x] 4.2 Run the full PHPUnit suite and ensure the new tests pass alongside the existing suite (no regressions). Per `feedback_no-mock-fixes-real-functionality.md`, no shared stub edits to make existing tests pass — fix code or change assertions to behaviour.

## 5. Newman verification (the original triage finding)

- [x] 5.1 Run the `platform-annotations` Newman collection against the dev environment and confirm the 5 aggregation assertions previously failing with `Schema "<ref>" not found` now go GREEN.
- [x] 5.2 Capture the before/after Newman assertion counts (count of failing assertions before this change vs after) in the apply summary.
- [x] 5.3 Spot-check one aggregation endpoint manually as the admin user against a schema with a concrete `organisation` value to confirm the runtime behaviour matches the spec scenarios (`Admin without active organisation runs an aggregation that resolves a schema by ref`).

## 6. Spec coverage and archival prep

- [x] 6.1 Run `python .github/check_spec_coverage.py` (or the project's equivalent gate-16 invocation) to confirm new code references are spec-linked. Annotate any genuinely-plumbing helpers with `@spec exclude <reason>` per the strict-coverage policy.
- [x] 6.2 Re-read `openspec/specs/auth-system/spec.md` after the delta is archived (post-merge) to confirm the new requirement reads correctly inline with the existing multi-tenancy requirements.
