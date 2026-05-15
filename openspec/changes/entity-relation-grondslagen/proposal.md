## Why

`EntityRelation` records the link between a detected entity and the chunk/file/object/email it was found in, plus its anonymisation outcome (`anonymized`, `anonymizedValue`). What it does NOT record is the **legal basis** (grondslag) under which the anonymisation was performed.

Consuming apps — primarily DocuDesk — need to associate per-entity legal bases (Woo Art. 5 grondslagen and equivalents) with each anonymisation event for audit, compliance reporting (Wet open overheid), and downstream rendering (e.g. attaching a "grondslagenpagina" to the anonymised document). Today this metadata has nowhere to live. The choices were either to scatter it across consumer-app schemas (loses the link to the actual EntityRelation row that drove the redaction) or to never persist it (loses the audit trail entirely).

This change adds a single optional `bases` JSON column to `EntityRelation` and exposes an audited write path for it: a new `PATCH /api/entity-relations/{id}` HTTP endpoint backed by an `EntityRelationMapper::updateDecisionMetadata` DI method. The two surfaces share one audited write path so both HTTP and in-process callers (DocuDesk currently injects the mapper via OR's DI) inherit the audit-trail semantics from a single place.

**Architecture note (added 2026-05-12 after explore-mode investigation).** Earlier drafts of this spec described "the anonymise endpoint accepts `bases`, persists, then strips before forwarding to OpenAnonymiser". That picture was wrong on two counts: (1) OpenAnonymiser is called during *detection* (`EntityRecognitionHandler::detectWithOpenAnonymiser`), not at anonymise time; (2) the anonymise step itself (`DocumentProcessingHandler::anonymizeDocument`) does local text-replacement only — no external forwarding. Bases are decision metadata that sit between detection and anonymise, so they get their own audited write path rather than riding on either flow.

## What Changes

- **NEW** Optional `bases` JSON column on `EntityRelation` — array of UUIDs referencing `base` schema objects (the grondslag vocabulary owned by the consuming app, e.g. DocuDesk's `dossier` register). OpenRegister stores the array verbatim; it does NOT validate that the UUIDs resolve, since the vocabulary lives outside OpenRegister's own schemas.
- **NEW** Boolean `skip_anonymization` column on `EntityRelation` (default `false`). Records the operator decision that a particular detected entity occurrence should **not** be redacted during the anonymise pass — used by DocuDesk's review-UI "skip this entity" gesture.
- **NEW** `PATCH /api/entity-relations/{id}` HTTP endpoint with a strict field whitelist of **decision-only** fields: `bases` and `skipAnonymization`. The post-hoc system fields (`anonymized`, `anonymizedValue`) are intentionally **NOT** editable here — those record what the anonymise pass actually did and are written by `markAsAnonymized`, never by the operator. Non-whitelisted fields in the body MUST be rejected with HTTP 400. The body MAY include any subset of the whitelist.
- **NEW** `EntityRelationMapper::updateDecisionMetadata(int $id, array $fields): EntityRelation` — DI parallel to the HTTP PATCH. Same whitelist, same shape validation, same audit-trail. Single audited write path: the HTTP controller is a thin wrapper.
- **NEW** Database migration adds the `bases` (nullable JSON) and `skip_anonymization` (boolean, default `false`) columns to the `oc_openregister_entity_relations` table. Existing rows are unaffected (`bases` defaults to NULL; `skip_anonymization` defaults to `false`). Migration is forward-only and idempotent.
- **NEW** Audit-trail wiring: every successful `updateDecisionMetadata` write that produces a non-empty diff emits one audit-trail entry via OpenRegister's existing immutable subsystem (ADR-022) — per-field previous → new values, actor UID (per ADR-005, never the display name), timestamp, and row identifier. Semantic no-ops (PATCH whose values match the current row state, or PATCH with body `{}`) MUST NOT write an audit entry. Reads MUST NOT produce audit entries.
- **MODIFIED** Anonymise flow honours `skipAnonymization`. Both `FileTextController::anonymizeFile` (HTTP) and `FileService::anonymizeDocument` (DI) MUST filter out relations whose `skipAnonymization` is `true` before running text-replacement. `EntityRelationMapper::markAsAnonymized` MUST NOT flip `anonymized=true` on skipped rows. Skipped rows retain `anonymized=false`; the operator decision is preserved and queryable via the `skipAnonymization` flag.
- **UNCHANGED** OpenAnonymiser integration. `EntityRecognitionHandler::detectWithOpenAnonymiser` is the only place OpenAnonymiser is called (during detection), and this change does not touch it. `bases` and `skipAnonymization` are decision metadata applied between detection and anonymise.
- **UNCHANGED** `markAsAnonymized`'s role as the path that writes `anonymized=true` / `anonymizedValue` on rows that *were* redacted. This change adds a filter (skip relations where `skipAnonymization=true`) but does not change which path owns those fields.

### Out of scope

- A full REST surface on entity-relations (`GET /api/entity-relations`, list/show, `DELETE`). The PATCH endpoint is the minimum needed for this change; further verbs can be added under a separate change if a use case appears.
- A batch PATCH endpoint (`PATCH /api/entity-relations` with `entries[]`). DocuDesk loops if it needs to update decision metadata on multiple relations; a real volume-driven use case can prompt a follow-up batch surface.
- Letting operators edit `anonymized` or `anonymizedValue` directly via the new PATCH endpoint. Those fields record what the redaction *did*; only the redaction code path may write them. The whitelist is decision-only by design.
- Validating that `bases` UUIDs resolve to known `base` objects. Consumer-app concern (see design D2).
- The DocuDesk-side adjustment to call the new PATCH endpoint instead of riding `bases` on its anonymise call. Tracked in the paired DocuDesk change `anonymisation-grondslagen-and-prohibition-gate` (the DD spec ships before this OR rework lands and will need a parallel amendment; flagged for that change's apply phase).

## Capabilities

### New Capabilities

- `entity-relation-grondslagen`: optional bases-link on `EntityRelation`, an operator-decision skip-flag, and an audited per-relation PATCH endpoint + parallel DI mapper method exposing those two decision fields under a strict whitelist. Per-relation storage gives per-position granularity — operators *can* make different decisions for different occurrences of the same entity in the same file if their UX surfaces that (DocuDesk's review UI typically aggregates at entity level for ergonomic reasons; the OR surface preserves the finer granularity for any consumer that wants it).

### Modified Capabilities

(none — the broader entity-recognition / anonymisation pipeline is implemented but currently uncovered by an OpenSpec capability; this change does not retroactively spec it.)

## Cross-app Dependencies

- **Hard** — `docudesk:anonymisation-grondslagen-and-prohibition-gate` (consumer). The DD prohibition-gate change describes attaching bases via the anonymise endpoint payload — that DD spec needs amending in lock-step with this OR rework so the contracts agree. Apply ordering: this OR change applies first (specs + implementation), then the DD spec is amended, then the DD prohibition-gate change applies against the new contract.
- **Soft** — `docudesk:add-dossier-schema` (already merged). Provides the `base` register that supplies the UUIDs DocuDesk callers will send. Independent — both can land in any order.

## Impact

- **Code (openregister):**
  - `lib/Db/EntityRelation.php` — new `bases` property (JSON, nullable, default null) and `skipAnonymization` property (boolean, default false); `addType` registrations; magic-method getter/setter docblocks; `jsonSerialize()` includes both after `anonymizedValue`.
  - `lib/Db/EntityRelationMapper.php` — new method `updateDecisionMetadata(int $id, array $fields): EntityRelation` (implements whitelist enforcement, shape validation, audit-trail emission, single transactional write); `markAsAnonymized` updated to skip rows with `skip_anonymization=true`.
  - `lib/Controller/EntityRelationsController.php` — NEW controller. Single method (PATCH) for now; structured to host future GET/show without renaming.
  - `lib/Controller/FileTextController.php` — `anonymizeFile` filters out skipped relations before building the replacements list.
  - `lib/Service/FileService.php` / `lib/Service/File/DocumentProcessingHandler.php` — the DI `anonymizeDocument` path also honours `skipAnonymization` when given a payload that joins with persisted state (the caller-passed `entities[]` array is filtered server-side against the persisted skip flag).
  - `appinfo/routes.php` — add the PATCH route under `entityRelations#update`.
  - `lib/Migration/Version1Date20260512<HHMMSS>.php` — adds the `bases` and `skip_anonymization` columns. Idempotent.
- **API contract:** One new HTTP endpoint (`PATCH /api/entity-relations/{id}`). The existing anonymise endpoint (`POST /api/files/:fileId/anonymize`) keeps its shape but its behaviour changes: rows where `skip_anonymization=true` are filtered out before text-replacement runs.
- **Database:** Two columns added to `oc_openregister_entity_relations`. Migration is forward-only and zero-downtime.
- **Audit trail:** Every successful PATCH (HTTP or DI) with a non-empty diff writes one audit entry summarising changed fields (`bases`, `skipAnonymization`), with previous and new values, actor UID, timestamp, and the row identifier. Semantic no-ops do not write audit entries. Reads do not produce audit entries.
- **Authorization:** PATCH requires write-access to the relation's parent file/object — same implicit check that `markAsAnonymized` inherits today. Admin role MUST NOT be required. ADR-005 + ADR-023 anchor: no extra action-level permission is added in this change; if a future change wants per-field action authz, it MUST add a new Requirement here.
- **Cross-app:**
  - **DocuDesk** is the immediate consumer. The paired change `anonymisation-grondslagen-and-prohibition-gate` in DocuDesk currently expects to ride bases on the anonymise call; that DD spec needs amending alongside this rework.
  - **opencatalogi** and **softwarecatalog** do not call entity-relation endpoints; unaffected.
- **Tests:** Mapper unit tests for the new columns + the `updateDecisionMetadata` method (whitelist, shape, semantic-no-op behaviour, audit emission). Controller tests for PATCH (HTTP shape, authorization, whitelist rejection). Anonymise-flow regression tests for the skip filter (relations with skip=true are not redacted; `markAsAnonymized` does not flip them). Migration smoke test.