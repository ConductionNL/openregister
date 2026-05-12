# Design — `entity-relation-grondslagen`

## Context

`EntityRelation` (`lib/Db/EntityRelation.php`) is the join row between a detected entity and its location — chunk, file, object, or email — plus position offsets, confidence, detection method, and the anonymisation outcome (`anonymized`, `anonymizedValue`). It is OpenRegister's authoritative record of "which entity was found where, at what confidence, and what was done about it."

What it does NOT carry is *why* a redaction was applied. Consumer apps — DocuDesk first — record per-anonymisation legal bases (Woo Art. 5 grondslagen and similar) for compliance reporting and audit. Today this metadata has no home: stuffing it into consumer-app schemas decouples it from the actual EntityRelation row that drove the redaction, and leaves the audit trail incomplete.

### Actual flow in OR (anchored during explore-mode investigation, 2026-05-12)

A faithful picture of how detection and anonymisation work in OpenRegister today:

```
┌────────────────────────────────────────────────────────────────────────┐
│ Detection time                                                         │
│ ──────────────                                                         │
│ TextExtractionService → EntityRecognitionHandler::detectEntities*      │
│   ├─ detectWithRegex / detectWithPresidio / detectWithOpenAnonymiser   │
│   │  (OpenAnonymiser is one of N detection backends — it runs HERE,   │
│   │  not at anonymise time. HTTP POST /api/v1/analyze on the           │
│   │  configured openAnonymiserApiEndpoint.)                            │
│   ▼                                                                    │
│   storeDetectedEntities → mapper->insert(new EntityRelation(...))      │
│   ↳ rows enter with anonymized=false, anonymizedValue=null,            │
│     bases=null                                                         │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Decision time (this change adds)                                       │
│ ────────────────────────────────                                       │
│ Operator (via DocuDesk's review UI) selects grondslagen per entity.    │
│ DocuDesk calls:                                                        │
│   PATCH /api/entity-relations/{id}  { bases: ["uuid-a", ...] }         │
│   - or, in-process via DI:                                             │
│   EntityRelationMapper::updateDecisionMetadata($id, ['bases' => ...]) │
│ ↳ bases column updated, audit-trail entry written                      │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Anonymise time (UNCHANGED by this change)                              │
│ ─────────────────────────────────────────                              │
│ Two surfaces today, both untouched:                                    │
│   • HTTP: POST /api/files/:fileId/anonymize                            │
│       (FileTextController; takes only fileId; looks up entities from   │
│       DB; calls FileService::anonymizeDocument)                        │
│   • DI: FileService::anonymizeDocument(Node, entities[])               │
│       (called by DocuDesk's AnonymizationService via DI lookup)        │
│ Both:                                                                  │
│   • call DocumentProcessingHandler::anonymizeDocument for local        │
│     PhpWord text-replacement (no external HTTP call here),             │
│   • call EntityRelationMapper::markAsAnonymized(fileId, ...) which     │
│     flips anonymized=true on the file's relations.                     │
│ Bases are visible on the row at this point (set earlier via PATCH)     │
│ but the anonymise flow does not consume them — they are consumer-app   │
│ metadata for downstream reporting.                                     │
└────────────────────────────────────────────────────────────────────────┘
```

The earlier draft of this design described "the anonymise endpoint accepts `bases`, persists, then strips before forwarding to OpenAnonymiser". That picture was wrong on two counts: OpenAnonymiser doesn't run at anonymise time (it's a detection-time backend), and nothing forwards to it at anonymise time. The decoupled-PATCH model below is the actual fit.

## Goals / Non-Goals

**Goals:**

- Add an optional `bases` JSON column on the `oc_openregister_entity_relations` table.
- Add a boolean `skip_anonymization` column on the same table, default `false`, as the operator's per-relation "don't redact this one" decision.
- Extend `EntityRelation` with getters/setters for both fields following the existing Nextcloud `Entity` base-class pattern.
- Expose an audited write path for both decision fields via a new `PATCH /api/entity-relations/{id}` endpoint plus a parallel DI mapper method.
- Modify the anonymise flow to honour `skip_anonymization`: skipped rows MUST NOT be redacted and MUST NOT have `anonymized` flipped to `true`.
- Make the change additive and non-breaking from the API-shape perspective: the existing anonymise endpoint keeps the same URL + signature; only its behaviour gains a filter. Pre-change clients that have never touched `skip_anonymization` see identical outcomes.
- Provide a forward-only, zero-downtime migration.

**Non-Goals:**

