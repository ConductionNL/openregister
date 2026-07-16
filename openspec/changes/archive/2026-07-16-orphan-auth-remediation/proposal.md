---
kind: code
---

## Why

Hydra gate 6 (`orphan-auth`) — "an authorization/validation method DEFINED but
NEVER CALLED is identical to having no check at all (OWASP A01:2021)" — was
recently un-blinded (openregister#444): its file enumerator was made recursive,
so it now scans the full `lib/Service` + `lib/Controller` tree instead of the
~37% it previously reached. On clean `origin/development` it reports **14 orphan
auth/validation methods** — public `is*/validate*/…` methods with zero callers
in `lib/` or `src/`.

A defined-but-uncalled validation method is a liability in two ways: (1) if it is
the ONLY guard for a sensitive action, that action is silently unprotected
(the shillinq/procest IDOR pattern); (2) if a newer/central mechanism already
enforces the same rule on the live path, the orphan is dead code that makes the
codebase LOOK like it has a second guard it does not run. Both must be resolved
deliberately, per-method, with evidence.

## What Changes

Each of the 14 findings was triaged to exactly one verdict (full per-method
table with `file:line` evidence in `design.md`). Outcome:

- **4 methods DELETED** — each is superseded by a proven live enforcement point,
  so the guarded action is NOT unprotected. Removing them (and their dead tests)
  eliminates the misleading "second guard":
  - `AuditHandler::validateObjectOwnership()` — the audit-log read path
    (`LogService::getLogs()`) already re-validates register/schema ownership
    INLINE and is `requireAdmin()`-gated; `AuditHandler::getLogs()` is never on
    the live path (`ObjectService::getLogs()` delegates to `GetObject::findLogs`).
  - `DestructionService::validateDestructionList()` — legal holds are enforced
    fail-closed at execution time by `DestructionExecutionJob` (re-check +
    skip), and at eligibility scan time by `findEligibleObjects()`.
  - `SearchQueryHandler::isSearchTrailsEnabled()` — superseded by
    `getEffectiveRecordingMode()`/`resolveRecordingMode()`, which read the same
    `searchTrailsEnabled` setting; `logSearchTrail()` gates on the mode.
  - `ValidationHandler::validateSchemaObjects()` — the live `/api/objects/validate`
    endpoint uses `ObjectService::validateAndSaveObjectsBySchema()`.
- **10 methods LEFT in place, documented** — none is an authorization check on an
  unprotected live path. 8 are value-object / format predicates that are legit
  (if presently unused) public API with unit tests; 2 (`validateAll` on the
  integration reference validators) are deliberately-deferred opt-in marker
  validators registered in DI but not yet consumed (their own docblocks/DI
  comments say "wire into schema-save when write-time enforcement is desired").
  Force-wiring or force-deleting either class is out of scope: wiring is
  speculative feature work with regression risk, deleting removes tested API.

**No method was WIRED**: no finding was an authorization check guarding a
reachable mutating path that was actually unprotected. Every security-relevant
orphan already had a proven superseding live check. This is an honest
verify-then-fix outcome, not a refusal to act.

## Capabilities

### New Capabilities

- `orphan-auth-remediation` — the invariant that a dead validation method which
  duplicates a live enforcement point MUST be removed, and the live enforcement
  point MUST remain and stay covered.

### Modified Capabilities

(none — enforcement behaviour of every touched path is unchanged; only dead
duplicate code is removed)

## Impact

- No runtime behaviour change: every deleted method had zero callers in `lib/`
  or `src/` (verified by `->method(` grep). The live paths that DO enforce the
  same rules are untouched.
- Dead tests removed: 3 methods in `tests/Service/ObjectHandlersIntegrationTest.php`
  that exclusively exercised two of the deleted methods.
- Gate 6 (full-repo) drops from 14 → 10 orphan findings; the remaining 10 are
  documented, non-auth, and non-blocking under diff-scoped CI.
