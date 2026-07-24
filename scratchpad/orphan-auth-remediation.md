# orphan-auth-remediation (openregister · gate-6 un-blinding #444)

**No LIVE unprotected mutating action found.** Every security-relevant orphan already had a proven fail-closed live check — the orphan was a duplicate, not the sole guard. 0 wired.

## Per-method verdicts (14 findings on clean origin/development)
| verdict | count | methods |
|---|---|---|
| wired | 0 | — |
| deleted (superseded) | 4 | validateObjectOwnership, validateDestructionList, isSearchTrailsEnabled, validateSchemaObjects |
| seam / leave | 8 | isGrouped, isMultiFieldGroupBy, isPartialSuccess, isPreviewAvailable, isGeoType, isSupportedCrs, isRead, isVerified |
| unsure | 2 | PropertyReferenceTypeValidator::validateAll, PropertySemanticReferenceValidator::validateAll (isVerified also carries an unsure note) |

### Deletions — superseding live check (verified)
- `AuditHandler::validateObjectOwnership` (+ private `extractSchemaId`/`extractSchemaSlug`) → `LogService::getLogs()` re-validates register/schema inline (LogService.php:158-175) behind `AuditTrailController::objects` requireAdmin(). AuditHandler is injected into ObjectService but never called.
- `DestructionService::validateDestructionList` → `DestructionExecutionJob` re-checks legal holds fail-closed at execution (:160-161, skip+notify) + `findEligibleObjects()`:201.
- `SearchQueryHandler::isSearchTrailsEnabled` → `getEffectiveRecordingMode()`/`resolveRecordingMode()`; `logSearchTrail()` gates on the mode.
- `ValidationHandler::validateSchemaObjects` → live `/api/objects/validate` uses `validateAndSaveObjectsBySchema`.
- Removed 3 dead tests (ObjectHandlersIntegrationTest); the other two deletions had none.
- Bonus: fixed pre-existing @license drift AGPL→EUPL-1.2 in ValidationHandler (gate-28).

### Bad-path-rejected test output (WIRES)
None — 0 methods wired. No orphan was an auth check on an unprotected live path; each security-relevant path is already guarded (LogService inline register/schema reject + requireAdmin; DestructionExecutionJob fail-closed legal-hold skip; getEffectiveRecordingMode gate).

## Real suite numbers (or-phpunit-83-full:local, OPENREGISTER_TEST_SKIP_NC=1, tests/Unit)
- Baseline origin/development: 14763 tests · 27 err · 17 fail · 23 skip
- After change: IDENTICAL 14763 / 27 / 17 / 23 (deleted methods have zero unit coverage; removed tests live in NC-root Service suite)
- Merged tree (incl. upstream #451 credential test): 14769 / 27 / 17 / 23 — no new err/fail
- None of the 27 err / 17 fail involve any edited class. (Full NC-root suite is the ~14735/54err/18fail legacy bucket — needs the NC container; not run here.)

## Gates
Diff-scoped (--base origin/development): all PASS incl. gate-6 orphan-auth PASS, gate-28 license-triangle PASS (after license fix). Full-repo gate-6: 14 → 10.

## PRs / issue
- #452 openregister: orphan-auth-remediation (apply) — MERGED (base development)
- #453 openregister: orphan-auth-remediation (archive) — MERGED; canonical spec openspec/specs/orphan-auth-remediation/spec.md status: done; changes/archive/2026-07-16-orphan-auth-remediation/
- #444 findings comment posted (issuecomment-19319524)

## Follow-up
Wire the two `validateAll` integration-marker validators into schema-save as a future opt-in (behind a setting)? Deferred — DI-registered but unconsumed; semantic one's docblock explicitly defers. Filed on #444.