- Validate that base UUIDs resolve. OpenRegister does not own the vocabulary; cross-app validation is the consumer app's responsibility (see D2).
- Spec the broader entity-recognition / anonymisation pipeline. `EntityRecognitionHandler`, `FileService::anonymizeDocument`, `FileTextController::anonymizeFile` are implemented but currently uncovered by an OpenSpec capability. Retrofitting a full capability spec for that surface is out of scope.
- Provide a prohibition gate. Validating that the right entities were selected for anonymisation is a consumer-app concern (DocuDesk implements this in the paired change `anonymisation-grondslagen-and-prohibition-gate`).
- Provide a full REST surface for entity-relations (`GET`, list/show, `DELETE`). PATCH is the minimum needed; further verbs follow under a separate change if a use case appears.
- A batch PATCH endpoint. Single-item only for v1.
- Letting operators edit `anonymized` or `anonymizedValue` directly. The PATCH whitelist is decision-only; post-hoc system fields stay system-controlled.
- Retroactively un-redact when `skip_anonymization` is flipped to `true` on an already-anonymised row. The flag is forward-looking.

## Decisions

### D1. `bases` is a JSON column, not a join table

The column is a JSON array of strings (UUIDs). No separate `entity_relation_base` join table.

**Rationale:**

- **Nextcloud convention.** Nextcloud's storage layer treats moderate-cardinality multi-valued attributes as JSON columns by default; join tables are reserved for relationships requiring foreign-key integrity, indexed reverse-lookup, or cross-record constraints. None of those apply here — bases are a per-row tag list.
- **Parity with `dossier.bases[]`.** DocuDesk's `dossier` schema (in-flight) uses an `array of $ref` to `base` objects. Mirroring that shape on the EntityRelation row keeps the mental model uniform across the system.
- **Zero foreign-key coupling.** OpenRegister does not own the `base` vocabulary; storing UUIDs as JSON strings sidesteps the question of whether to enforce FK constraints across registers.

**Alternative considered:** A `entity_relation_bases` join table with `(entity_relation_id, base_uuid)`. Rejected: introduces a second migration, two write points to keep in sync, and reverse-lookup queries that aren't part of any current use case. Can be added later if compliance reporting demands it.

### D2. No validation that base UUIDs resolve

The mapper accepts any string array. Whether the UUIDs point to actual `base` objects in DocuDesk's register (or any register) is the consumer app's problem.

**Rationale:**

- The `base` vocabulary lives in DocuDesk's `dossier` register. OpenRegister doesn't own it and shouldn't reach into another app's data to validate.
- The consumer app already validates the picker output before sending. Double-validation at the OpenRegister layer adds latency without catching new cases.
- Cross-register referential integrity is a known harder problem in OpenRegister; deferring it here is consistent with how other cross-app references work.

**Mitigation for the dangling-reference risk:** consumers should handle unresolvable UUIDs gracefully (render as "onbekende grondslag" or filter from summaries). DocuDesk-side documents the contract.

### D3. Scope-tight PATCH endpoint + parallel DI mapper method, both routed through a single audited write path

This is the architectural pivot from the original design. Decoupling bases-set from anonymise gives both verbs cleaner semantics and avoids the spec ↔ implementation mismatch the explore-mode investigation surfaced.

**Surface:**

```
HTTP:  PATCH /api/entity-relations/{id}
       body: { bases?, skipAnonymization? }
              any other field → 400 with "field not editable: <name>"
       response 200: the updated EntityRelation row (jsonSerialize)
       response 400: shape / whitelist violation
       response 403: insufficient authorization (see D7)
       response 404: relation id does not resolve

DI:    EntityRelationMapper::updateDecisionMetadata(
         int $id,
         array $fields,
         ?IUser $actingUser = null
       ): EntityRelation
       — same whitelist, same shape validation, same audit-trail
```

**The HTTP controller is a thin wrapper.** It resolves the acting `IUser` from the session, calls `updateDecisionMetadata`, maps thrown exceptions to HTTP responses (400 / 403 / 404). No duplicated whitelist logic. No duplicated audit logic.

**Whitelist — decision fields only:**

| Field | Editable via PATCH | Why |
|---|---|---|
| `bases` | yes | Operator decision: under what legal basis are we redacting? |
| `skipAnonymization` | yes | Operator decision: should we redact this occurrence at all? |
| `anonymized` | **no** | Post-hoc state. Records whether the anonymise pass actually redacted the row. Written only by `markAsAnonymized` (or future system-level redaction code paths) — never by an operator. Letting operators flip this would manufacture false audit history (claim a redaction without one having happened). |
| `anonymizedValue` | **no** | Same — the placeholder string is set by the redaction code path, not the operator. |
| any structural field (`entityId`, `chunkId`, `fileId`, `objectId`, `emailId`, `positionStart`, `positionEnd`, `confidence`, `detectionMethod`, `context`, `registerId`, `schemaId`, `objectUuid`, `createdAt`) | **no** | Detection output / row identity; mutating these post-detection would break audit traceability and referential intent. |

