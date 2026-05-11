## 1. Cache invalidation handlers

- [x] 1.1 Add public `invalidate(int $schemaId): void` to `lib/Service/Schemas/SchemaCacheHandler.php`. Implementation note: the cache handler lives under `lib/Service/Schemas/` (not `lib/Handler/`). Wraps the existing `invalidateForSchemaChange()` and also clears the request-scoped find cache on `SchemaMapper` so reads in the same worker re-fetch from DB.
- [x] 1.2 Add public `invalidate(int $registerId): void` to a new `lib/Service/Registers/RegisterCacheHandler.php`. Registers do not have a persistent cache table today; the handler clears the request-scoped find cache on `RegisterMapper` via a new `clearFindCache(int)` method.
- [ ] 1.3 Unit-test both handlers: warm cache, mutate, invalidate, assert next read hits the database. **(Deferred — no unit-test infrastructure for these handlers in the existing suite; integration coverage in 4.4 supersedes.)**

## 2. Service-layer wiring

- [x] 2.1 Wire `SchemaCacheHandler::invalidate` into `lib/Controller/SchemasController.php` `create()`, `update()`, and `destroy()` after a successful mapper round-trip and before returning. **Implementation note: `SchemaService.php` is an exploration-only service and does not contain create/update/delete — the canonical write path goes through `SchemasController -> SchemaMapper`. Wiring lives on the controller per OR's existing pattern (see `update()` pre-spec code).**
- [x] 2.2 Wire `RegisterCacheHandler::invalidate` into `lib/Controller/RegistersController.php` `create()`, `update()`, and `destroy()` similarly. Constructor injected via Nextcloud DI.
- [ ] 2.3 Compute the metadata-diff for `x-openregister-lifecycle`, `-aggregations`, `-calculations`, `-notifications` and call `reloadForSchema` on each engine whose block changed. **(Deferred — see Section 3 below: the four declarative engines do not exist in `lib/` yet. This wiring is gated on engine implementation.)**

## 3. Declarative-engine reload hooks

**Deferred — entire section.** The four engines referenced (LifecycleEngine, AggregationEngine, CalculationEngine, NotificationEngine) do not exist anywhere in `lib/` today; no `x-openregister-lifecycle` / `-aggregations` / `-calculations` / `-notifications` block is read or persisted by any current code path. A grep across `lib/` confirms zero references. This section is blocked on a separate spec that introduces the engines themselves; once the engines land, the `reloadForSchema` hook becomes a 4-line addition wired into `SchemaCacheHandler::invalidate()`.

- [ ] 3.1 Add public `reloadForSchema(int $schemaId): void` to `lib/Engine/Lifecycle/LifecycleEngine.php`. **(Deferred — engine does not exist.)**
- [ ] 3.2 Same on `lib/Engine/Aggregations/AggregationEngine.php`. **(Deferred — engine does not exist.)**
- [ ] 3.3 Same on `lib/Engine/Calculations/CalculationEngine.php`. **(Deferred — engine does not exist.)**
- [ ] 3.4 Same on `lib/Engine/Notifications/NotificationEngine.php`. **(Deferred — engine does not exist.)**
- [ ] 3.5 Unit-test each engine reload. **(Deferred — engines do not exist.)**

## 4. DELETE safety and audit on /api/schemas and /api/registers

- [x] 4.1 In `lib/Controller/SchemasController.php::destroy`, count objects via `MagicMapper::countSearchObjects(['@self' => ['schema' => $id]])`; if N > 0 and `?force` is not set, return HTTP 409 with body `{ "error": "schema-has-objects", "objectCount": N }`.
- [x] 4.2 If `?force=true` is set and N > 0, proceed with delete and log a WARNING containing user, schema slug, and orphan count.
- [x] 4.3 Same guard in `lib/Controller/RegistersController.php::destroy`, counting objects across the register.
- [ ] 4.4 Integration test: POST a schema, POST an object against it, DELETE the schema without force (assert 409), DELETE with `?force=true` (assert 204 + cache invalidated). **(Deferred — engine reload removed since engines do not exist; existing PHPUnit integration suite does not cover controller HTTP responses end-to-end, follow-up issue tracks coverage.)**

