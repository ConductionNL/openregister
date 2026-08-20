# Manual Entity Anonymisation

OpenRegister exposes `POST /api/files/{fileId}/manual-entities` for the operator-supplied "add this exact text to the anonymisation list for this file" flow. The endpoint performs **chunk-aware exact-string matching** against the file's previously extracted text, creates (or reuses) a catalogue entry for the value, and creates one `EntityRelation` row per occurrence found with `detectionMethod = manual`.

The endpoint complements presidio/openanonymiser/pattern detection by giving operators a clean way to catch values the detectors missed without polluting the model with edge cases. It feeds into the same anonymise flow as auto-detected entities — `POST /api/files/{fileId}/anonymize` picks up the new relations on its next run.

## Endpoint contract

```
POST /api/files/{fileId}/manual-entities
Content-Type: application/json
Body: {
    "value":         string,             // operator-supplied text (REQUIRED, PII)
    "type":          string,             // entity type tag, e.g. "PERSON" (REQUIRED)
    "wholeWord":     boolean (default true),
    "caseSensitive": boolean (default true)
}
```

> **Note on `category`:** the `oc_openregister_entities.category` column is `NOT NULL` and is populated server-side from `type` via the same `EntityRecognitionHandler::getCategoryForType()` mapping the detector flow uses (PERSON / EMAIL / PHONE / ADDRESS → `personal_data`; IBAN / SSN → `sensitive_pii`; ORGANIZATION → `business_data`; LOCATION → `contextual_data`; DATE → `temporal_data`; everything else → `contextual_data`). The endpoint intentionally does NOT accept a `category` field in v1 — operator-override on category is a follow-up if a concrete use case emerges.

**Response shape (201 / 200):**

```json
{
    "entity": {
        "id":     7,
        "uuid":   "01HMX...",
        "value":  "Jan Jansen",
        "type":   "PERSON",
        "reused": false
    },
    "relations": [
        {
            "id":            200,
            "chunkId":       100,
            "positionStart": 13,
            "positionEnd":   23,
            "context":       "... Jan Jansen woont in ..."
        }
    ],
    "matchCount":     1,
    "matchesSkipped": 0
}
```

**Status codes:**

- **201** — one or more matches found; the catalogue entry (new or reused) plus the inserted relations are in the body.
- **200** — zero matches found in the file. The catalogue entry is still created/reused and is available for use on other files. Body adds a `message` field: `"Text not found in file. Catalogue entry created (or reused) and is available for use on other files."`
- **400** — `{ "error": "invalid_request", "field": "value"|"type" }` for missing required body fields, or `{ "error": "regex_compile_failure" }` for malformed Unicode in the needle or a value longer than the chunk overlap (200 chars).
- **401** — no authenticated session: `{ "error": "unauthenticated" }`.
- **403** — acting user lacks write-access to the file: `{ "error": "forbidden", "reason": "write access to file required" }`. Same RBAC check as `PATCH /api/entity-relations/{id}` — the file MUST be reachable in the user-folder and `isUpdateable()` MUST return true.
- **415** — non-JSON Content-Type: `{ "error": "unsupported_media_type", "reason": "..." }`.
- **422** — `{ "error": "file_not_extracted" }` — the file has no extracted chunks. Operator must trigger text extraction first.
- **500** — unexpected failure: `{ "error": "internal_error" }`. Body never echoes the operator-supplied value (ADR-005).

The endpoint is `@NoAdminRequired` — non-admins can add manual entities to files they can write.

## Semantics

- **Atomic per call.** The catalogue write, the relation inserts, and the audit-trail entries all happen inside one `IDBConnection::beginTransaction()` / `commit()` / `rollBack()` window. Either everything in the call lands or nothing does.
- **Idempotent on retry.** Each match position is probed via `EntityRelationMapper::existsForFileAtPosition($fileId, $entityId, $chunkId, $positionStart, $positionEnd)` before insert. Already-present rows bump `matchesSkipped` and don't insert. Re-running the call for the same value on the same file is a no-op DB-wise (the audit-trail entry is still written so operator intent is recorded).
- **Catalogue lookup-or-create.** `GdprEntityMapper::findOneByValueAndType(value, type)` resolves an existing row; otherwise a fresh `GdprEntity` is inserted. `entity.reused = (existing !== null)` in the response signals which path was taken. **Match flags do not key the catalogue lookup** — the catalogue row is the canonical truth for that (value, type) and is shared across all files that reference it.
- **Chunk-aware matching.** OR's text extractor splits long files into 1000-character chunks with 200-character overlap. The matcher runs `preg_match_all` per chunk, computes absolute positions as `chunk.startOffset + chunkRelativeOffset`, and dedups by absolute (start, end) across chunks. When the same match appears in two chunks' overlap region the entry from the **lower `chunkIndex`** wins, so re-runs select the same canonical chunk (idempotency precondition).
- **Match flag defaults.** Both `wholeWord` and `caseSensitive` default to **true**. The defaults reflect "operator means exactly what they typed"; loosen them only when the use case demands it.
- **Value-too-long.** Values longer than the chunk overlap (200 chars by default) are rejected with `regex_compile_failure` because they cannot reliably be matched per-chunk. The error message never contains the value (ADR-005).

