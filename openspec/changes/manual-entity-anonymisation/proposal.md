## Why

OpenRegister's anonymisation pipeline post-`entity-relation-grondslagen` is **relation-driven**: `FileTextController::anonymizeFile` reads non-skipped `EntityRelation` rows for the file via `findEntitiesForAnonymization`, hands them to `DocumentProcessingHandler::anonymizeDocument`, and the per-row substitution is built from `findEntityIdsByValueForFile` so each entity gets a stable `[<TYPE>: <entity_id>]` placeholder. The flow is clean for **detection-derived** entities (Presidio writes the relation rows; the anonymise call picks them up automatically).

DocuDesk operators have raised a recurring need that this architecture doesn't currently support: **manually adding a piece of text as an anonymisable entity**. The legacy mental model — "pass the extra text as another entity to the anonymise call" — no longer applies because the HTTP path no longer accepts an entities array (it sources entities entirely from the relations table). The DocumentProcessingHandler DI method DOES accept entities but a manually-added text without a backing entity catalogue row falls into the UUID-fragment fallback, which:

- produces a different placeholder per call (idempotency-violation),
- doesn't appear in `findEntitiesForAnonymization`'s pre-anonymise listing,
- doesn't show up in the grondslagen-summary report,
- can't carry per-entity decision metadata (bases, skip).

In short: the only correct way to redact a manually-supplied text is to **persist it the same way Presidio does** — as a catalogue entry plus position-bound relation rows — and let the existing pipeline run unchanged.

DocuDesk doesn't have HTTP endpoints to do that. `GdprEntitiesController` is read-only (no POST). `EntityRelationsController` exposes only PATCH for decision metadata (no POST / GET / DELETE). The mappers themselves can `insert()` via QBMapper inheritance, but no HTTP surface exposes it.

This change adds the missing write path with an operator-friendly shape: **one POST per "operator decides to anonymise this text in this file"**, server-side string matching against the file's already-extracted chunks, atomic creation of the catalogue entry (or reuse of an existing one) plus one relation row per occurrence found, with full audit-trail capture.

## What Changes

- **NEW endpoint:** `POST /api/files/{fileId}/manual-entities` — operator-typed text + entity type in, catalogue entry + zero-or-more relation rows out, atomic in a single DB transaction.
- **NEW behaviour:** server-side **chunk-aware exact-string matching** against the file's persisted `TextExtractionService` chunks. Matches across chunk boundaries via concatenation + boundary mapping (per-chunk `strpos` would miss text split across chunks). Default match semantics:
  - **Whole-word** (`\bvalue\b` regex boundary) — `"Jan"` does NOT match inside `"Janitor"` / `"January"`. Toggleable via `wholeWord: bool = true`.
  - **Case-sensitive** — mirrors Presidio's detection behaviour. Toggleable via `caseSensitive: bool = true`.
- **NEW behaviour:** **lookup-or-create** semantics on the catalogue. A `(value, type)` pair already present in `openregister_entities` is REUSED — the same row's `id` is referenced by the new relations, which keeps cross-document placeholder IDs stable. New entries are created only when no match exists.
- **NEW behaviour:** **zero-match responses are non-errors.** When the supplied text is not found in the file (typo, wrong file, already-anonymised content), the endpoint returns HTTP 200, creates the catalogue entry (or reuses it), creates no relations, and includes a `message` field in the body. The operator sees explicit feedback rather than an opaque 4xx.
- **NEW behaviour:** **idempotent relation creation.** If a relation already exists for `(fileId, entityId, chunkId, positionStart, positionEnd)` it's NOT inserted again — the `matchesSkipped` counter reports how many positions were already covered by prior runs. This makes the endpoint safe to retry and safe to call multiple times for the same text on the same file.
- **NEW:** `EntityRelation.detectionMethod` gains `'manual'` as a recognised value. Confirmed: no existing code paths reject unknown values (they treat it as a free-form string label), but adding to a constants class makes the enum explicit. Existing rows are unaffected.
- **NEW audit-trail actions:**
  - `entity_create` — on catalogue inserts (skipped when an existing row is reused).
  - `entity_relations_batch_create` — single row per call, capturing `{ value, type, fileId, matchCount, matchesSkipped, detectionMethod: 'manual' }`. Per-position drill-down recoverable from the relations themselves; the audit row is the per-operation record.
- **NEW RBAC:** the endpoint requires write access to the file referenced by `fileId`. Same check that `markAsAnonymized` and the existing `PATCH /api/entity-relations/{id}` already inherit. The catalogue is tenant-shared, but the entry-point is file-scoped, so file-write access is the natural gate.
- **NO** changes to the existing anonymise flow. After this change, an operator-added entity is structurally indistinguishable from a Presidio-detected entity (same row shape, same placeholder generation, same skip-honouring) — the existing pipeline picks it up unchanged.
- **NO** changes to the existing `GdprEntitiesController` or `EntityRelationsController` endpoints. The new endpoint lives on `FileTextController` (or a sibling controller) because it's file-scoped; the existing controllers stay read-only for entities and decision-metadata-only for relations.
- **NO new capability for entities catalogue writes** beyond this scope. Other potential use cases (admin-bulk-add, importing a known PII vocabulary, automated catalogue seeding) are deliberately out of scope.

