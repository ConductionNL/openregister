## 1. Cache invalidation handlers

- [ ] 1.1 Add public `invalidate(int $schemaId): void` to `lib/Handler/SchemaCacheHandler.php`; bump per-handler version counter so `getAll()` re-fetches from the database on the next call.
- [ ] 1.2 Add public `invalidate(int $registerId): void` to `lib/Handler/RegisterCacheHandler.php` with the same semantics.
- [ ] 1.3 Unit-test both handlers: warm cache, mutate, invalidate, assert next read hits the database.

## 2. Service-layer wiring

- [ ] 2.1 Update `lib/Service/SchemaService.php` (`create`, `update`, `delete`) to call `SchemaCacheHandler::invalidate` after a successful mapper round-trip and before returning.
- [ ] 2.2 Update `lib/Service/RegisterService.php` (`create`, `update`, `delete`) to call `RegisterCacheHandler::invalidate` similarly.
- [ ] 2.3 In `SchemaService::update` and `delete`, compute the metadata-diff for `x-openregister-lifecycle`, `-aggregations`, `-calculations`, `-notifications` and call `reloadForSchema` on each engine whose block changed.

## 3. Declarative-engine reload hooks

- [ ] 3.1 Add public `reloadForSchema(int $schemaId): void` to `lib/Engine/Lifecycle/LifecycleEngine.php`; re-read the schema's `x-openregister-lifecycle` block from the mapper and replace the engine's registry entry for that schema ID (or drop it if absent on delete).
- [ ] 3.2 Same on `lib/Engine/Aggregations/AggregationEngine.php` for `x-openregister-aggregations`.
- [ ] 3.3 Same on `lib/Engine/Calculations/CalculationEngine.php` for `x-openregister-calculations`.
- [ ] 3.4 Same on `lib/Engine/Notifications/NotificationEngine.php` for `x-openregister-notifications`.
- [ ] 3.5 Unit-test each engine reload: bootstrap with metadata A, call `reloadForSchema` after persisting metadata B in the mapper, assert the engine now resolves B.

## 4. DELETE safety and audit on /api/schemas and /api/registers

- [ ] 4.1 In `lib/Controller/SchemasController.php::destroy`, count objects via `ObjectService::count(['@self.schema' => $id])`; if N > 0 and `?force` is not set, return HTTP 409 with body `{ "error": "schema-has-objects", "objectCount": N }`.
- [ ] 4.2 If `?force=true` is set and N > 0, proceed with delete and log a WARNING containing user, schema slug, and orphan count.
- [ ] 4.3 Same guard in `lib/Controller/RegistersController.php::destroy`, counting objects across all attached schemas.
- [ ] 4.4 Integration test: POST a schema, POST an object against it, DELETE the schema without force (assert 409), DELETE with `?force=true` (assert 204 + cache invalidated + engines reloaded).

## 5. importFromApp auto-Register creation

- [ ] 5.1 In `lib/Handler/ImportHandler.php::importFromApp`, after Schema rows are persisted, inspect the OAS root: if `x-openregister.type === 'application'`, derive register attributes from `x-openregister.app` (slug), `info.title` (title), `info.description` (description).
- [ ] 5.2 Look up an existing Register via `RegisterMapper::findOneBy(['slug' => $slug, 'organisationId' => $orgId])`. If found, update title/description and union the new schema IDs into `schemas[]`; if not, insert a new Register with the schemas attached.
- [ ] 5.3 Skip the auto-Register step entirely when `x-openregister.type` is absent or set to a value other than `application`.
- [ ] 5.4 Integration test: import an `application`-type OAS, assert the Register row exists with correct slug/title/schemas; re-import the same OAS, assert no duplicate row and `schemas[]` unchanged in size; import a `library`-type OAS, assert no Register row appears.

## 6. ObjectService.searchObjectsBySlug helper

- [ ] 6.1 Add public `searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array` to `lib/Service/ObjectService.php`. Resolve `$registerSlug` via `RegisterMapper::findBySlug($registerSlug, $orgId)`, resolve `$schemaSlug` via `SchemaMapper::findBySlug($schemaSlug, $orgId)`, then merge `@self.register` and `@self.schema` numeric IDs into `$filters` and delegate to `searchObjects($filters)`.
- [ ] 6.2 On unknown slug (either side), throw `OCP\AppFramework\Db\DoesNotExistException` with a message identifying which slug failed.
- [ ] 6.3 Update the docblock on `ObjectService::searchObjects` to state that `@self.register` and `@self.schema` MUST be numeric IDs; link to `searchObjectsBySlug` for slug-aware callers.
- [ ] 6.4 Unit-test: known-good slug pair returns the same result as the numeric-ID equivalent; unknown register slug throws; unknown schema slug (with valid register) throws; foreign-organisation slug throws.

## 7. Smoke-test re-run against bootstrap-openbuilt

- [ ] 7.1 Re-run the `bootstrap-openbuilt` smoke test (the manual flow that produced commit 3138e4c) against the branch and confirm the manual `POST /api/registers` step is no longer needed.
- [ ] 7.2 Confirm `searchObjectsBySlug('openbuilt', 'application', [])` returns the seeded objects from the smoke test, where the pre-spec slug-based call returned zero.
- [ ] 7.3 Document the smoke-test result in the PR description.

## 8. Regression coverage on dependent apps

- [ ] 8.1 Run the OpenCatalogi test suite against the branch; the existing `_extend=schemas` path on `/api/registers` must still serialize identically.
- [ ] 8.2 Run the softwarecatalog test suite against the branch; runtime schema CRUD is not yet exercised there but the read paths must be unchanged.
