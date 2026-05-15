- [x] 1. Create the Serializer namespace and RegisterSerializer
  - [x] 1.1 Create directory `lib/Service/Serializer/` (PHP namespace `OCA\OpenRegister\Service\Serializer`).
  - [x] 1.2 Create `lib/Service/Serializer/RegisterSerializer.php` with constructor DI for `SchemaMapper` and `LoggerInterface`.
  - [x] 1.3 Implement `RegisterSerializer::serialize(Register $register, array $extend = [], ?array $schemaStats = null): array` — calls `$register->jsonSerialize()`, then applies `$extend` transformations.
  - [x] 1.4 Implement `RegisterSerializer::serializeMany(array $registers, array $extend = [], ?array $schemaStatsByRegisterId = null): array` — iterates and delegates to `serialize()`.
  - [x] 1.5 Implement the `'schemas'` extension: for each ID in the register's `schemas` field, attempt `SchemaMapper::find($id, _multitenancy: false)`. On success, place the schema's `jsonSerialize()` output in the same array position. On `DoesNotExistException`, **retain the original ID in its position** (do NOT drop it) and log a warning via `LoggerInterface` with the failed ID in context.
  - [x] 1.6 Implement the `'@self.stats'` extension: only effective when `'schemas'` is also in `$extend`. For each successfully expanded schema object, set `stats.objects.total` from the provided `$schemaStats` lookup; default to `0` when the ID is absent from the lookup. Orphan ID entries are NOT augmented.
  - [x] 1.7 Unknown `_extend` keys are silently ignored (no exception, no log).
  - [x] 1.8 Do NOT strip the `properties` field from expanded schemas (the serializer preserves it; stripping is a consumer concern).

- [x] 2. Wire RegisterSerializer into RegisterService
  - [x] 2.1 Add `RegisterSerializer` as a constructor dependency on `RegisterService`.
  - [x] 2.2 Add `RegisterService::findSerialized(string|int $id, array $_extend = [], bool $_multitenancy = true): array` — calls existing `find()`, pre-computes stats if `'@self.stats'` AND `'schemas'` are requested (via existing `getSchemaObjectCounts()`), delegates to `RegisterSerializer::serialize()`.
  - [x] 2.3 Add `RegisterService::findAllSerialized(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], array $_extend = [], bool $_multitenancy = true): array` — calls existing `findAll()`, pre-computes per-register schema stats if requested, delegates to `RegisterSerializer::serializeMany()`.
  - [x] 2.4 Keep `RegisterService::findAll()` and `::find()` signatures and return types unchanged (still return `Register[]` / `Register`). The `_extend` parameter on these methods is documented as a no-op placeholder for signature compatibility.

- [x] 3. Refactor RegistersController::index() to delegate
  - [x] 3.1 Replace the inline schema-expansion block in `lib/Controller/RegistersController.php::index()` (currently the loop after `findAll()` that calls `SchemaMapper::find()` per schema ID) with a single call to `$this->registerService->findAllSerialized(...)`, passing the parsed `$_extend` array.
  - [x] 3.2 Remove the direct `SchemaMapper` usage for expansion within `index()` (keep any other usages intact).
  - [x] 3.3 Remove the inline `getSchemaObjectCounts()` call and per-schema stats loop from `index()` — the serializer now owns this.
  - [x] 3.4 Verify `RegistersController::index()` body is thin (routing, param parsing, service call, response formatting only) per ADR-008.

- [x] 4. Clean up `@SuppressWarnings(PHPMD.UnusedFormalParameter)` on `_extend`
  - [x] 4.1 Drop `_extend` from `RegisterMapper::findAll()` signature and remove the `@SuppressWarnings(PHPMD.UnusedFormalParameter)` pragma on that method.
  - [x] 4.2 Drop `_extend` from `RegisterMapper::find()` signature and remove the `@SuppressWarnings` pragma on that method.
  - [x] 4.3 Update `RegisterService::findAll()` and `::find()` to stop forwarding `_extend` to the mapper (the mapper no longer accepts it).
  - [x] 4.4 Run `composer check:strict` — PHPCS / PHPMD / Psalm / PHPStan must pass, including any new sniff complaints arising from the signature change.

- [x] 5. Unit tests for RegisterSerializer
  - [x] 5.1 Test: no `_extend` passed → output `schemas` is the ID array from `Register::jsonSerialize()`, unchanged. No `SchemaMapper::find()` calls made.
  - [x] 5.2 Test: `_extend: ['schemas']` with all schemas resolvable → output `schemas` is an array of schema objects in original order; each object contains `id`, `title`, and `properties`.
  - [x] 5.3 Test: `_extend: ['schemas']` with one orphan ID → output contains the orphan ID in its original array position (mixed object/ID array); logger receives a warning with the failing ID in context; no exception thrown.
  - [x] 5.4 Test: `_extend: ['schemas']` with mixed numeric and UUID schema references where both orphans → each orphan retains its original type (int stays int; string stays string).
  - [x] 5.5 Test: `_extend: ['schemas', '@self.stats']` with precomputed stats `[10 => 5, 20 => 0]` → schema 10 has `stats.objects.total == 5`; schema 20 has `stats.objects.total == 0`.
  - [x] 5.6 Test: `_extend: ['schemas', '@self.stats']` where one schema ID is orphan → expanded schemas have stats; orphan ID is a bare int/string with no wrapping.
  - [x] 5.7 Test: `_extend: ['@self.stats']` alone (no `'schemas'`) → `schemas` field is unchanged ID array; no stats anywhere.
  - [x] 5.8 Test: `_extend: ['schemas', 'unknown-key']` → output identical to `_extend: ['schemas']`; no warnings emitted for the unknown key.
  - [x] 5.9 Test: `Register::jsonSerialize()` after all changes → `schemas` field is still an ID array (entity contract unchanged).