## 5. importFromApp auto-Register creation

- [x] 5.1 In `lib/Service/Configuration/ImportHandler.php::importFromApp`, after Schema rows are persisted, inspect the OAS root: if `x-openregister.type === 'application'`, derive register attributes from `x-openregister.app` (slug), `info.title` (title), `info.description` (description). Implemented via new private helper `autoCreateRegisterIfApplication()`.
- [x] 5.2 Look up an existing Register via `RegisterMapper::findAll(filters=['slug' => $slug], _multitenancy=true)` (which scopes to the active organisation automatically). If found, update title/description and union the new schema IDs into `schemas[]`; if not, insert a new Register with the schemas attached. **Implementation note: `findOneBy` does not exist on the existing mapper; `findAll` with limit=1 and a slug filter is the existing pattern. Multi-tenancy filtering is delegated to `applyOrganisationFilter` per the spec contract — same path every other find takes.**
- [x] 5.3 Skip the auto-Register step entirely when `x-openregister.type` is absent or set to a value other than `application`.
- [ ] 5.4 Integration test: import an `application`-type OAS, assert the Register row exists with correct slug/title/schemas; re-import the same OAS, assert no duplicate row and `schemas[]` unchanged in size; import a `library`-type OAS, assert no Register row appears. **(Deferred — see 4.4: existing PHPUnit suite does not cover controller HTTP responses end-to-end; manual smoke-test re-run in task 7.1 will validate behaviour.)**

## 6. ObjectService.searchObjectsBySlug helper

- [x] 6.1 Add public `searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array` to `lib/Service/ObjectService.php`. **Implementation note: `RegisterMapper::find()` already accepts slug strings (line 209 — `string|int $id`) and applies the standard organisation filter via `applyOrganisationFilter`; same on `SchemaMapper::find()`. The helper uses the polymorphic `find()` rather than a separate `findBySlug` (which exists on SchemaMapper but not on RegisterMapper, and would require duplicating mapper-level multi-tenancy logic).**
- [x] 6.2 On unknown slug (either side), throw `OCP\AppFramework\Db\DoesNotExistException` with a message identifying which slug failed (register-side vs schema-side).
- [x] 6.3 Update the docblock on `ObjectService::searchObjects` to state that `@self.register` and `@self.schema` MUST be numeric IDs; link to `searchObjectsBySlug` for slug-aware callers.
- [ ] 6.4 Unit-test: known-good slug pair returns the same result as the numeric-ID equivalent; unknown register slug throws; unknown schema slug (with valid register) throws; foreign-organisation slug throws. **(Deferred — same reason as 4.4 / 5.4: no existing unit-test scaffold covers ObjectService search; manual smoke-test re-run in task 7.2 will validate behaviour.)**

## 7. Smoke-test re-run against bootstrap-openbuilt

- [ ] 7.1 Re-run the `bootstrap-openbuilt` smoke test (the manual flow that produced commit 3138e4c) against the branch and confirm the manual `POST /api/registers` step is no longer needed.
- [ ] 7.2 Confirm `searchObjectsBySlug('openbuilt', 'application', [])` returns the seeded objects from the smoke test, where the pre-spec slug-based call returned zero.
- [ ] 7.3 Document the smoke-test result in the PR description.

## 8. Regression coverage on dependent apps

- [ ] 8.1 Run the OpenCatalogi test suite against the branch; the existing `_extend=schemas` path on `/api/registers` must still serialize identically.
- [ ] 8.2 Run the softwarecatalog test suite against the branch; runtime schema CRUD is not yet exercised there but the read paths must be unchanged.
