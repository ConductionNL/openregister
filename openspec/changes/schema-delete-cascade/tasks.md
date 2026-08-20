# Tasks — Schema delete cascade

Scope note: this change introduces **no OpenRegister schemas or registers**, so there is
**no seed-data task** — none is needed. It introduces no lifecycle, aggregation,
notification or widget behaviour, so **ADR-031's declarative-vs-imperative guidance does
not apply** here.

## 1. Backend — schema-wide object deletion (openregister)

- [x] 1.1 Implement `ObjectService::deleteObjectsBySchema()` (replacing the throwing stub at `lib/Service/ObjectService.php:3568`): resolve the int register/schema ids to `Register`/`Schema` entities, collect UUIDs + full snapshots via `MagicMapper::findAllInRegisterSchemaTable()` **before** deleting, then call `MagicMapper::deleteObjectsBySchema(hardDelete: true)`. Return `{deleted_count, deleted_uuids, schema_id}` to match the two controllers' existing response shape.
- [x] 1.2 Write one `AuditTrailMapper` entry per deleted object (snapshot captured pre-delete) with action `schema.cascade_delete` and `changed.cascadeContext` trigger context; chunk the collect+audit loop and resolve schema-derived values once per operation (ADR-009); write through `AuditTrailMapper` so ADR-003 hash-chaining applies.
- [x] 1.3 Verify both bulk routes now succeed instead of returning HTTP 500 — `bulk#deleteSchemaObjects` (`lib/Controller/BulkController.php:525`) and the misleadingly-named `bulk#deleteSchema` (`:444`, which deletes objects, not the schema); mark the latter `@deprecated` in its docblock as a duplicate of the former.

## 2. Backend — cascade on schema delete

- [x] 2.1 Add `deleteObjects` handling to `SchemasController::destroy()` (`lib/Controller/SchemasController.php:830`): parse the flag alongside the existing `force`, refuse **HTTP 400** when both are passed, and keep the default (no flag) 409 behaviour byte-for-byte unchanged.
- [x] 2.2 Implement the cascade as **phase 1 (one transaction)**: audit + hard-delete rows + delete the schema entity → commit. Roll back the whole thing on any failure so nothing is partially deleted.
- [x] 2.3 Implement **phase 2 (post-commit, idempotent)**: drop the magic table via `MagicMapper::dropTable()` + `getTableNameForRegisterSchema()`. On failure, log WARNING and still return success with `tableDropped: false` — do NOT fail the request or attempt a rollback (DDL is not rollbackable on MySQL/MariaDB; see design D2).
- [x] 2.4 Return the cascade response `{success, schemaId, deletedCount, deletedUuids, tableDropped}`; keep the `checkSchemaManagePermission()` gate and cache invalidation (`SchemaCacheHandler::invalidate()` + engine `reloadForSchema()`) on every disposition.
- [x] 2.5 Drop the empty magic table on a plain (0-object) schema delete too, so the no-flag path stops leaving orphan tables behind. **Narrowed during implementation:** the table is dropped only when it holds *zero rows in total*. `getStatistics()` excludes soft-deleted rows, so a "0-object" schema can still have tombstones; dropping that table would destroy real rows with no audit entry. Such a table is kept (WARNING logged) — the cascade disposition is the audited way to remove them.

## 3. Backend — repair the dead guard

- [x] 3.1 Repair `SchemaMapper::delete()` (`lib/Db/SchemaMapper.php:1587`): the object count currently queries the retired `openregister_objects` blob table and is therefore always 0. Re-point it at the **magic tables** via a direct DB query (deterministic name `openregister_table_{registerId}_{schemaId}`, guarded by `tableExists()`, excluding soft-deleted rows). Do **not** inject `MagicMapper`/`MagicStatisticsHandler` — that is a real circular dependency (`MagicStatisticsHandler` injects `SchemaMapper`).
- [x] 3.2 Add an explicit `bool $force = false` bypass parameter to `SchemaMapper::delete()`; only the `?force=true` controller path may pass `true`. The cascade path needs no bypass (rows are already gone, so the guard naturally counts 0). **Note:** the class-level `@method Schema delete(Entity $entity)` docblock annotation shadows the real signature for PHPStan and had to be updated in lockstep.
- [x] 3.3 Audit the three now-genuinely-guarded callers — `lib/Mcp/BuiltIn/SchemasToolProvider.php:227`, `lib/Tool/SchemaTool.php:447`, `lib/Service/ObjectSource/TablesSchemaSyncService.php:303` — and decide **per site** whether it should refuse (default) or legitimately force. Do not blanket-force. `TablesSchemaSyncService` is the likeliest legitimate exception. (See design Open Question 1.)
  **Verdict: all three REFUSE (no site forces).** `SchemasToolProvider` + `SchemaTool` are LLM-invokable with no controller in front of them — a model must not be able to orphan a user's data on an ambiguous instruction; both carry a comment saying so. `TablesSchemaSyncService` turned out **not** to need the exception: it retires read-only *mirrors* of Nextcloud Tables tables whose objects live in Tables, not in a magic table, so the repaired guard counts 0 and retirement proceeds exactly as before; if a magic table *does* hold rows for such a schema that is real user data the sync job has no mandate to destroy. It already tolerated a failed delete (catch + WARNING). **Pre-existing bug fixed there:** it unlinked the schema from the register *before* attempting the delete and left it unlinked even when the delete threw — the exact orphaned-schema corruption this change exists to close. The unlink now happens only after a successful delete.

