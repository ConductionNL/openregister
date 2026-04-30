# Tasks: AVG Verwerkingsregister

> **Status (Phase 2a):** Foundation + CRUD controller + Art 30 §4 verantwoordingsdocument shipped. Phase 2a adds `VerwerkingsactiviteitenController` with admin-gated write paths (create / update / soft-delete-as-archive), open-read paths (list / show / verantwoording), and the audit-trail aggregation join that surfaces lifetime CRUD counts per processing activity for AP supervisory review. 16 integration tests across two suites. Live API verified end-to-end with curl. **DSAR endpoints** (Art 15 inzage / Art 16 rectificatie / Art 17 vergetelheid / Art 20 portabiliteit) remain in Phase 2b.

## Implemented (Phase 1)

- [x] **Verwerkingsregister modeled as a dedicated catalog table.** `lib/Migration/Version1Date20260430160000.php` adds `oc_openregister_verwerkingsactiviteiten` per AVG Art 30 §1 with all 17 catalogue columns + 4 indexes. Rationale: fixed AVG-mandated schema doesn't need OpenRegister flexibility, and the audit-trail FK is cleaner pointing at a stable table than at a register-managed object.

- [x] **Article 6 GDPR legal-basis vocabulary enforced.** `Verwerkingsactiviteit::RECHTSGROND_VOCABULARY` = `[toestemming, overeenkomst, wettelijke_verplichting, vitaal_belang, publieke_taak, gerechtvaardigd_belang]`. `VerwerkingsactiviteitMapper::insert/update` reject unknown values with `InvalidArgumentException`. Closes the consent-as-legal-basis tracking primitive (Art 6(1)(a) + Art 7).

- [x] **Processing activities linked to schemas containing personal data.** Per the trigger contract documented in `design.md`, schemas (and registers, as fallback) carry the `x-openregister-processing-activity: <code|uuid>` annotation in their `configuration` column. `AuditTrailMapper::resolveProcessingActivityId` reads the annotation at write time and resolves it via `VerwerkingsactiviteitMapper::resolveReference` (code first, uuid second).

- [x] **All access to personal data logged with processing purpose.** `AuditTrailMapper::createAuditTrail` now writes the resolved `processing_activity_id` onto every audit row via the schema/register annotation lookup. Per-action override (`ObjectEntity::setProcessingActivityId()`) takes precedence — used by the upcoming data-subject rights endpoints to attribute reads/writes to a DSAR-specific processing activity.

- [x] **Verwerkingsactiviteit lifecycle (concept / published / archived).** `Verwerkingsactiviteit::STATUS_VOCABULARY` enforces the transitions; mapper validation rejects unknown statuses. Hard-delete is preserved at the mapper level but operationally we recommend `status='archived'` to avoid orphan audit-trail FKs.

- [x] **Multi-tenant privacy isolation.** `organisation_id` column on the table + filter in `VerwerkingsactiviteitMapper::findAll(?string $organisationId)`. Single-row finds bypass the filter so audit hooks can resolve cross-tenant explicit references when needed.

- [x] **Privacy-specific audit trail.** Existing `oc_openregister_audit_trails` already includes `processing_activity_id`, `processing_activity_url`, `processing_id`, `confidentiality`, `retention_period`, hash-chained tamper evidence (`hash`/`previous_hash`). Phase 1 wires the missing FK target so the existing columns are populated automatically — no separate "privacy audit" table needed.

- [x] **CRUD endpoints for the verwerkingsregister catalog (Phase 2a).** `lib/Controller/VerwerkingsactiviteitenController.php` exposes `GET /api/avg/verwerkingsactiviteiten` (list), `GET .../{id}` (show by id|uuid|code), `POST .../` (admin-only create returning 201), `PUT .../{id}` (admin-only update), `DELETE .../{id}` (admin-only soft-archive — flips `status='archived'` so audit-trail FKs stay resolvable, never hard-deletes). Validation errors land as 422 envelopes; unknown identifiers as 404; non-admin write attempts as 403. Read paths intentionally don't gate on admin (AVG Art 30 §4 requires the register to be available to supervisory authorities and indirectly to data subjects).

- [x] **Art 30 register MUST be exportable for the Autoriteit Persoonsgegevens (Phase 2a).** `GET /api/avg/verantwoording` joins each verwerkingsactiviteit with audit-trail row counts (per action) attributed to it via `processing_activity_id`. Response carries `{count, activities: [{...activity, activity: {totalEvents, byAction: {create, update, delete, read}}}]}`. The lifetime counters give AP reviewers + the operator's annual `verantwoordingsdocument` a single-query view of "how many times has each declared processing activity actually been performed". Verified live by curl + integration test.

## Open (Phase 2b — data-subject rights + UI)