**Rationale for excluding `anonymized` / `anonymizedValue`:** the PATCH endpoint is *decision-time*. `markAsAnonymized` is *anonymise-time*. Keeping the surfaces distinct preserves the audit-trail invariant "`anonymized=true` ⟹ the redaction code path ran for this row". A general-purpose PATCH that could flip `anonymized` without running the redaction would silently break that invariant.

**Granularity:** the whitelist applies per-relation (per-position). Each `EntityRelation` row is one detected occurrence at one offset, and the PATCH operates on a single row's `id`. Operators *can* express different decisions for different occurrences of the same entity in the same file — for example, set `skipAnonymization=true` at position 100 while leaving position 250 unflagged. Whether any consumer UI surfaces that capability is a separate question; the OR contract preserves the granularity for any consumer that wants it.

### D4. Empty / null `bases` is allowed

A row with `bases: null` or `bases: []` represents "no grondslag attached to this relation". This stays valid because:

- Existing rows (pre-migration) have `bases: null`. We don't backfill.
- Some consumer flows may not record grondslagen yet (or ever — generic file-sanitisation flows have no grondslag concept).

The column is nullable; the JSON array, when present, has no `minItems` constraint.

### D5. Migration is forward-only and idempotent

The migration class adds two columns to `openregister_entity_relations`:

- `bases` — JSON, `notnull => false`, `default => null`.
- `skip_anonymization` — BOOLEAN, `notnull => true`, `default => false`.

