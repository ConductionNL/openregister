## 1. Triage (gate-6 un-blinding, openregister#444)

- [x] 1.1 Reproduce gate 6 on clean `origin/development` with the recursive enumerator (`_enum_tracked '\.php$' lib/Service lib/Controller`) → 14 orphan methods, stable across two runs.
- [x] 1.2 Read EACH of the 14 methods end-to-end; find the live call path via `->method(` grep (not DI injection). Record a per-method verdict with `file:line` evidence in `design.md`.
  - Acceptance: every finding has exactly one verdict (wired/deleted/seam/unsure) with a cited superseding path or reason.

## 2. Deletions (superseded — a live check already enforces the rule)

- [x] 2.1 `AuditHandler::validateObjectOwnership()` — delete method + its two private-only helpers `extractSchemaId`/`extractSchemaSlug`. Superseded by `LogService::getLogs()` inline register/schema check + `requireAdmin()`.
- [x] 2.2 `DestructionService::validateDestructionList()` — delete. Superseded by `DestructionExecutionJob` execution-time `hasActiveLegalHold` (fail-closed skip) + `findEligibleObjects()` scan.
- [x] 2.3 `SearchQueryHandler::isSearchTrailsEnabled()` — delete. Superseded by `getEffectiveRecordingMode()`/`resolveRecordingMode()`.
- [x] 2.4 `ValidationHandler::validateSchemaObjects()` — delete. Superseded by `ObjectService::validateAndSaveObjectsBySchema()` on the live `/api/objects/validate` route.

## 3. Dead-test removal

- [x] 3.1 Remove `testValidateSchemaObjectsWithValidObjects` + `testValidateSchemaObjectsWithFailingCallback` + `testIsSearchTrailsEnabled` from `tests/Service/ObjectHandlersIntegrationTest.php` (the only tests exercising deleted methods; `validateObjectOwnership`/`validateDestructionList` had none).
  - Acceptance: `grep -rn "<deleted-method>" lib/ src/ tests/ appinfo/` returns only the unrelated frontend `RegisterSchemaCard.vue` JS method.

## 4. Verification

- [x] 4.1 `php -l` clean on all 5 edited files.
- [x] 4.2 Re-run gate 6 → 14 → 10 orphan findings (4 deleted); the 10 remaining are the documented non-auth value-object predicates + deferred opt-in validators.
- [x] 4.3 Establish `origin/development` baseline and compare, real numbers (`or-phpunit-83-full:local`, `OPENREGISTER_TEST_SKIP_NC=1`, `tests/Unit`): baseline 14763 tests / 27 err / 17 fail / 23 skip; after change IDENTICAL (deletions have zero `tests/Unit` coverage; removed tests live in the NC-root Service suite). No new err/fail.
- [x] 4.4 Confirm none of the pre-existing 27 err / 17 fail involve any edited class.

## 5. Documentation

- [x] 5.1 File the per-method verdict table + the "no live unprotected action found" conclusion on openregister#444.