### Endpoint shape

```
POST /api/files/{fileId}/manual-entities
Content-Type: application/json

{
    "value":         "Jan Jansen",
    "type":          "PERSON",
    "wholeWord":     true,            // optional, default true
    "caseSensitive": true             // optional, default true
}

// `category` is intentionally not accepted in v1 — it is derived
// server-side from `type` via the same mapping the detector flow uses,
// so manual-entity rows stay consistent with detector-produced rows.
// Operator-override on `category` is a follow-up if a concrete use
// case emerges.

→ 201 Created  (when ≥1 match found)
{
    "entity": {
        "id":     42,
        "uuid":   "...",
        "value":  "Jan Jansen",
        "type":   "PERSON",
        "reused": false               // true if dedup hit existing catalogue row
    },
    "relations": [
        {
            "id":            101,
            "uuid":          "...",
            "chunkId":       1,
            "positionStart": 142,
            "positionEnd":   152,
            "context":       "...Aanvraag van Jan Jansen voor het loket..."
        },
        {
            "id":            102,
            "uuid":          "...",
            "chunkId":       3,
            "positionStart": 26,
            "positionEnd":   36,
            "context":       "...met groet, Jan Jansen, secretaris"
        }
    ],
    "matchCount":     2,
    "matchesSkipped": 0
}

→ 200 OK  (when zero matches)
{
    "entity":         { "id": 42, ..., "reused": true },
    "relations":      [],
    "matchCount":     0,
    "matchesSkipped": 0,
    "message":        "Text not found in file. Catalogue entry created (or reused) and is available for use on other files."
}

→ 400 Bad Request  — missing required field, regex compile failure, or value too long
→ 403 Forbidden    — caller lacks write access to fileId
→ 404 Not Found    — fileId doesn't exist or its chunks aren't extracted yet
→ 422 Unprocessable — file extraction not yet run (chunks missing); operator must extract first
→ 415 Unsupported   — Content-Type isn't application/json (matches existing controller convention)
```

### What the relation rows are NOT

