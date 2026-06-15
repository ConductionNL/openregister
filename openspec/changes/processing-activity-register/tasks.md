# Tasks: Processing Activity Register — Platform Verwerkingsregister & Verwerkingenlogging

> Scope note (2026-06-14): this PR delivers ONLY the genuinely-missing
> per-access READ-LOGGING delta (OR-PA-3/4/5 read-side + the access-guarded
> query/extract surface of OR-PA-7/8). Most of the rest of this change
> already ships on `development` (verified by audit below) and is checked
> `[x]`; the remaining hardening/exports/VNG-API/UI work is genuinely not
> yet built and is marked `[~]` with a reason — to be delivered by follow-up
> changes, not silently claimed.

## Phase 1 — Verwerkingsactiviteit hardening (OR-PA-1)

- [~] 1.1 Migration + entity extra fields (`specialCategories`, lifecycle `draft|active|retired`, owner/review). NOT in this delta — the shipped `Verwerkingsactiviteit` entity carries the core Art. 30(1) fields + `status` (concept/published/archived) but not the new special-category/lifecycle/review columns. Out of scope for the read-log delta; follow-up hardening change.
- [~] 1.2 `VerwerkingsactiviteitValidator` (Art. 6 enum, Art. 9 basis, LIA, 422). PARTIAL on development: `VerwerkingsactiviteitMapper::validate()` already enforces naam/doelbinding/rechtsgrond (Art. 6 vocabulary) on insert/update via `InvalidArgumentException`. The structured 422 controller contract + Art. 9/LIA paths are follow-up hardening; not in this delta.
- [~] 1.3 Versioning of `active` mutations via the audit trail. NOT in this delta — depends on the new lifecycle field (1.1). Follow-up.
- [~] 1.4 Review-sweep background job. NOT in this delta — depends on owner/review fields (1.1). Follow-up.
- [~] 1.5 Unit tests for 1.1–1.4. NOT in this delta (depends on the above).

## Phase 2 — `x-openregister-processing` dialect (OR-PA-2)

- [~] 2.1 Annotation schema + `ProcessingAnnotationValidator` (full save-time 422). PARTIAL: this delta READS the new `x-openregister-processing` dialect (`logReads`, `attribution`, `subjectIdFields`) in `ProcessingLogService` and accepts it in `AvgComplianceService`. The dedicated save-time `ProcessingAnnotationValidator` (422 on malformed annotation) is follow-up.
- [~] 2.2 Seeding of the app catalogue on register import + per-org fallback seed. PARTIAL: the flagged fallback activity (`niet-geclassificeerde-verwerking`) IS seeded lazily by `ProcessingLogService::fallbackActivityUuid()` (find-or-create per organisation). The full register-import upsert-by-code catalogue seeder is follow-up.
- [x] 2.3 Attribution resolution. ALREADY SHIPS for writes: `AuditTrailMapper::resolveProcessingActivityId()` resolves the legacy `x-openregister-processing-activity` (schema→register inheritance + `ObjectEntity::setProcessingActivityId()` override). This delta adds the new-dialect + per-operation read/export resolution + retired/draft-falls-back precedence in `ProcessingLogService::resolveAttribution()`.
- [x] 2.4 `AvgComplianceService`: accept either annotation form; add fallback-attribution count. DONE: `findUnannotatedSchemasWithPii()` now accepts the new dialect's `attribution.default` (via `configHasAttribution`); `runAllChecks()` reports `totals.unclassifiedProcessing` from `countUnclassifiedProcessing()`.
- [x] 2.5 Unit tests: dialect detection + legacy back-compat + fallback. DONE for the read-log paths (`ProcessingLogServiceTest`: dialect opt-in, legacy-no-reads, fallback, per-operation override). Full seed/upsert-idempotency tests are follow-up (2.2).

## Phase 3 — Processing log storage + read instrumentation (OR-PA-3, OR-PA-4)

