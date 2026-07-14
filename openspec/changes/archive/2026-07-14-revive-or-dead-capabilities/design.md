# Design — revive-or-dead-capabilities

## Step 1 — Verification verdict table

Verified independently against `origin/development` @ `0c0256213`. For each method:
read the method + class; grepped `->method(` repo-wide; checked dynamic dispatch
(`call_user_func`, `$obj->$m()`, reflection), `register.d` `"handler"` strings,
`appinfo/routes.php`, `appinfo/info.xml` background jobs, DI wiring in
`lib/AppInfo/Application.php` / `lib/AppHost/Bootstrap.php`, and cross-app callers
in sibling repos.

| # | Class::method | Verdict | Caller evidence | Action |
|---|---|---|---|---|
| 1 | `ArchivalService::generateDestructionList` | **(b) SUPERSEDED** | `grep -rn "ArchivalService" lib/` → **zero** references outside its own file. No DI registration, no controller, no job, no route. The **whole class** is orphaned, not just this method. Destruction lists **are** produced in production by `RetentionService::createDestructionList()`, called from the *registered* cron `DestructionCheckJob` (`appinfo/info.xml:108` → `DestructionCheckJob.php:139`). | DELETE class + test |
| 2 | `Archival\DestructionService::generateCertificate` | **(b) SUPERSEDED** | Zero `->generateCertificate(` in `lib/`. The real executor is `DestructionExecutionJob`, which does **not** use `DestructionService` at all — it generates and persists the certificate via `RetentionService::generateDestructionCertificate()` (`DestructionExecutionJob.php:212-237`). Its only would-be caller, `DestructionService::executeDestruction()`, is itself zero-caller. | DELETE both + tests; **fix the live route** (below) |
| 3 | `MetricsService::recordMetric` | **(a) GENUINELY DEAD** | `grep -rn "MetricsService" lib/` → **zero** references outside its own file; the class is injected nowhere. `openregister_metrics` is touched only by `MetricsService` itself and the migration that creates it. The Prometheus `/api/metrics` route is `AppHost\Controller\GenericMetrics`, which does **not** read that table — so it does not supersede it. Canonical spec `openspec/specs/production-observability/spec.md` **requires** the table be used: *"CRUD Operation Counters … Counters MUST persist across PHP request boundaries using the `openregister_metrics` database table (already used by `MetricsService`)."* Intended trigger is therefore object create/update/delete. | WIRE to object lifecycle events |
| 4 | `Credential\CredentialAppTokenService::issueToken` | **(c) CONSUMER SEAM** | Not dead — dead *within* OR by design. ADR-004 Rule 2: *"**Apps** present signed, per-app, expiring tokens."* OpenRegister is the **verifier**: `CredentialController::brokerRequest()` calls `tokenService->verify()` (`CredentialController.php:452`). The **consuming app** is the **issuer** — it holds the per-app signing secret returned exactly once by `registerApp()` (route `POST /api/credentials/apps/{appId}/register`). OR minting its own token would be a category error: it would *assert* the `appId` rather than *prove* it, defeating the control. Class docblock states it: *"the app keeps its own copy"*; *"trusted SAME-INSTANCE PHP callers pass their appId … directly without a token."* Fleet-wide `->issueToken(` hits resolve to an unrelated class (shillinq `Booking\ConfirmationTokenService`). | MARK-SEAM + document |

**Nothing was left UNSURE.** All four resolved on evidence.

### The triage was wrong on 1 and 2 — and that matters

The triage asserted "destruction lists never produced" and "certificate never
produced". Both capabilities **do** run in production, through `RetentionService`
(a third class the triage never looked at). The gate flagged the right *methods*
but the wrong *conclusion*: these are duplicate implementations left behind by a
refactor, not a broken feature. Fabricating a trigger for them — e.g. bolting
`generateDestructionList()` onto a cron — would have created a **second**
destruction pipeline racing the real one against the same objects. Deleting them
is the correct action.