No data migration; existing rows pick up the column defaults. Re-running the migration is a no-op (the column-add primitive is idempotent in Nextcloud's schema migration framework; the migration body uses `hasColumn` guards).

**Rollback:** drop both columns. Existing PATCH calls that wrote either field post-rollback would HTTP-500 from the missing columns; this is acceptable for an emergency-only path.

### D6. Audit-trail entries are written inside `updateDecisionMetadata` (single audited path)

OpenRegister has an existing audit-trail subsystem (`AuditTrail` / `AuditTrailMapper`). The new mapper method emits one audit entry per successful write summarising changed fields, per ADR-022.

**Audit-entry payload:**

```
{
  "action": "entity_relation_decision_updated",
  "subjectType": "openregister_entity_relations",
  "subjectId": <relation id>,
  "actor": <user UID — per ADR-005, never the display name>,
  "timestamp": <ISO-8601>,
  "changedFields": {
    "bases":             { "previous": <null|array>, "new": <null|array> },
    "skipAnonymization": { "previous": <bool>,       "new": <bool>       }
  }
}
```

(`anonymized` and `anonymizedValue` mutations have their own audit-trail entries via `markAsAnonymized`'s existing wiring; the PATCH endpoint does not write either field.)

Only fields that actually changed appear under `changedFields`. If the caller PATCHes a field with the same value it currently holds, that field MUST NOT appear in `changedFields` and MUST NOT trigger a duplicate audit entry — semantic-no-ops are not audit-worthy events. If no fields changed at all (e.g. PATCH with body `{}` or PATCH with values identical to current state), the call succeeds (HTTP 200) and **no audit entry is written**.

Reads MUST NOT produce audit entries — applies to all relation reads in the codebase (mapper find, controller GET, downstream consumers).

### D7. Authorization: write-access to the relation's parent file/object

PATCH requires that the acting user can **write** to the file or object referenced by the relation (`fileId`, `objectId`, etc.). This mirrors the implicit check that `FileTextController::anonymizeFile` inherits today: anonymising a file requires write-access to the file, so flipping `anonymized=true` on the relations under that file requires the same.

**Resolution order:**

1. If the relation has `fileId` set — check the acting user can write the file. (`Folder::isReadable() && ...` — actually a write-check; OR has a helper for this used in the anonymise path.)
2. Else if the relation has `objectId` (+ `registerId` + `schemaId` for disambiguation) — check the user can update the underlying object.
3. Else if the relation has `emailId` — check the email is accessible to the user.
4. If none of the above resolve to a permission grant, deny (HTTP 403).

**No additional action-level permission** is introduced in this change (per ADR-023). The PATCH endpoint inherits the existing file/object write check; there is no separate "may set bases" permission. If a future change wants action-level authz, it MUST add a new Requirement here. This decision is recorded explicitly so future reviewers can see the absence-of-extra-check is intentional, not oversight.

**`@NoAdminRequired`** on the controller method — non-admins can PATCH relations they have write-access to. Admin role MUST NOT be required.

### D8. Standard PATCH semantics for partial updates

PATCH is the natural HTTP verb for partial updates. Body shape maps to behaviour directly:

| Body | Effect on `bases` | Audit entry? |
|---|---|---|
| Field absent | Unchanged | No |
| `"bases": null` | Set to `null` (clear) | Yes (previous → null), only if previous ≠ null |
| `"bases": []` | Set to `[]` (empty array, distinct from null per D4) | Yes, only if previous ≠ [] |
| `"bases": ["uuid-a"]` | Set to `["uuid-a"]` | Yes, only if previous ≠ new |

| Body | Effect on `skipAnonymization` | Audit entry? |
|---|---|---|
| Field absent | Unchanged | No |
| `"skipAnonymization": true` | Set to `true` | Yes, only if previous was `false` |
| `"skipAnonymization": false` | Set to `false` | Yes, only if previous was `true` |

A PATCH that omits all whitelist fields (body `{}`) or whose values all match current state is a successful no-op (HTTP 200) and writes no audit entry. DocuDesk's retry path is trivial: omit any field that hasn't changed.

### D9. Anonymise flow honours `skipAnonymization`

`skipAnonymization` is load-bearing for the anonymise pass: a row flagged `skip=true` MUST NOT be redacted and MUST NOT have `anonymized` flipped to `true`. Both the HTTP path (`FileTextController::anonymizeFile`) and the DI path (`FileService::anonymizeDocument` via `DocumentProcessingHandler`) honour the flag.

**HTTP path** (`POST /api/files/:fileId/anonymize`):

1. Today: `EntityRelationMapper::findEntitiesForFile($fileId)` returns all rows for the file; the controller iterates over them to build the replacements list; `markAsAnonymized($fileId, ...)` flips every row.
2. After this change: the mapper read filters `skip_anonymization=false` (or the controller filters server-side after reading); the replacements list excludes skipped rows; `markAsAnonymized` updates only rows where `skip_anonymization=false`.

The filter SHOULD live in the mapper (`findEntitiesForFile` gains a behaviour flag or a sibling method `findEntitiesForAnonymization($fileId)` that filters). Putting it in the mapper means a single source of truth for "which rows are eligible for redaction".

**DI path** (`FileService::anonymizeDocument(Node, entities[])`):

The caller passes an `entities[]` array, typically built from `findEntitiesForFile`. Two implementation choices:

- **(i) Caller filters.** DD's `AnonymizationService` consults persisted skip state when building the array. The OR-side `DocumentProcessingHandler` trusts the caller's filtering. Less safe — a stale or buggy caller can request redactions on skipped rows.
- **(ii) OR re-filters server-side.** Inside `FileService::anonymizeDocument`, before passing the array to `DocumentProcessingHandler`, look up the persisted skip state for each entity and filter out skipped ones. Defensive but adds a per-entity lookup.

Pick (ii) — defensive filtering inside OR. The cost is one DB lookup per entity; the gain is that operator skip decisions are honoured regardless of which path the caller took. The OR contract is "skipped relations are never redacted, full stop".

**`markAsAnonymized` change:**

```php
// Before:
//   UPDATE oc_openregister_entity_relations
//   SET anonymized = 1, anonymized_value = ?
//   WHERE file_id = ?

// After:
//   UPDATE oc_openregister_entity_relations
//   SET anonymized = 1, anonymized_value = ?
//   WHERE file_id = ? AND skip_anonymization = 0
```

Rows where `skip_anonymization=true` retain `anonymized=false`. The operator decision is preserved and remains visible.

**What if `skipAnonymization` is flipped to `true` AFTER anonymise has already run on the row?** The row already has `anonymized=true`. The flip is a forward-looking decision ("don't redact in future passes") that doesn't retroactively un-redact. The redaction has already happened against the file; the only place the flag matters now is future re-runs. Specs and audit-trail reflect both events in order.

## Risks / Trade-offs

**[R1 — Dangling base UUIDs after consumer-side delete]** → Mitigation per D2: consumer app reads unresolvable UUIDs gracefully; documents the contract. Same shape as the prior design.

**[R2 — Partial decision/execution sequence: PATCH succeeds, anonymise fails later]** → Acceptable. The EntityRelation row reflects "intended" state (bases set, skip flag set, anonymized=false); retry re-issues the anonymise call and the persisted decisions are honoured. Same failure-mode shape as `markAsAnonymized` today.

**[R3 — Consumer app that wraps the call differently]** → If a consumer ever passes a stale payload shape that tries to ride bases on the anonymise call (the old behaviour the original spec described), the anonymise endpoint silently ignores `bases` (it never read the field). Backwards-compatible in the no-op sense. The consumer-side correction is a separate adoption task.

**[R4 — JSON column query performance]** → Negligible. Reverse lookups ("show me all relations under grondslag X") are not a current use case; if they emerge, a generated index on the JSON path or a materialised view is a follow-up.

**[R5 — Whitelist drift]** → The whitelist (`bases`, `skipAnonymization`) is hard-coded in `updateDecisionMetadata`. If a future field needs to be editable, that's a deliberate spec amendment, not an accidental opening. Decision-fields-only is the invariant.

**[R6 — Race between two PATCHes on the same row]** → Last-write-wins per QBMapper's optimistic semantics. Acceptable for the operator-decision use case (an operator setting bases or skip on a row is a deliberate UI action, not high-concurrency). If concurrent operator decisions emerge as a real pattern, we add an `If-Match` / ETag layer in a follow-up.

**[R7 — Audit-trail volume]** → One entry per successful PATCH with changes. For DocuDesk's review flow this is one entry per entity-relation per operator decision — bounded by the number of detected entities per file. Same audit-volume profile as `markAsAnonymized` today. Not a concern at expected volumes.

**[R8 — Skip flag bypassed by a non-Conduction consumer]** → The defensive filtering in `FileService::anonymizeDocument` (D9, option ii) is the OR-side guardrail. A non-Conduction consumer that calls the DI path with an entities array including skipped rows will still see them filtered out server-side. `markAsAnonymized` enforces the filter at the SQL layer. No consumer-trust dependency.

**[R9 — Forward-only skip semantic surprises consumers expecting "un-anonymise"]** → A row that's already `anonymized=true` when `skip_anonymization` is flipped to `true` keeps the redaction in the file. The flag is forward-looking by design. Documentation MUST state this clearly; the UI consumer (DocuDesk) MUST surface it ("this entity has already been anonymised in this file; further redaction passes will skip it").

## Migration Plan

1. **OpenRegister side, phase 1 (this change):** land `EntityRelation` fields + migration + `EntityRelationMapper::updateDecisionMetadata` + `EntityRelationsController` PATCH + route + anonymise-flow filter changes in one PR. Apply migration on dev / staging — both columns appear (bases nullable JSON; skip_anonymization boolean default false). Existing rows have `bases=null` and `skip_anonymization=false`. Smoke-test: PATCH a relation with `bases`, confirm the row is updated and an audit entry is written; PATCH a relation with `skipAnonymization=true`, run the file's anonymise endpoint, confirm the skipped row is not redacted and stays `anonymized=false`.
2. **DocuDesk side, phase 1.5 (separate change, separate PR — `docudesk:anonymisation-grondslagen-and-prohibition-gate` amendment):** rewrite the DD spec to call OR's new PATCH instead of riding `bases` on the anonymise call, and to PATCH `skipAnonymization=true` for prohibition-gate "release-via-override" decisions. DD's flow becomes: detection → operator reviews + picks grondslagen and skip flags → DD PATCHes each relation → DD calls anonymise (no per-entity decoration in the anonymise payload).
3. **DocuDesk side, phase 2:** implement the amended DD spec. Test end-to-end against OR phase 1.
4. **Release.** No flag, no toggle — the new PATCH endpoint is additive; the anonymise endpoint URL is unchanged.

**Rollback (OR):** drop both columns via a reverse migration. PATCH calls that wrote either field after rollback would silently fail (HTTP 500 from the missing columns). In practice rollback is an emergency-only path; the DD-side amendment is rolled back in lock-step (DD reverts to the pre-amendment behaviour).

## Seed Data

Not applicable — this change adds a column to an existing DB table, not new schemas or registers. The `base` vocabulary that bases reference lives in DocuDesk and is seeded there per the `add-dossier-schema` change (already merged on DD's `development`).

## Open Questions

- **Whether to add a generated index on the JSON column for compliance-reporting queries.** **Resolution:** defer until a real query workload appears. JSON column queries on small per-row arrays are inexpensive at expected volumes.
- **Whether the PATCH endpoint should later absorb `markAsAnonymized`'s file-scoped flip path.** **Resolution:** Non-Goal for v1. The two paths coexist; a future refactor can consolidate after the new PATCH is proven in production.