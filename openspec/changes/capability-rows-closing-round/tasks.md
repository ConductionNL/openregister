# Tasks: capability-rows-closing-round

## 1. Text extraction per format

- [x] 1.1 Pin the PDF, DOCX and EML scenarios in a test that extracts a fixture of each type through `lib/Files/Service/TextExtractionService.php`. Verify: `tests/Unit/Service/TextExtractionServiceTest.php` or a new fixture test.

## 2. A register as one unit

- [ ] 2.1 Pin the round trip: export a register with two schemas and its records, import it on an empty instance, and compare. Verify: a Newman or PHPUnit test against `registers#export` and `registers#import`.
  - 2026-11-25, measured on a sandbox Nextcloud 34 on MariaDB, and it does NOT round-trip, so nothing is pinned yet: `GET /api/registers/{id}/export?format=configuration&includeObjects=true` of a register with two schemas and three records exports both schemas and the three objects; after deleting them, `POST /api/registers/{id}/import` with that file is routed to the register-bundle importer (a `.json` with `components.registers`), which refuses it with 400 'Register bundle import requires exactly one schema when no schema is explicitly specified; found 2' AFTER creating the register and both schemas (they are left behind, empty); with `type=configuration` the same file answers 404 for a missing register, and into an existing register 400 'importFromJson must be called with a Configuration entity'. The bundle importer is single-schema by design (register-import-auto-create); a two-schema round trip needs that importer to route each object by its `@self.schema`, which is that change's call.

## 3. Lock holder shown

- [x] 3.1 Pin the lock notice: lock a record as one user and open it as another. Verify: a Playwright test on the record page that reads "locked by" with the holder's name (`src/views/object/ObjectDetails.vue`).
  - 2026-11-25: `tests/e2e/ci/lock-holder-shown.spec.ts`: `e2e-other` locks a record over the API, the administrator opens it and reads 'locked by e2e-other'; 1 passed on a sandbox Nextcloud 34. It failed first: the page read the lock from `objectItem.locked` while the object API answers it in `@self.locked`, so the notice (and the Lock/Unlock switch) never showed a lock. `ObjectDetails.lockInfo` now reads `@self.locked`, and Unlock calls the object's unlock route (the store had no `unlockObject`, so the button would have thrown) (`ObjectDetails.lock.spec.js`).
