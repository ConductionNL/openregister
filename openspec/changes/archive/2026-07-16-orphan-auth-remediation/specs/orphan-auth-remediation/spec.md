## ADDED Requirements

### Requirement: A dead validation method that duplicates a live enforcement point MUST be removed, and the live enforcement point MUST remain

An authorization or validation method that is DEFINED but has zero callers on
any live path is, at best, dead code and, at worst, a false signal that a guard
runs when it does not (OWASP A01:2021). When a newer or central mechanism
already enforces the same rule on the live call path, the orphan MUST be removed
together with any test that exclusively exercises it, and the superseding live
enforcement point MUST be preserved and stay covered. A method MUST NOT be
removed on the assumption of supersession — the superseding live path MUST be
identified (route/job + the executing check) before deletion.

- The audit-log read path MUST validate that the requested object belongs to the
  requested register/schema before returning per-object audit trails, and MUST be
  admin-gated. This enforcement lives in `LogService::getLogs()` (inline
  register/schema comparison) behind `AuditTrailController::objects` /
  `requireAdmin()`; the duplicate `AuditHandler::validateObjectOwnership()` MUST NOT
  exist as a second, uncalled copy.
- Destruction of an object under an active legal hold MUST be prevented
  fail-closed at execution time. This enforcement lives in
  `DestructionExecutionJob` (re-check `hasActiveLegalHold` → skip + notify) and in
  `DestructionService::findEligibleObjects()`; a separate uncalled pre-flight
  `validateDestructionList()` MUST NOT stand in as a phantom guard.
- The search-trail recording gate MUST be resolved through
  `SearchQueryHandler::getEffectiveRecordingMode()`; the duplicate
  `isSearchTrailsEnabled()` reader MUST NOT exist uncalled.
- Bulk schema-object validation exposed at `/api/objects/validate` MUST run
  through `ObjectService::validateAndSaveObjectsBySchema()`; the uncalled
  `ValidationHandler::validateSchemaObjects()` duplicate MUST NOT exist.

#### Scenario: Audit-log ownership is enforced on the live path after the duplicate is removed
- **WHEN** a non-matching register/schema is supplied for an object id to the audit-trail endpoint
- **THEN** `LogService::getLogs()` MUST reject it with an `InvalidArgumentException` (register/schema mismatch), and the endpoint MUST remain `requireAdmin()`-gated — independently of any `AuditHandler` method

#### Scenario: Legal-hold destruction guard survives removal of the pre-flight duplicate
- **WHEN** an object acquires an active legal hold after being placed on an approved destruction list
- **THEN** `DestructionExecutionJob` MUST re-check the hold at execution time and skip the object (fail-closed), with no dependency on `validateDestructionList()`

#### Scenario: Removing a superseded orphan does not change runtime behaviour
- **WHEN** a validation method with zero `lib/`+`src/` callers and a proven superseding live check is deleted
- **THEN** the unit test suite result MUST be unchanged (same pass/err/fail counts), because no live path referenced the deleted method

### Requirement: A non-authorization value-object predicate flagged by orphan-auth MUST NOT be force-wired or force-deleted

The orphan-auth verb regex (`is*/validate*/…`) also matches value-object and
format predicates and deliberately-deferred opt-in validators that are not
access controls. Such a finding MUST be triaged as a documented seam/unsure and
LEFT in place: it MUST NOT be deleted merely to satisfy the gate (that removes
tested public API), and it MUST NOT be wired into a mutating path unless a real,
currently-unprotected action requires it (that is speculative feature work with
regression risk). The verdict and reasoning MUST be recorded with `file:line`
evidence.

#### Scenario: A deferred opt-in marker validator is left in place, documented
- **WHEN** orphan-auth flags a DI-registered but unconsumed marker validator whose own docblock defers wiring ("wire into schema-save when write-time enforcement is desired")
- **THEN** the method MUST be left in place with a recorded UNSURE/deferred verdict and a follow-up note, NOT force-wired or force-deleted
