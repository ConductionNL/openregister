# Tasks: dbal-source-resolution-system-context

## 1. System lookup seam

- [ ] 1.1 Add `SourceMapper::findForSystem(string $sourceId): ?Source` — id-or-uuid lookup with NO RBAC verify and NO organisation filter, docblock stating the security rationale (REQ-DSRSC-001) (code exists, test missing: lib/Db/SourceMapper.php::findForSystem (exercised only through a mock))

## 2. Provider wiring

- [x] 2.1 `DbalObjectSourceProvider::resolveSource()` calls `findForSystem()` instead of `find()`/`findAll()` (REQ-DSRSC-001) (verified: lib/Service/ObjectSource/DbalObjectSourceProvider.php, tests/Unit/Service/ObjectSource/DbalObjectSourceProviderTest.php)

## 3. Tests

- [x] 3.1 Unit test: Source in another organisation + `saasMode: true` → `resolveSource()`/`findAll()` on the provider still resolves and returns objects (REQ-DSRSC-001) (verified: tests/Unit/Service/ObjectSource/DbalObjectSourceProviderTest.php::testResolveSourceUsesSystemLookupAcrossOrganisations)
- [x] 3.2 Unit test: schema-level RBAC (`checkPermission()` in `ObjectService::paginateObjectSource()`) is untouched and still enforced before the provider runs (REQ-DSRSC-002) — covered by existing `ObjectService`/parity tests, verified not regressed (verified: tests/Unit/Service/ObjectSource/PaginateObjectSourceTest.php::testDeniedReadRejectsBeforeProviderIsConsulted)

## 4. Quality

- [ ] 4.1 `php -l` + `composer phpcs` on changed files; run the relevant `DbalObjectSourceProviderTest` subset in the `nextcloud:34` container recipe (host PHP too old)