- [x] 6. Integration tests / parity check
  - [x] 6.1 Add an integration test that hits `GET /api/registers?_extend=schemas` and compares the response to a snapshot captured before the refactor — must be byte-identical for the happy path (no orphan IDs in test fixtures).
  - [x] 6.2 Add an integration test that hits `GET /api/registers?_extend=schemas` with a fixture containing a register referencing a deleted schema — verifies the orphan ID is preserved in the response (post-refactor behavior change).
  - [x] 6.3 Add an integration test for `GET /api/registers?_extend=schemas&_extend=@self.stats` → each expanded schema has `stats.objects.total`.
  - [x] 6.4 Add a service-level integration test that calls `$registerService->findAllSerialized(_extend: ['schemas'])` via DI and asserts identical output to the HTTP path (same fixture, ignoring HTTP envelope).

- [x] 7. Cross-repo verification
  - [x] 7.1 Grep `opencatalogi/` and `softwarecatalog/` for `RegisterService::findAll(` and `RegisterService::find(` with `_extend` — note any consumers that may benefit from the new `findAllSerialized` / `findSerialized` methods. Do NOT change them in this PR; file follow-up issues if any are found.
        - **opencatalogi:** no callers passing `_extend` to `RegisterService::findAll`/`::find` (grepped `opencatalogi/lib`).
        - **softwarecatalog:** repo not cloned in this workspace; cross-app audit deferred to a separate ticket against that repo.
  - [x] 7.2 Grep `docudesk/lib/Service/RegisterDiscoveryService.php` — confirm it calls `$registerService->findAll(_extend: ['schemas'])` and expects entities. Document that DocuDesk's follow-up PR will swap its inline `$register->jsonSerialize()` for a `RegisterSerializer::serialize($register, ['schemas'])` call.
        - Confirmed: `RegisterDiscoveryService::fetchAvailableRegisters()` (around line 75) calls `findAll(_extend: ['schemas'])` and then `array_map([$this, 'serializeRegister'], ...)` which calls `$register->jsonSerialize()` per register. After this change merges, that path returns ID-only schemas (entity contract is unchanged). The DocuDesk follow-up PR replaces the call with `RegisterService::findAllSerialized(_extend: ['schemas'])` (or injects `RegisterSerializer` and serializes per register).

- [x] 8. Documentation
  - [x] 8.1 Update `openregister/docs/` or relevant section to document the `lib/Service/Serializer/` namespace and `RegisterSerializer` usage (example call for a DI consumer). — Added `docs/development/Services/serializers.md`.
  - [x] 8.2 Add a CHANGELOG entry noting: (a) new `findAllSerialized` / `findSerialized` on `RegisterService`, (b) new `RegisterSerializer`, (c) the orphan-schema-ID retention behavior change for `/api/registers?_extend=schemas`.
  - [x] 8.3 Update or create the follow-up ticket in DocuDesk (internal tracker / GitHub issue) referencing this change and describing the one-line swap needed in `RegisterDiscoveryService::serializeRegister()`. — Filed as [ConductionNL/docudesk#120](https://github.com/ConductionNL/docudesk/issues/120). Action lands once openregister cuts a release containing this change.

- [x] 9. Quality gates
  - [x] 9.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan). — PHPCS clean. PHPMD: only pre-existing warnings remain (unrelated `find()` body / unrelated controller methods). Psalm: 0 errors on touched files. PHPStan: 0 errors on touched files (3 fixed during this section). PHPUnit local run requires the Nextcloud bootstrap (`OC::checkInstalled()` not available outside the container), so unit/integration tests are validated for syntax and run via container — see 9.3.
  - [x] 9.2 Existing test suite passes (`composer test` or equivalent). — Verified inside container `master-nextcloud-1` for `RegisterServiceTest` (21/21), `RegistersControllerTest` (87/87), `RegisterMapperTest` (2/2). The full suite hits a pre-existing fatal in `SettingsControllerTest.php:2175` (`IResult` interface drift, unrelated to this change) so the global run can't complete; targeted runs of every register-related test pass green.
  - [x] 9.3 New unit and integration tests from sections 5 and 6 pass. — `RegisterSerializerTest` 10/10, `RegisterSerializerIntegrationTest` 4/4 — verified via `docker exec -w /var/www/html/apps-extra/openregister master-nextcloud-1 php vendor/bin/phpunit -c phpunit-unit.xml --filter RegisterSerializerTest` and `--filter RegisterSerializerIntegrationTest` against `phpunit.xml`.
  - [x] 9.4 Psalm/PHPStan show zero new issues on the changed files.
  - [x] 9.5 Code review: confirm ADR-008 alignment (controller is thin; business logic in service/serializer). — Confirmed: `RegistersController::index()` body is now param parsing + one service call + register-level `@self.stats` block + JSONResponse. Schema expansion + per-schema stats live in `RegisterSerializer` / `RegisterService::findAllSerialized()`.