## Audit trail

Two action types are written by every successful call:

1. **`entity_create`** — written ONLY when a new catalogue row was inserted:
   ```
   action       = "entity_create"
   user         = acting user UID  (NEVER the display name — ADR-005)
   created      = now (UTC)
   changed.subjectType = "openregister_entities"
   changed.subjectId   = <gdpr_entity id>
   changed.fields = { "value": <value>, "type": <type>, "category": <derived> }
   ```
   ADR-022 forensic exception: `value` IS allowed in the audit payload (and only here — never in HTTP logs or error responses).

2. **`entity_relations_batch_create`** — written on EVERY call, even on zero-match calls:
   ```
   action       = "entity_relations_batch_create"
   user         = acting user UID
   created      = now (UTC)
   changed.subjectType = "openregister_files"
   changed.subjectId   = <fileId>
   changed.fields = {
       "value":           <value>,
       "type":            <type>,
       "fileId":          <fileId>,
       "detectionMethod": "manual",
       "matchCount":      <matchCount>,
       "matchesSkipped":  <matchesSkipped>,
       "relationIds":     [<id>, <id>, ...]   // only the inserted ones
   }
   ```
   Zero-match calls are still audited so operator intent is recorded even when the value isn't found in the file (e.g. a typo).

## Anonymise-flow interaction

Manual-method relations carry `detectionMethod = "manual"` but are otherwise identical to detector-produced rows. The downstream pass picks them up unchanged:

- **`POST /api/files/{fileId}/anonymize`** — `EntityRelationMapper::findEntitiesForAnonymization` does NOT filter on `detection_method`; all relations on the file (minus `skip_anonymization = true` ones) are included. Manual-method rows are anonymised on the next run.
- **Caveat: value-keyed substitution.** OR's `DocumentProcessingHandler::anonymizeDocument` collapses multiple distinct catalogue entries with the same `value` into one substitution token. If an operator manually adds a value that already exists in the catalogue (under a different type), the auto-detected and manually-added occurrences will all map to the same placeholder. This is a feature, not a bug — operators don't see two placeholders for "Jan Jansen" just because one occurrence was auto-detected and another was operator-flagged.

## PII redaction (ADR-005)

- **Request log** — controller logs `valueLength` only, never `value`. Permitted log payload: `fileId`, `type`, `wholeWord`, `caseSensitive`, `valueLength`, `actor` (UID — UID is not PII per ADR-005).
- **Error responses** — never include the operator-supplied `value`. Reason codes are stable strings; messages are operator-readable but PII-clean.
- **Logger warnings on the catalogue dedup-invariant violation** — `GdprEntityMapper::findOneByValueAndType` logs the type + the colliding ids if two rows match the same (value, type), but NOT the value itself (the value can be re-derived from the row in the catalogue audit log if a forensic step is needed).
- **Audit trail (`entity_create`, `entity_relations_batch_create`)** — explicit forensic exception per ADR-022. `value` IS persisted here.

## RBAC

Same model as the rest of OR's file-bound write endpoints:

1. The file MUST resolve through the actor's `IRootFolder::getUserFolder($uid)->getById($fileId)` lookup (i.e. the file must be visible to the actor).
2. The resolved node MUST be a file (not a folder) and `isUpdateable()` MUST return true.

Either check failing produces a 403 with `{ "error": "forbidden", "reason": "write access to file required" }`. There is no oracle between "file does not exist" and "file is not writable" — both produce 403/422 depending on the path (file-not-extracted → 422, write-denied → 403). This is the spec's no-oracle rule.

## Spec references

- Capability: [`openspec/changes/manual-entity-anonymisation/specs/entity-relation-grondslagen/spec.md`](../../openspec/changes/manual-entity-anonymisation/specs/entity-relation-grondslagen/spec.md)
- Design (matcher algorithm, audit, RBAC, idempotency invariants): [`openspec/changes/manual-entity-anonymisation/design.md`](../../openspec/changes/manual-entity-anonymisation/design.md)
- Tracking issue: [`#1593`](https://github.com/ConductionNL/openregister/issues/1593)
- ADR-005 (no PII in logs / error responses; UID not display name in audit payloads)
- ADR-022 (audit-trail for OR-owned mutations; forensic exception for `value` in audit payload only)
- Related: [Entity-Relation Decision Metadata](./entity-relation-decision-metadata.md) — operator decisions (`bases`, `skipAnonymization`) on individual relations.