- [ ] **Inzageverzoek endpoint (Art 15 AVG).** `GET /api/avg/inzage?subject={uuid|email|bsn}` — composes `GdprEntity` lookup + audit-trail filter + RBAC-aware object listing.
- [ ] **Recht op rectificatie endpoint (Art 16 AVG).** `POST /api/avg/rectificatie?subject=...` — wraps existing object update with DSAR attribution via the per-action override.
- [ ] **Recht op vergetelheid endpoint (Art 17 AVG).** `POST /api/avg/vergetelheid?subject=...` — bulk soft-delete with audit retention exception (the deletion itself is audit-logged for legal defence; the personal data is removed from active records).
- [ ] **Recht op dataportabiliteit endpoint (Art 20 AVG).** `GET /api/avg/portabiliteit?subject=...&format=json` — composes DSAR scoping + `ExportService`.
- [ ] **Frontend management UI.** Vue management screens for operators to maintain the catalog (Phase 2a ships the REST surface; the UI layer is a separate effort).
- [ ] **DPIA (Art 35) tracking.** Out of scope for Phase 1; would extend the table with DPIA references + integrate with the existing risk-assessment workflow.
- [ ] **Verwerkersovereenkomst tracking.** Third-party processors registered as a sub-entity of the verwerkingsactiviteit (or as `ontvangers` entries with type=processor + agreement reference).
- [ ] **Automated PII detection flagging unregistered processing.** Extend the existing `GdprEntity` PII-detection layer to cross-check whether the host schema has a `x-openregister-processing-activity` annotation; flag misses to admins.
- [ ] **Retention enforcement (auto-delete/anonymise on bewaartermijn expiry).** Wire a TimedJob that walks audit rows by `processing_activity_id`, computes expiry from `bewaartermijn`, and queues retention actions. Builds on existing `ObjectRetentionHandler`.

## Test coverage

- [x] `tests/Service/AvgVerwerkingsregisterIntegrationTest` (Phase 1) — 9 integration tests against real Postgres + the live audit-trail mapper:
  - Mapper round-trip (insert / find / update with default-status auto-fill).
  - Validation rejects invalid `rechtsgrond`.
  - Validation rejects missing `naam`.
  - Validation rejects missing `doelbinding`.
  - `resolveReference` finds by code and by uuid; unknown reference returns null.
  - Audit-trail hook honours schema annotation (`x-openregister-processing-activity` on schema config tags every audit row through that schema).
  - Audit-trail hook falls back to register annotation when schema is unset.
  - Audit-trail per-action override (`ObjectEntity::setProcessingActivityId()`) beats schema/register defaults.
  - Audit-trail hook leaves `processing_activity_id` unset when no annotation exists (preserves existing behaviour for callers that haven't opted in).

- [x] `tests/Service/VerwerkingsactiviteitenControllerIntegrationTest` (Phase 2a) — 7 integration tests covering list / show-by-id-uuid-code / admin-only create gating / 422 validation envelope / 201 create round-trip / soft-archive on DELETE / verantwoording aggregation join. Live API verified end-to-end via curl: `POST /api/avg/verwerkingsactiviteiten` returns 201 with persisted entity; `GET /api/avg/verantwoording` returns the catalog with the audit-aggregate slot populated.

## Architecture (Phase 1 decisions taken)

| Decision | Choice |
|---|---|
| Table shape | Dedicated `oc_openregister_verwerkingsactiviteiten` (NOT a register that eats its own dogfood). |
| Trigger linkage precedence | Per-action override (`ObjectEntity::processingActivityId`) > per-schema annotation > per-register annotation > unset (no tag). |
| Audit-trail FK direction | `audit_trails.processing_activity_id` is a soft FK to `verwerkingsactiviteiten.uuid`. Hash-chained tamper evidence locks the audit content; no hard FK constraint needed. |
| Reference form | Both `code` (short readable) and `uuid` (canonical) accepted; resolver tries `code` first. |
| Vocabulary validation | Mapper-level `InvalidArgumentException` for `rechtsgrond` and `status`; required-field check for `naam` and `doelbinding`. |
| Multi-tenant isolation | `organisation_id` on the table + filter on listing queries; single-row lookups (audit hook resolution path) bypass the filter so cross-tenant references can be explicit. |
| Authorization split (Phase 2a) | Read paths (list / show / verantwoording) open to any authenticated user — Art 30 §4 requires the register to be available to supervisory authorities + (indirectly via DSAR) to data subjects. Write paths (create / update / soft-delete) admin-only. |
| Soft-delete semantics (Phase 2a) | `DELETE` flips `status='archived'`, never hard-deletes — audit-trail rows reference activities by uuid as a soft FK and forensic legibility requires the catalog row to remain resolvable indefinitely. |
