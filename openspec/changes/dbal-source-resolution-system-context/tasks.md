# Tasks: dbal-source-resolution-system-context

## 1. System lookup seam

- [ ] 1.1 Add `SourceMapper::findForSystem(string $sourceId): ?Source` — id-or-uuid lookup with NO RBAC verify and NO organisation filter, docblock stating the security rationale (REQ-DSRSC-001)

## 2. Provider wiring

- [ ] 2.1 `DbalObjectSourceProvider::resolveSource()` calls `findForSystem()` instead of `find()`/`findAll()` (REQ-DSRSC-001)

## 3. Tests

- [ ] 3.1 Unit test: Source in another organisation + `saasMode: true` → `resolveSource()`/`findAll()` on the provider still resolves and returns objects (REQ-DSRSC-001)
- [ ] 3.2 Unit test: schema-level RBAC (`checkPermission()` in `ObjectService::paginateObjectSource()`) is untouched and still enforced before the provider runs (REQ-DSRSC-002) — covered by existing `ObjectService`/parity tests, verified not regressed

## 4. Quality

- [ ] 4.1 `php -l` + `composer phpcs` on changed files; run the relevant `DbalObjectSourceProviderTest` subset in the `nextcloud:34` container recipe (host PHP too old)
