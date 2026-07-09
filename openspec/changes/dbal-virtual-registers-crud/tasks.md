# Tasks: dbal-virtual-registers-crud

## 1. Writable seam

- [ ] 1.1 Add `WritableObjectSourceProvider` interface (`insert`/`update`/`remove`) extending `ObjectSourceProvider` in `lib/Service/ObjectSource/`
  - Acceptance: interface documented; no native provider implements it
- [ ] 1.2 Add `DbalWriteException` (sanitized message + HTTP status) and map SQLSTATE classes per design D4; extend `ObjectSourceErrorMiddleware` to render it
  - Acceptance: 23505/23503 → 409, 23502/23514/22xxx → 422, connection → 503/502; middleware logs the wrapped DBAL exception

## 2. Dispatch

- [ ] 2.1 `SaveObject`: replace the unconditional read-only guard with conditional delegation — RBAC (`create`/`update`) first, then writability check (annotation `readOnly === false` AND live Source `writable === true`, fail closed), then `insert`/`update` on the writable provider; everything else keeps the v1 rejection
  - Acceptance: native providers and read-only dbal sources behave exactly as v1
- [ ] 2.2 `DeleteObject`: same conditional delegation to `remove`; zero affected rows → DoesNotExistException (404 parity); external deletes are hard deletes
  - Acceptance: read-only sources keep the v1 403; absent id → 404
- [ ] 2.3 Audit: record audit-trail rows for external create/update/delete (design D6); if hard-coupled to native rows, structured secret-free log + follow-up issue filed
  - Acceptance: a live external write leaves an inspectable audit record or logged line

## 3. Provider writes

- [ ] 3.1 `DbalObjectSourceProvider::insert()` — column allowlist (400 on unknown property), parameterized INSERT, generated PK via PG `RETURNING` / `lastInsertId`, re-read via `find()` for the response; no-PK tables append-only
  - Acceptance: SQLite fixture insert round-trips with DB defaults visible
- [ ] 3.2 `DbalObjectSourceProvider::update()` — single/composite-PK predicate from the object id (part-count mismatch → 400), only allowlisted columns, zero-rows → null/404 path
  - Acceptance: composite-PK fixture table updates by joined id
- [ ] 3.3 `DbalObjectSourceProvider::remove()` — same predicate rules; no-PK and view schemas reject update/remove regardless of the flag
  - Acceptance: view schema write → v1 rejection

## 4. Opt-in plumbing

- [ ] 4.1 `SourcesController`: round-trip `authConfig.writable` (boolean, non-secret, survives the custody sanitizer); introspection stamps `readOnly: !writable` on table schemas and `readOnly: true` on views
  - Acceptance: re-introspection after toggling updates annotations; custody strip test still green
- [ ] 4.2 Live writability resolver used by the dispatch (source lookup by annotation `sourceId`, fail closed on any resolution error)
  - Acceptance: flag off → immediate re-lock unit test

## 5. UI

- [ ] 5.1 `EditSource.vue`: "Allow writes (create/update/delete)" toggle on the database form, default off, with a warning hint; sent as `authConfig.writable`
  - Acceptance: toggle round-trips; NcCheckboxRadioSwitch labelled (a11y gates)

## 6. Tests & quality

- [ ] 6.1 Writable SQLite fixture config + integration tests: insert/update/delete round-trips, constraint-violation mapping (unique/FK/NOT NULL), unknown-property 400, composite-PK and no-PK behaviour
- [ ] 6.2 Dispatch unit tests: read-only default unchanged, live re-lock, RBAC-before-provider (provider never touched on denial), native providers unaffected
- [ ] 6.3 Run hydra gates + change-scoped test suites; fix all findings in touched files (repoint any legacy @spec anchors pulled into diff scope)
- [ ] 6.4 Live verification on the isolated instance against real PostgreSQL: flip demo source writable, CRUD a permit via API and via the UI (create, edit status, delete), verify constraint 409/422 and read-only re-lock

## Quality reminders (not checkboxes)
- SPDX + @spec tags (`openspec/changes/dbal-virtual-registers-crud/specs/dbal-virtual-registers/spec.md`) on every new/changed method
- Parameterized SQL + platform quoting everywhere; no secrets in messages or logs
