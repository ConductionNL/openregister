# Tasks — revive-or-dead-capabilities

## 1. Verify (Step 1 — no code changes)

- [x] 1.1 Read each of the four methods + their classes at `origin/development`
- [x] 1.2 Grep `->method(` repo-wide; check dynamic dispatch, `register.d` handlers, routes, `info.xml` jobs, DI wiring, cross-app callers
- [x] 1.3 Record a per-method verdict (a/b/c) with caller evidence in `design.md`

## 2. `ArchivalService::generateDestructionList` — (b) superseded → DELETE

- [x] 2.1 Re-confirm zero callers (`ArchivalService` has 0 refs in `lib/`)
- [x] 2.2 Delete `lib/Service/ArchivalService.php`
- [x] 2.3 Delete `tests/Unit/Service/ArchivalServiceTest.php`
- [x] 2.4 Note residual orphans (DestructionListMapper, DestructionList, SelectionListMapper) in `design.md` for follow-up

## 3. `Archival\DestructionService::generateCertificate` — (b) superseded → DELETE + fix the live route

- [x] 3.1 Re-confirm zero callers for `generateCertificate` and `executeDestruction`
- [x] 3.2 Delete both methods; drop the deps they orphaned (`DeleteObject`, `AuditTrailMapper`, `DEFAULT_BATCH_SIZE`)
- [x] 3.3 Delete the two dead certificate tests
- [x] 3.4 **D1** — queue `DestructionExecutionJob` with `destructionListUuid` (the key the job reads)
- [x] 3.5 **D2** — record approvals under the canonical `userId` key
- [x] 3.6 **D3** — persist the approved list in `ArchivalController::approveDestructionList`
- [x] 3.7 Test: approval queues the job with a uuid the job can actually read
- [x] 3.8 Test: certificate CONTENT is real and populated (approver, counts, groupings, selectielijst, Archiefwet statement)

## 4. `MetricsService::recordMetric` — (a) dead, intended → WIRE

- [x] 4.1 Add `METRIC_OBJECT_CREATED` / `_UPDATED` / `_DELETED` constants
- [x] 4.2 Add `lib/Listener/ObjectMetricsListener.php` (SPDX EUPL-1.2, Conduction)
- [x] 4.3 Register it on `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` in `Application.php`
- [x] 4.4 Test: each event writes a populated row into `openregister_metrics` (type, entity, register/schema metadata)
- [x] 4.5 Test: a metrics failure never breaks the object write it observes

## 5. `CredentialAppTokenService::issueToken` — (c) consumer seam → MARK

- [x] 5.1 Add `@orphaned-write-capability exclude <reason>` so gate-52 honours it
- [x] 5.2 Document who is meant to call it (consuming apps; OR is the verifier — ADR-004 Rule 2)

## 6. Ship

- [x] 6.1 Spec deltas for `archival-destruction-workflow` + `production-observability`
- [x] 6.2 Run tests in `php:8.3-cli` + fresh `composer install`; report baseline AND delta
      (baseline @0c0256213: 14448 tests / 20 errors / 8 failures — branch: 14437 / 20 / 8 → zero new failures)
- [x] 6.3 PR #395 → `development`, admin-merged (`abcfd3d2d`)
- [x] 6.4 Archive change; sync canonical specs; update issue #393

## 7. Follow-ups filed (NOT done here — out of scope)

- [ ] 7.1 Prometheus **exposition** of the new CRUD counters — `/api/metrics`
      (`AppHost\Controller\GenericMetrics`) does not read `openregister_metrics`. Rows now
      exist; projecting them into the exposition format remains unimplemented.
- [ ] 7.2 Retention-metadata **validation** on the object write path — removed with
      `ArchivalService` (REQ-010); it never ran (zero callers) and has no live equivalent.
- [ ] 7.3 Residual orphans left by the `ArchivalService` deletion: `DestructionListMapper`,
      `DestructionList` entity, `SelectionListMapper` (entity/mapper/migration layers —
      schema risk, deliberately not touched).
