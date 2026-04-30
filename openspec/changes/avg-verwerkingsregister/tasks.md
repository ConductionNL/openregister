# Tasks: AVG Verwerkingsregister

> **Status (Phase 2b):** Full DSAR surface shipped. Phase 2b adds `DsarService` (composes the GdprEntity index + entity_relations join + MagicMapper object lookup) and `DsarController` exposing the four AVG data-subject rights endpoints: Art 15 (inzage), Art 16 (rectificatie), Art 17 (vergetelheid, with `dryRun` support), Art 20 (portabiliteit). All write paths attribute their audit rows to the operator-configured DSAR processing activity via the per-action override on `ObjectEntity` — proves end-to-end that the Phase 1 trigger contract composes. 24 integration tests across three suites (Phase 1 audit trigger + Phase 2a CRUD + Phase 2b DSAR composition). Live API verified end-to-end with curl. Frontend management UI is the only remaining open task.

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

- [x] **Inzageverzoek endpoint (Art 15 AVG, Phase 2b).** `GET /api/avg/inzage?subject={value}&type={email|bsn|name|...}&mode={exact|ilike}`. `DsarService::findObjectsForSubject` walks `oc_openregister_entities` for rows matching the subject value (case-insensitive exact by default), joins `oc_openregister_entity_relations.object_id` to dedupe, and loads each owning `ObjectEntity` via MagicMapper. Response shape: `{subject, type, count, results: [{object, gdprEntities: [{type, value, category, detectedAt}]}]}`. Admin-only.

- [x] **Recht op rectificatie endpoint (Art 16 AVG, Phase 2b).** `POST /api/avg/rectificatie` body `{objectId, changes}`. `DsarService::rectifyObjectForSubject` loads the object, merges the change set into the existing payload, sets `setProcessingActivityId(<dsar uuid>)` on the entity, and calls `MagicMapper::update` — the audit-trail trigger contract (Phase 1) tags the resulting audit row with the configured DSAR processing activity. Admin-only. 422 on missing/empty payload, 404 on unknown object.

- [x] **Recht op vergetelheid endpoint (Art 17 AVG, Phase 2b).** `POST /api/avg/vergetelheid?subject=...&type=...&dryRun=true|false`. `DsarService::eraseObjectsForSubject` matches every object referencing the subject, optionally returns the matched set without acting (`dryRun=true` for the operator's confirmation UX), and otherwise sets the soft-delete metadata on each match (`{deletedBy, deletedAt, reason: 'avg-vergetelheid', subject}`) plus the DSAR processing-activity tag. Admin-only. The deletion itself is audit-logged for legal defence; the configured DSAR processing activity provides the legal basis under Art 17 §3(b).

- [x] **Recht op dataportabiliteit endpoint (Art 20 AVG, Phase 2b).** `GET /api/avg/portabiliteit?subject=...&type=...`. Same locator path as inzage but the response envelope is reduced to the machine-readable export shape: `{subject, generated, count, objects: [...]}` — no GdprEntity match annotations, just the canonical object payloads in the order they were found. Admin-only.

- [x] **DSAR processing-activity attribution.** `DsarService::getDsarProcessingActivityUuid` reads the `dsar_processing_activity` app-config key (defaults to the literal `dsar` code), resolves it via `VerwerkingsactiviteitMapper::resolveReference` (accepts both `code` and `uuid`), and returns the canonical uuid. All write paths (vergetelheid + rectificatie) set this on `ObjectEntity::setProcessingActivityId()` before persisting so the Phase 1 audit-trail hook tags the row correctly. When unset / unresolvable, the audit row falls through to schema/register defaults — no fatal error.

## Open (Phase 2c — UI)
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

- [x] `tests/Service/DsarServiceIntegrationTest` (Phase 2b) — 8 integration tests covering: GdprEntity → entity_relations → MagicMapper join (inzage); per-object dedup when one object has multiple PII hits; empty-result safety on unknown subject; `getDsarProcessingActivityUuid` resolution by `code`; vergetelheid `dryRun=true` reports matches without touching objects; vergetelheid live erase tags audit rows with the DSAR activity uuid (proves the Phase 1 trigger contract composes); rectifyObjectForSubject returns the updated envelope; rectifyObjectForSubject returns null for unknown objects. Live API verified end-to-end via curl: `GET /api/avg/inzage` and `POST /api/avg/vergetelheid?dryRun=true` both return 200 with the expected envelope shape.

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
| DSAR composition layer (Phase 2b) | A dedicated `DsarService` composes GdprEntity index + MagicMapper rather than embedding the join in the controller; keeps the controller thin and testable, and makes the same composition reusable from CLI tooling or scheduled jobs. |
| DSAR audit attribution (Phase 2b) | All DSAR write paths set `ObjectEntity::setProcessingActivityId()` before persisting so the Phase 1 audit-trail hook tags rows with the configured DSAR processing activity. The activity reference is operator-configurable via the `dsar_processing_activity` app-config key (defaults to `code='dsar'`). |
| Vergetelheid dry-run UX (Phase 2b) | `POST /api/avg/vergetelheid?dryRun=true` returns the matched set without acting — gives the operator a confirmation surface ("you're about to erase 12 objects, proceed?") before the destructive call. |