### …but verification found a real compliance bug the gate could not see

While tracing caller chains, three defects turned up on the **live**
`/api/archival/destruction-lists/{id}/approve` route (`ArchivalController` →
`DestructionService::approveList`). Its sibling route `/api/retention/…`
(`RetentionController`) is correct; the archival one is not:

| Defect | Evidence | Consequence |
|---|---|---|
| **D1 — wrong job-argument key** | `DestructionService::approveList` queues `DestructionExecutionJob` with `['destructionList' => <array>, 'approvedBy' => …]` (`DestructionService.php:388-394`), but the job reads `$argument['destructionListUuid']` and **returns early** when it is null (`DestructionExecutionJob.php:91-95`). `RetentionController.php:233` correctly passes `['destructionListUuid' => $id]`. | Job bails immediately. **Objects are never destroyed; no certificate is ever produced** on this route. |
| **D2 — non-canonical approvals key** | `approveList` writes `approvals[] = ['approvedBy' => …]` (`DestructionService.php:334`). The canonical shape written by `RetentionController.php:192` is `['userId' => …]`, and **both** readers — `RetentionService::generateDestructionCertificate` (`:839`) and `DestructionExecutionJob` (`:179`) — use `array_column($approvals, 'userId')`. | Even once D1 is fixed, the certificate's `approvedBy` field comes out **empty** — the legal proof of *who authorised the destruction* is blank. This is exactly the "artefact generates an empty file" failure mode. |
| **D3 — approval never persisted** | `ArchivalController::approveDestructionList` loads the object, calls `approveList()`, and returns `$result` **without** `setObject()`/`update()` (`ArchivalController.php:228-256`). `RetentionController.php:227-228` persists. | The approval and the `approved` status are lost; the list stays `in_review` in storage. |

Fixing D1–D3 is what actually restores the compliance capability that #393 cared
about. It is in scope precisely because deleting `generateCertificate()` without it
would leave a route that claims to approve destruction and silently produces nothing.

## Decisions

**D-A: Delete the duplicate archival stack rather than wire it.**
Runtime truth decides: `DestructionCheckJob` → `RetentionService` →
`DestructionExecutionJob` is the registered, complete, working pipeline.
`ArchivalService` (whole class) and `DestructionService::{executeDestruction,
generateCertificate}` are zero-caller duplicates. Keeping them means the codebase
advertises an Archiefwet capability twice and implements it once.

**D-B: Wire metrics through an event listener, not the hot path.**
`MagicMapper` already dispatches `ObjectCreatedEvent` / `ObjectUpdatedEvent` /
`ObjectDeletedEvent` (`MagicMapper.php:8061,8138,8171`) and `Application.php`
already registers listeners for all three. A new `ObjectMetricsListener` therefore
needs **zero** changes to `SaveObject`/`DeleteObject` (huge, hot, heavily-mocked
classes) and inherits the canonical write path every app uses. `recordMetric()` is
already fail-soft (catches and logs), so a metrics failure cannot break a write.

**D-C: `issueToken` stays, annotated.**
Deleting it would delete the reference implementation of the token format that
`verify()` accepts, and the issue→verify round-trip tests that guard it. It is
suppressed with a reason rather than removed.

## Risks

- **Enabling real destruction.** Fixing D1–D3 means `/api/archival/…/approve` now
  actually deletes objects. That is the specified behaviour, it is gated on the
  `archivaris` group, it re-checks legal holds at execution time, and the parallel
  `/api/retention/…` route already does exactly this. Mitigated by the re-entrancy
  guard already present in the job.
- **Residual orphans (documented, not actioned):** `DestructionListMapper`,
  `DestructionList` entity and `SelectionListMapper` were used only by the deleted
  `ArchivalService`. They are left in place — removing entity/mapper/migration
  layers is out of scope for this change and carries schema risk. Noted for
  follow-up on #393.
