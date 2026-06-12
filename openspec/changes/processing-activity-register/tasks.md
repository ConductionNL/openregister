# Tasks: Processing Activity Register — Platform Verwerkingsregister & Verwerkingenlogging

## Phase 1 — Verwerkingsactiviteit hardening (OR-PA-1)

- [ ] 1.1 Migration + entity: extend `Verwerkingsactiviteit` with `specialCategories`/`specialCategoriesBasis`, `legitimateInterestAssessment`, `rechtsgrondReferentie`, `confidential`, `flagged`, `ownerUserId`, `reviewIntervalMonths`/`nextReviewAt`, lifecycle field (`draft|active|retired`, migrating existing `concept` → `draft`).
- [ ] 1.2 `VerwerkingsactiviteitValidator`: Art. 6 enum, Art. 9 basis when `specialCategories`, LIA when `legitimate-interest`, ISO-8601 `bewaartermijn`; 422 + structured error `{field, value, allowed}` from `VerwerkingsactiviteitenController` create/update.
- [ ] 1.3 Versioning: route every mutation of an `active` activity through the immutable audit trail (before/after, actor, timestamp) with prior versions retrievable; lifecycle transitions audited; `retired` excluded from attribution resolution.
- [ ] 1.4 Review sweep: daily background job notifying `ownerUserId` (NC notification, nl/en) when `nextReviewAt` enters the configured window.
- [ ] 1.5 Unit tests: each validation path (happy + 422), lifecycle transitions, active-mutation audit entry, retired-resolution behaviour, review notification.

## Phase 2 — `x-openregister-processing` dialect (OR-PA-2)

- [ ] 2.1 Annotation schema + `ProcessingAnnotationValidator` (mirroring the notification-dialect validator): `activities[]` (validated per 1.2), `attribution` (default + per-operation `read|create|update|delete|export`), `logReads` boolean; 422 + structured error on schema/register save.
- [ ] 2.2 Seeding: register-import hook upserting catalogue entries by `(organisation, code)` — create as `draft`, never overwrite existing fields, never duplicate; seed the per-organisation fallback activity `niet-geclassificeerde-verwerking` (`flagged: true`).
- [ ] 2.3 Attribution resolution: extend `AuditTrailMapper::resolveProcessingActivityId()` to the new dialect (schema → register inheritance, per-operation override, new-dialect-wins precedence over the legacy string key); keep `ObjectEntity::setProcessingActivityId()` imperative override semantics.
- [ ] 2.4 `AvgComplianceService`: accept either annotation form in `findUnannotatedSchemasWithPii()`; add fallback-attribution counts per register/schema to `runAllChecks()` (OR-PA-4 surface).
- [ ] 2.5 Unit tests: seed/upsert idempotency + no-overwrite, inheritance + precedence, per-operation override, legacy back-compat, 422 grammar paths, compliance-check variants.

## Phase 3 — Processing log storage + read instrumentation (OR-PA-3, OR-PA-4)

- [ ] 3.1 Migration `oc_openregister_processing_log` (columns + indexes per design D3: per-betrokkene composite, `(register_id, activity_id, created)`, `created`).
- [ ] 3.2 `ProcessingLogEntry` + `ProcessingLogMapper` (insert-batch, findBySubject, findFiltered, countByActivity, deleteCreatedBefore — no update/delete-single API).
- [ ] 3.3 `ProcessingLogService::log()` + read-path instrumentation at the object read/search/export boundaries (REST, GraphQL, MCP, public) for schemas resolving `logReads: true`; list/search collapse to one entry with `objectCount` (+ identifiers when ≤100); actor = NC user or API-client identifier; channel detection; subject-identifier extraction from schema-declared fields.
- [ ] 3.4 Fallback attribution: unresolvable/draft/retired attribution → fallback activity, never dropped, never activity-less.
- [ ] 3.5 Unit tests: opt-in vs no-opt-in, single read, bulk collapse (50 vs 150 objects), channel/actor matrix, export action, per-operation attribution, fallback paths.

## Phase 4 — Emission pipeline + retention (OR-PA-5, OR-PA-6)

- [ ] 4.1 Buffered emission: in-request buffer, post-response batched flush; spool-to-appdata on flush failure + retry background job; admin warning on persistent failure (threshold configurable). Primary action never blocked or failed by logging.
- [ ] 4.2 Retention: app config `processing_log_retention` (default `P3Y`); prune job hard-deleting by `created` index in batches; prune run recorded (period + count).
- [ ] 4.3 Append-only enforcement: no update/delete routes on any surface; RBAC denial tests for REST/GraphQL/MCP.
- [ ] 4.4 Unit tests: flush batching, outage spool + recovery, persistent-failure warning, prune correctness + its record, confidential exclusion at the mapper level.

## Phase 5 — Exports + per-subject extract (OR-PA-7)

- [ ] 5.1 `Art30ExportService`: active activities, full Art. 30(1) column set, register filter, controller-identity header; JSON / CSV (UTF-8 BOM, row per activity) / PDF; structural no-literal-PII DTO; format 400 / range 422 (`processing_export_max_range_days`, default 366).
- [ ] 5.2 Per-subject extract: join `processing_log` (by idType+idValue+period) with the subject's write-side audit-trail entries; per entry timestamp/action/actor/channel + activity name/doelbinding/rechtsgrond; confidential excluded for non-FG; generation logged as `action: export`.
- [ ] 5.3 Unit + integration tests: header content, slice filter, joined extract correctness, no-literal-PII byte-scan (seeded value + file name), validation paths, export-is-logged.

## Phase 6 — VNG API + access model (OR-PA-8, OR-PA-9)

- [ ] 6.1 Bearer auth: token issuance/config with `verwerkingenlogging` (+ FG) scopes and optional register restriction; 401/403 semantics.
- [ ] 6.2 VNG Logging Verwerkingen-shaped endpoints: verwerkingsacties list/filter (betrokkene, period, activity, actor) + verwerkingsactiviteiten, paginated, standard resource shape; confidential gated on FG scope; register-restricted tokens see their slice.
- [ ] 6.3 Access model: admin-default posture on all surfaces (gate-5 route-auth declared), privacy-officer group delegation for inquiry/export, organisation scoping on every query, register filter parameter on every list surface.
- [ ] 6.4 Tests: Newman collection for the bearer API (auth matrix, filters, pagination, confidential gating); PHPUnit for delegation + tenant isolation.

## Phase 7 — UI, spec sync, fleet coordination

- [ ] 7.1 Platform UI: verwerkingsregister admin section (activity list/detail with lifecycle + validation errors), FG inquiry view (subject/period/activity/actor filters + extract download), Art. 30 export form, compliance-gap (fallback count) surfacing; Playwright e2e per gate-19; English i18n source keys + nl.
- [ ] 7.2 Sync the `avg-verwerkingsregister` delta into `openspec/specs/avg-verwerkingsregister/spec.md` on archive; reconcile the main spec's "modeled as an OR register and schema" prose with design D1 (platform entity); update docs (annotation dialect reference, read-log semantics, export/API guide). Bump `appinfo/info.xml` `<version>`.
- [ ] 7.3 Notify the superseded changes: comment on procest `avg-verwerkingenlogging`, docudesk `processing-activity-export`, and scholiq `avg-verwerkingsregister` with the design.md supersession map so each is thinned to catalogue + UI surfacing + domain export inclusion before apply.