A subtle property worth surfacing in the operator UI but kept entirely server-side here: `EntityRelation.positionStart` / `positionEnd` / `chunkId` are NOT consulted by `DocumentProcessingHandler::anonymizeDocument` at substitution time. The current substitution pass is **value-keyed** (`strtr` against the file's content using `value → placeholder` map). Position metadata is recorded for forensic / report purposes but doesn't constrain redaction — every occurrence of the operator-supplied value is redacted, regardless of whether each individual position has a corresponding relation row.

This means: an operator who types `"Jan Jansen"` and gets matchCount = 2 will see ALL occurrences of `"Jan Jansen"` redacted, not just at positions 142 and the other match. The position rows are forensic markers, not redaction filters.

This is consistent with how Presidio-detected entities behave today. It's documented here so the design is explicit; it's flagged as future work (see "Out of scope" below).

### Out of scope

- **Position-aware string substitution.** Today the substitution pass is value-keyed; positions are recorded but not honoured. An operator who adds "Jan Jansen" at position 142 (intending to redact only that occurrence) gets all occurrences redacted. Aligning the substitution to honour per-position decisions (so per-occurrence skip decisions become meaningful, and per-relation grondslagen pre-occurrence become possible) is a separate change (`position-aware-substitution`). Trigger: a real operator workflow needs "redact this occurrence only". Until then, value-keyed is documented behaviour.
- **Presidio overlap-dedup.** `EntityRecognitionHandler::extractFromChunk` runs per chunk and stores relations without deduplicating matches that land in chunk-overlap regions. The same entity in an overlap can produce two `EntityRelation` rows (different `chunkId` + `positionStart`, same absolute position). The manual-entity flow introduced here applies absolute-position dedup so a match in an overlap region produces ONE relation. This creates an inconsistency between detection-derived and operator-added entities — manual is smarter than Presidio. Bringing Presidio in line (same absolute-position dedup applied in `storeDetectedEntities`) is a separate change `presidio-overlap-dedup`. Trigger: grondslagen-summary report shows duplicate rows for entities in overlap regions on long documents, or independent detection-quality complaint. Not blocking on this change.
- **DELETE endpoint for manual entries.** An operator who adds the wrong text and wants to remove it would today need to skip the relations (PATCH `skipAnonymization=true`). A real delete endpoint (DELETE the catalogue entry, or DELETE individual relations) is a small follow-up. Out of scope here.
- **GET / index endpoints on entity-relations.** Reading relations works today via `findEntitiesForAnonymization` (used internally) and the joined view served by DocuDesk's reports. A public REST GET on `/api/entity-relations` is potentially useful but isn't required for the manual-entity flow.
- **Bulk-import vocabularies.** Adding 1000 PII strings at once (e.g. from an org-wide watchlist) is conceivable but is a different shape — it's not file-scoped. Out of scope here.
- **Fuzzy / approximate matching.** Operator typos won't match. Operator must type the exact text. This matches Presidio's exact-position semantics. Adding a "did you mean?" or Levenshtein-distance pass is out of scope.
- **OCR-style detection re-run.** This endpoint does NOT trigger a fresh Presidio pass on the file; it only adds the operator-supplied value. Re-running detection is done via the existing `extractFile` / re-detect path (separate concern).
- **DocuDesk-side UI for the operator flow.** "Add manual entity" form, text-input, type-picker, optional preview of matches — all DocuDesk-side. This OR change provides the API; DocuDesk consumes it in a separate change.

## Capabilities

### Modified Capabilities

- `entity-relation-grondslagen`: extended with a manual-entity write path. The capability already covers the read side (per-row decision metadata, skip-honouring, stable placeholders); this change adds the write path so operator-supplied entities can flow through the same pipeline.

### New Capabilities

(none — this extends an existing capability rather than introducing a new one. The new endpoint and behaviours are spec'd as ADDED Requirements to the existing `entity-relation-grondslagen` capability spec.)

## Impact

- **Code (openregister):**
  - `lib/Controller/FileTextController.php` — NEW endpoint method `addManualEntity(int $fileId): JSONResponse`. Reads JSON body, validates input, dispatches to a new service method, formats the response.
  - `lib/Service/File/ManualEntityService.php` — NEW. Orchestrates: lookup-or-create catalogue entry, chunk-aware string matching, batch relation creation, transaction control, audit. Single point of truth for the file-scoped operator-add operation.
  - `lib/Service/File/ChunkTextMatcher.php` — NEW. Pure utility: takes a list of chunks + a needle + match options, returns `[{chunkId, positionStart, positionEnd, context}, ...]`. Tested in isolation against fixture chunks covering cross-boundary, whole-word, case-sensitivity, overlapping-substring edge cases.
  - `lib/Db/GdprEntityMapper.php` — extend with `findOneByValueAndType(string $value, string $type): ?GdprEntity` lookup helper.
  - `lib/Db/EntityRelationMapper.php` — extend with `insertBatch(array $rows): array` for atomic multi-row insert, plus `existsForFileAtPosition(int $fileId, int $entityId, int $chunkId, int $positionStart, int $positionEnd): bool` for idempotency check.
  - `lib/Service/File/DocumentProcessingHandler.php` — no changes (existing pipeline already picks up the new relations).
  - `appinfo/routes.php` — add the new route entry.
  - `openspec/specs/entity-relation-grondslagen/spec.md` — add to in-progress list (this change extends it).
- **API contract:** Additive only — one new endpoint. No existing endpoint changes shape.
- **Cross-app:**
  - DocuDesk's anonymisation review UI gains an "Add manual entity" action (separate DocuDesk change consuming this API).
  - opencatalogi / softwarecatalog: unaffected.
- **Performance:** Chunk concatenation + `mb_strpos` is O(N) in total file text size per call. Typical Woo files (<1MB extracted text, <100 chunks) complete in well under 100ms server-side. Large files (multi-MB text, hundreds of chunks) bounded by the existing TextExtractionService's chunk-read cost.
- **Privacy / compliance:** The operator-supplied value is PII by definition. Per ADR-005 / ADR-022:
  - Audit-trail row records `value` (forensic exception — audit is the explicit allowed location).
  - HTTP request/response logs (NC's standard log path) MUST NOT include the value. The new code includes a permitted-log-payload note and a small redactor on the request log line.
  - Error responses MUST NOT echo the value (it's already in the request, the operator already knows it).
- **Tests:** Unit tests for `ChunkTextMatcher` covering cross-boundary, whole-word, case-sensitivity, overlapping-substring, zero-match, no-chunks. Service tests for `ManualEntityService` with mock mappers covering happy path, idempotent re-call, zero match, RBAC denial. Controller tests for the endpoint covering 201 happy path, 200 zero-match, 400 invalid body, 403 RBAC, 404 missing file, 422 unextracted file, 415 wrong content-type. Integration test against a fully-stacked OR instance: upload a file → extract → POST manual entity → assert relations created → invoke anonymise → assert output contains placeholder.
- **Migration:** No DB migration. `detectionMethod` is already a string column; adding `'manual'` as a recognised value is a code-only enum extension.