## 4. Frontend — nextcloud-vue

- [x] 4.1 Fix the data-corruption bug in `CnEditDataModal.removeSchema()` (`src/modals/CnEditDataModal.vue:519`): issue the `DELETE` **first** and only PATCH the register to unlink the schema **on success**. Today the unlink is committed before the DELETE, so a 409 detaches a surviving schema from its register.
- [x] 4.2 Finalise `parseAxiosError()` in `src/utils/errors.js` (returns `{status, code, message, data}`) and use it in **every** catch block in `CnEditDataModal` — axios's `e.message` is the useless "Request failed with status code 409"; the real body is in `e.response.data`. Leave the existing fetch-based `parseResponseError` alone (it awaits `.json()` on a `Response` and is not reusable for axios). JSDoc the public util.
- [x] 4.3 On a `schema-has-objects` 409, surface the real message ("Cow still has 1 object") and offer a clearly destructive, **confirm-first** "Delete schema and its N objects" action that calls `DELETE /api/schemas/{id}?deleteObjects=true`. **Never expose `force` in the UI.** English i18n keys; Nextcloud CSS vars only; no change to any existing prop/event/slot interface.

## 5. Tests

- [x] 5.1 PHPUnit: cascade removes rows + drops the table + deletes the schema; 0-object cascade; both-flags → 400; no-flag → 409 unchanged; `force=true` → still orphans (back-compat); 403 without manage permission. → `tests/Unit/Controller/SchemasDestroySafetyTest.php` (7 tests) + `tests/Unit/Service/SchemaDeletionServiceTest.php`.
- [x] 5.2 PHPUnit: phase-1 failure rolls everything back (schema + objects intact, no audit entries); phase-2 `dropTable` failure still returns success with `tableDropped: false`. → `SchemaDeletionServiceTest::testPhaseOneFailureRollsEverythingBack` / `::testPhaseTwoDropFailureStillReturnsSuccess`.
- [x] 5.3 PHPUnit: one audit entry per deleted object with the full pre-delete snapshot + cascade trigger context; entries are hash-chained and survive the table drop. → `SchemaDeletionServiceTest::testEachCascadedObjectProducesItsOwnAuditEntryWithSnapshot` (also asserts the audit→delete-rows→drop-table ordering that makes them survive). Hash-chaining is asserted structurally: entries go through `AuditTrailMapper::createAuditTrailEntry()`, which is the `insertHashChained()` entry point — a unit test with a mocked mapper cannot observe the chain itself.
- [x] 5.4 PHPUnit: the repaired `SchemaMapper` guard actually refuses a schema with magic-table objects, and the `force` bypass permits it. → `tests/Unit/Db/SchemaMapperDeleteGuardTest.php` (4 tests).
- [x] 5.5 Jest (nc-vue): `removeSchema` does not PATCH when the DELETE fails; `parseAxiosError` extracts `{status, code, message}` from a 409 body; the cascade action is confirm-gated.

## 6. Quality gates

- [x] 6.1 Both repos green: openregister `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) + PHPUnit; nextcloud-vue `check:docs` + `check:jsdoc` + jest.
  - [x] **openregister half**: `composer check:strict` exits 0 → `ALL CHECKS PASSED`. lint clean; PHPCS **0 errors** (57 pre-existing errors in 8 untouched files were fixed en route); PHPMD 0 new; Psalm 26 errors = baseline, 0 new; PHPStan = baseline, 0 new; PHPUnit 14099 tests, 19 errors + 7 failures — **byte-identical to the `origin/development` baseline** (verified by running the suite on a baseline worktree), all in unrelated subsystems (link services, SaveObjects, AppHost bootstrap). 19 new tests, all passing.
  - [ ] nextcloud-vue half — owned by the frontend agent (tasks 4.x / 5.5).

## Acceptance criteria

- Deleting a schema with objects from the UI never detaches it from its register — a failed delete leaves the register's `schemas` array untouched.
- The 409 surfaces as a human-readable message naming the schema and the object count, not "Request failed with status code 409".
- `DELETE /api/schemas/{id}?deleteObjects=true` leaves **no orphans**: no rows, no magic table, no schema record.
- `POST /api/bulk/{register}/{schema}/delete-objects` returns success instead of HTTP 500.
- Every cascaded object is reconstructible from the audit trail after its magic table is dropped.
- `?force=true` still behaves exactly as before for API clients and is absent from the UI.
- Live-verify on the `cowboy` app (register 2466, schema 4434 "Cow") — the reproduction case.

## Quality reminders

- Do not use sed/awk/scripting to modify code files; use real edits.
- Fix pre-existing quality issues encountered along the way rather than leaving them.
- No PR, merge or release steps belong in this list.
- `deleteObjectsByRegister()` (`ObjectService:3594`) is a sibling throwing stub — out of scope, do not fix it here.