- [x] 3.1 Migration `oc_openregister_processing_log` (columns + indexes per design D3). DONE — `Version1Date20260614000000` with per-subject composite, `(register_id, activity_id, created)`, `created`, `actor` indexes; unique `uuid`.
- [x] 3.2 `ProcessingLogEntry` + `ProcessingLogMapper` (insert / insertBatch / findBySubject / findFiltered / countByActivity / deleteCreatedBefore — no update/delete-single). DONE.
- [x] 3.3 `ProcessingLogService::logRead()` + read-path instrumentation. DONE — hooked into `ObjectService::find()` (the canonical single-object read boundary), gated on `logReads: true`; actor (NC user / `system`), channel detection, subject-identifier extraction from schema-declared `subjectIdFields`. List/search collapse provided via `logReadList()` (one entry with `objectCount`). GraphQL/MCP/public boundary wiring is a follow-up (the service API + the find() hook are the foundation).
- [x] 3.4 Fallback attribution: unresolvable/draft/retired → seeded flagged fallback, never dropped, never activity-less. DONE.
- [x] 3.5 Unit tests: opt-in vs no-opt-in, single read, bulk collapse, channel/actor, export action, per-operation attribution, fallback. DONE (`ProcessingLogServiceTest`, 10 tests).

## Phase 4 — Emission pipeline + retention (OR-PA-5, OR-PA-6)

- [x] 4.1 Buffered emission: in-request buffer + batched `flush()`; fail-soft (the read is never blocked or failed by logging; the buffer is retained on flush failure for retry). DONE. The asynchronous post-response/spool-to-appdata + admin-warning escalation is a follow-up refinement on this foundation.
- [~] 4.2 Retention prune job + `processing_log_retention` config. PARTIAL: the mapper exposes `deleteCreatedBefore()` (the prune primitive) and the controller reads `processing_export_max_range_days`. The scheduled prune BackgroundJob + `processing_log_retention=P3Y` default wiring is follow-up.
- [x] 4.3 Append-only enforcement: no update/delete routes on any surface. DONE — the mapper has no update/single-delete API; the controller exposes GET-only endpoints (asserted by `testControllerHasNoMutationEndpoints`).
- [x] 4.4 Unit tests: flush batching, fail-soft on flush failure, confidential exclusion at the mapper level. DONE for flush/fail-soft (`ProcessingLogServiceTest`) and confidential-gating (`ProcessingLogControllerTest`: FG-only). Outage-spool recovery + prune-record tests are follow-up (4.2).

## Phase 5 — Exports + per-subject extract (OR-PA-7)

- [x] 5.1 / 5.2 Per-subject (betrokkene) extract endpoint. DONE for the read-log side: `ProcessingLogController::betrokkene()` queries `processing_log` by `(idType, idValue, period)`, FG-gated, range-bounded (422), confidential-excluded for non-FG. The JOIN with the write-side audit trail and the org-level Art. 30 JSON/CSV/PDF export with the no-literal-PII byte contract are follow-up (`Art30ExportService`).
- [~] 5.3 Art. 30 export + no-literal-PII byte-scan tests. NOT in this delta — the aggregate Art. 30 export service is follow-up. The per-subject extract validation paths ARE tested (`ProcessingLogControllerTest`).

## Phase 6 — VNG API + access model (OR-PA-8, OR-PA-9)

- [x] 6.3 Access model: admin-default posture (no `#[NoAdminRequired]`; NC SecurityMiddleware default) + privacy-officer (FG) group delegation + organisation scoping + register filter on every list surface. DONE for the read-log inquiry/extract surfaces (`ProcessingLogController`); fails closed for non-admin/non-FG; no cross-tenant IDOR.
- [~] 6.1 / 6.2 Bearer-token-gated VNG Logging Verwerkingen-shaped API. NOT in this delta — the standard-shaped bearer API is follow-up; the access-guarded session-authenticated query surface is the foundation it will reuse.
- [~] 6.4 Newman bearer-API collection. NOT in this delta (depends on 6.1/6.2). PHPUnit for delegation + tenant isolation IS done (`ProcessingLogControllerTest`).

## Phase 7 — UI, spec sync, fleet coordination

- [~] 7.1 Platform verwerkingsregister UI + FG inquiry view + Playwright e2e. NOT in this delta — backend read-logging only; UI is follow-up.
- [x] 7.2 Spec sync of the delta into `openspec/specs/avg-verwerkingsregister/spec.md` (read-log requirements) + `appinfo/info.xml` version bump. DONE for the read-log delta on archive (the OR-PA-3/4/5/6 read-side + OR-PA-7 extract + OR-PA-8 access requirements are reflected in the main spec's implementation status). Annotation-dialect docs + Art. 30 export guide follow with their respective phases.
- [~] 7.3 Notify the superseded per-app changes (procest/docudesk/scholiq). Cross-app coordination action — out of scope of this OR PR (tracked by the orchestration, not a code change here).
