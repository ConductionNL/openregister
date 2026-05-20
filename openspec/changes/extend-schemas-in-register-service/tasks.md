## 1. Serializer namespace + RegisterSerializer

- [x] 1.1 Create `lib/Service/Serializer/RegisterSerializer.php` (PHP ns `OCA\OpenRegister\Service\Serializer`) with constructor DI for `SchemaMapper` + `LoggerInterface`. Expose `serialize(Register $register, array $extend = [], ?array $schemaStats = null): array` (calls `$register->jsonSerialize()` then applies `$extend`) and `serializeMany(array $registers, array $extend = [], ?array $schemaStatsByRegisterId = null): array` (iterates + delegates).
- [x] 1.2 Implement the `'schemas'` extension: for each ID in the register's `schemas` field, attempt `SchemaMapper::find($id, _multitenancy: false)`. On success place the schema's `jsonSerialize()` output in the same array position; on `DoesNotExistException` retain the original ID in its position (do NOT drop) and log a warning via `LoggerInterface`. Preserve the `properties` field on expanded schemas (stripping is a consumer concern). Unknown `_extend` keys are silently ignored.
- [x] 1.3 Implement the `'@self.stats'` extension: only effective when `'schemas'` is also in `$extend`. For each successfully expanded schema, set `stats.objects.total` from the provided `$schemaStats` lookup; default to `0` when the ID is absent. Orphan ID entries are NOT augmented.

## 2. Wire RegisterSerializer into RegisterService

- [x] 2.1 Add `RegisterSerializer` as a constructor dependency on `RegisterService`. Add `findSerialized(string|int $id, array $_extend = [], bool $_multitenancy = true): array` (calls existing `find()`, pre-computes stats via existing `getSchemaObjectCounts()` when `'@self.stats'` + `'schemas'` requested, delegates to `RegisterSerializer::serialize()`).
- [x] 2.2 Add `findAllSerialized(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], array $_extend = [], bool $_multitenancy = true): array` (calls existing `findAll()`, pre-computes per-register schema stats if requested, delegates to `serializeMany()`). Keep `RegisterService::findAll()` and `::find()` signatures + return types unchanged (still entities); the `_extend` parameter on those methods is documented as a no-op placeholder for signature compatibility.

## 3. Refactor RegistersController::index() to delegate

- [x] 3.1 Replace the inline schema-expansion block in `lib/Controller/RegistersController.php::index()` (the post-`findAll()` loop calling `SchemaMapper::find()` per schema ID, plus its `getSchemaObjectCounts()` + per-schema stats loop) with a single call to `$this->registerService->findAllSerialized(...)`, passing the parsed `$_extend` array. Remove the now-unused direct `SchemaMapper` usage (keep any other usages intact). Verify the controller body is thin (routing, param parsing, service call, response formatting only) per ADR-008.

## 4. Drop unused `_extend` plumbing in the mapper

- [x] 4.1 Drop `_extend` from `RegisterMapper::findAll()` AND `RegisterMapper::find()` signatures and remove the `@SuppressWarnings(PHPMD.UnusedFormalParameter)` pragmas. Update `RegisterService::findAll()` and `::find()` to stop forwarding `_extend` to the mapper (the mapper no longer accepts it). Run `composer check:strict` and resolve any sniff complaints from the signature change.

## 5. Unit tests for RegisterSerializer

- [x] 5.1 Tests for default + `'schemas'` expansion: no `_extend` → output `schemas` is the unchanged ID array (no `SchemaMapper::find()` calls); `_extend: ['schemas']` with all schemas resolvable → ordered array of schema objects each with `id`, `title`, `properties`; entity contract unchanged (`Register::jsonSerialize()` still returns ID array).
- [x] 5.2 Tests for orphan-ID retention: `_extend: ['schemas']` with one orphan → orphan ID kept in original array position (mixed object/ID array), logger receives warning with the failing ID in context, no exception; mixed numeric + UUID schema references both orphans retain their original PHP types (int stays int, string stays string).
- [x] 5.3 Tests for `@self.stats` interaction: `_extend: ['schemas', '@self.stats']` with precomputed `[10 => 5, 20 => 0]` → schema 10 has `stats.objects.total == 5`, schema 20 has `0`; orphan ID gets NO stats wrapping; `_extend: ['@self.stats']` alone (no `'schemas'`) → `schemas` field unchanged, no stats anywhere; `_extend: ['schemas', 'unknown-key']` → identical to `['schemas']`, no warning for the unknown key.

## 6. Integration tests / HTTP parity

- [x] 6.1 Add an integration test that hits `GET /api/registers?_extend=schemas` and compares against a pre-refactor snapshot — must be byte-identical for the happy path (no orphans in fixture). Add a second test with a fixture register referencing a deleted schema — verifies orphan ID is preserved (post-refactor behaviour change).
- [x] 6.2 Add an integration test for `GET /api/registers?_extend=schemas&_extend=@self.stats` — each expanded schema carries `stats.objects.total`. Add a service-level integration test that calls `$registerService->findAllSerialized(_extend: ['schemas'])` via DI and asserts identical output to the HTTP path (same fixture, ignoring HTTP envelope).

## 7. Cross-repo verification + docs

- [x] 7.1 Grep `opencatalogi/`, `softwarecatalog/`, and `docudesk/lib/Service/RegisterDiscoveryService.php` for `RegisterService::findAll(` / `::find(` with `_extend`. Do NOT change consumers in this PR; file a follow-up issue per consumer that should swap to `findAllSerialized`/`findSerialized` (including the DocuDesk `RegisterDiscoveryService::serializeRegister()` one-line swap).
    - DocuDesk follow-up: [ConductionNL/docudesk#120](https://github.com/ConductionNL/docudesk/issues/120).
    - softwarecatalog follow-up: [ConductionNL/softwarecatalog#272](https://github.com/ConductionNL/softwarecatalog/issues/272) — wire-format audit for `[]Schema` decoder paths after the orphan-schema-ID retention change (filed per PR #1430 review concern).
- [x] 7.2 Update `openregister/docs/` to document the new `lib/Service/Serializer/` namespace + `RegisterSerializer` usage (example DI consumer call). Add a `CHANGELOG.md` entry noting: (a) new `findAllSerialized` / `findSerialized` on `RegisterService`, (b) new `RegisterSerializer`, (c) the orphan-schema-ID retention behaviour change for `/api/registers?_extend=schemas`.

## 8. Quality gates

- [x] 8.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) with zero new issues on changed files; existing test suite + new section-5/6 tests pass; `openspec validate extend-schemas-in-register-service` passes; ADR-008 alignment confirmed (controller thin; logic in service/serializer).
