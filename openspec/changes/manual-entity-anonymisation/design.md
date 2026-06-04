## Context

The anonymisation pipeline after the `entity-relation-grondslagen` change reads entities from `oc_openregister_entity_relations` (filtered for `skip_anonymization = false`), joins against `oc_openregister_entities` for the value + type, and feeds them into `DocumentProcessingHandler::anonymizeDocument`. The substitution map is built using `findEntityIdsByValueForFile`, which gives every entity a stable `[<TYPE>: <entity_id>]` placeholder consistent across redactions of the same file and consistent with the placeholder appearing in DocuDesk's grondslagen-summary report.

Operator-supplied text doesn't fit this flow today. There's no HTTP path to:

1. Create a row in `oc_openregister_entities` (the catalogue) with operator-typed value + type.
2. Create rows in `oc_openregister_entity_relations` linking the new entity to the file at specific positions.

Both mappers can insert via QBMapper's inherited `insert()`, but `GdprEntitiesController` is read-only and `EntityRelationsController` exposes only `PATCH` for decision metadata.

Two distinct concerns shape the design:

**Position discovery.** DocuDesk doesn't reliably hold the full extracted text — its anonymisation review UI shows entities with short context snippets, not the raw chunked text. Asking DocuDesk to compute positions client-side would force a new chunk-fetch endpoint plus JS string matching, with OR remaining the source of truth anyway. Server-side detection is the natural fit: the operator submits the text; OR matches it against its own chunks; OR creates the rows.

**Substitution semantics.** The current substitution pass in `DocumentProcessingHandler::anonymizeDocument` is value-keyed (`strtr` against the file content using a value → placeholder map). Position metadata on `EntityRelation` is recorded but not consulted at substitution time. Manual entries that record positions inherit this property: all occurrences of the operator-supplied value are redacted, not just at the recorded positions. This is consistent with Presidio's behaviour today; a future `position-aware-substitution` change would shift the substitution pass to honour positions if a real workflow demands per-occurrence decisions.

## Goals / Non-Goals

**Goals:**

- A single HTTP endpoint that takes an operator-typed text + entity type and produces a fully-persisted catalogue entry plus zero-or-more position-marked relation rows for the target file.
- Server-side string matching, chunk-aware, with sensible defaults (whole-word, case-sensitive) and toggleable when the defaults are wrong for a use case.
- Lookup-or-create catalogue semantics so a value/type pair appearing on multiple files reuses the same catalogue row (cross-document placeholder stability).
- Idempotent relation creation: re-calling for the same value on the same file does not produce duplicate relations.
- Forensic audit-trail capture per operation; PII permitted in audit (ADR-022 forensic exception) but never in HTTP logs / error responses (ADR-005).
- RBAC anchored to file-write access: the catalogue is tenant-shared but the endpoint is file-scoped, so file write access is the natural gate.

**Non-Goals:**

- Position-aware substitution. Out of scope per proposal; future work.
- DELETE for manual entries. The existing PATCH `skipAnonymization: true` covers the immediate "undo" need; a true delete is a follow-up.
- GET / index endpoints on entity-relations. Read access works via the existing internal helpers and DocuDesk's reports.
- Fuzzy or approximate matching. Operator types exact strings.
- Bulk-import vocabularies. Not file-scoped; different shape.
- Re-running Presidio detection. The endpoint adds the supplied value; it does NOT trigger re-detection.
- DocuDesk-side UI for the flow.

## Decisions

### D1. Endpoint shape: file-scoped, combined entity + relations create

`POST /api/files/{fileId}/manual-entities`

The endpoint creates BOTH the catalogue entry (or reuses an existing one) and the relation rows in a single atomic operation. The alternative — separate `POST /api/gdpr-entities` + N × `POST /api/entity-relations` — would require:

- N+1 round-trips,
- Caller-side error handling on partial failures (entity created but relations failed; relations created but some failed),
- Server-side cross-call transaction boundaries (which isn't naturally available in HTTP).

The combined endpoint encapsulates the full operation in one DB transaction. The operator's mental model is "add this text as anonymisable in this file" — the endpoint shape mirrors that, not the underlying table topology.

**Alternative considered:** generic `POST /api/gdpr-entities` + `POST /api/entity-relations` resource creates. Rejected — see above. Generic endpoints can still be added later if a different consumer needs them (e.g. an admin import tool), but they're not required for the operator-add flow.

### D2. Chunk-aware string matching: per-chunk regex with absolute-position dedup

The file's extracted text lives in **overlapping** chunks. `TextExtractionService` defaults to `chunk_size = 1000` chars with `chunk_overlap = 200` chars; the chunker tries to break at the last space in the latter 20% of each chunk so adjacent chunks share ~150-200 chars of context aligned to word boundaries. Each `Chunk` row carries:

- `textContent` — the chunk's text
- `startOffset` / `endOffset` — absolute positions in the ORIGINAL extracted text
- `chunkIndex` — ordering field

**Naive concatenation would double-count.** Joining `chunk1.text + chunk2.text + ...` produces a string where the ~200-char overlap regions appear twice. `preg_match_all` on the joined string finds matches in each copy, producing two matches for any text in the overlap. Wrong.

**The correct algorithm** is per-chunk regex matching + dedup by absolute position:

```
1. Fetch all chunks for fileId, ordered by chunkIndex.
2. If strlen(needle) > chunkOverlap (default 200 chars):
     Return HTTP 400 — value too long for chunked matching.
     The overlap guarantees any shorter needle is wholly contained
     in at least one chunk even if it crosses a boundary, so the
     limit is generous (200 chars is well above any realistic
     entity value).
3. Build the regex pattern from needle:
     - preg_quote(needle, '/')
     - if wholeWord (default true):    wrap in \b...\b
     - flags: 'u' always (Unicode word boundaries);
              'i' when caseSensitive == false
4. For each chunk:
     - preg_match_all against chunk.textContent
     - For each match at chunkRelativeStart:
         absoluteStart = chunk.startOffset + chunkRelativeStart
         absoluteEnd   = absoluteStart + needleByteLength
         Collect { chunkId, chunkRelativeStart, chunkRelativeEnd,
                   absoluteStart, absoluteEnd, chunkIndex }
5. Dedup by (absoluteStart, absoluteEnd):
     - Group matches with identical absolute positions.
     - For each group, keep the entry from the LOWEST chunkIndex
       (the "canonical chunk" for that absolute position).
     - This is deterministic: re-runs pick the same canonical chunk.
6. Sort the deduped list by absoluteStart.
7. For each match, extract context (~30 chars before + 30 after) from
   the canonical chunk's textContent using chunkRelativeStart.
8. Return list of { chunkId, chunkRelativeStart, chunkRelativeEnd,
                   context }.
```

**Why per-chunk + dedup rather than concatenation:**

- Overlap-correct. The 200-char overlap regions naturally produce two per-chunk matches for any text in them; dedup by absolute position collapses to one.
- Determinism on re-runs. The "lowest chunkIndex" tiebreaker means the same canonical chunk is selected each call → idempotency check (`existsForFileAtPosition` keyed on chunkId+positionStart+positionEnd) works without needing absolute-position arithmetic on the query side.
- Memory bounded. Per-chunk regex never holds more than `chunkSize + needleLen` chars at once; concatenation would hold all chunks' text for the file.
- Matches the existing `EntityRelation` schema. `positionStart`/`positionEnd` on relations are chunk-relative today (Presidio detection populates them from per-chunk matches); the manual-entity flow stays on the same convention.

**Why the needleLen ≤ chunkOverlap constraint is acceptable:**

For needles longer than the overlap, a match could span a chunk boundary in a way that fits in neither chunk's text. E.g. with overlap=200 and a 300-char needle, a match straddling chunks 1 and 2 occupies 100 chars of chunk 1's tail + 200 chars of overlap + first chars of post-overlap chunk 2 content — but since chunk 2 contains the full overlap region as a prefix, the match's first 200 chars are in chunk 2 PLUS 100 chars that are NOT in either chunk's tail. Per-chunk regex misses it.

In practice:
- Entity values are short (PERSON names, org names, BSNs, emails). 200 chars is generous.
- Operators don't paste 200-char strings as entity values.
- The 400 response gives a clear error if it happens.

If this constraint proves restrictive in real workflows, a fallback strategy is possible: for needles > overlap, fall back to a full-document concatenated regex pass with explicit deduplication. Not needed in v1; flagged as a small follow-up risk.

**Existing Presidio behaviour and the inconsistency it creates:**

Presidio detection (`EntityRecognitionHandler::extractFromChunk`) runs per-chunk and stores relations without dedup. An entity name in the overlap region is detected by both chunks and produces two `EntityRelation` rows with different `chunkId` + `positionStart` but the same absolute position.

After this change, manual-entity matching dedup'd by absolute position would produce ONE relation per overlap occurrence, while Presidio still produces TWO. This is a documented inconsistency — manual entities are SMARTER than Presidio detection — and is acceptable for v1 because:

- Manual-entity correctness shouldn't wait for a Presidio-side fix.
- The user-facing impact of Presidio's duplicates is the grondslagen-summary potentially showing the entity twice; this is a separate UX-side problem that doesn't block manual entities.

Future change `presidio-overlap-dedup` should apply the same dedup-by-absolute-position to `EntityRecognitionHandler::storeDetectedEntities` so detection and manual-add behaviours converge. Flagged in proposal "Out of scope".

**Performance:** per-chunk regex is O(chunkSize) per chunk, O(N) total in document size. Dedup step is O(N log N) for the sort + O(N) for the dedup. Memory bounded by one chunk's text plus the dedup map. For typical files (1MB extracted text, ~1000 chunks) the operation completes in well under 100ms.

### D3. Whole-word + case-sensitive matching defaults

Defaults: `wholeWord = true`, `caseSensitive = true`. Rationale:

**Whole-word default protects against over-redaction.** If the operator types `"Jan"` and `wholeWord = false`, the substring match catches:

- `"Janitor"`, `"January"`, `"Anjana"` — words containing `"Jan"` that aren't names.
- All occurrences of `"Jan"` within larger words across the document.

The substitution pass (which is value-keyed) would then redact all matches as `"[PERSON: <id>]"`, producing visible corruption like `"[PERSON: 12]itor"`, `"[PERSON: 12]uary"`. This is operator-hostile. Whole-word boundary matching (`\bJan\b`) prevents this without surprising the operator.

**Case-sensitive default mirrors Presidio.** Presidio's named-entity recognition preserves case. An operator-typed `"jan jansen"` (lowercase) deliberately matching `"Jan Jansen"` (proper case) is an unusual ask; the inverse — `"Jan Jansen"` matching all-lowercase occurrences — is even rarer. The defaults preserve the operator's intent literally.

**Both toggleable.** Some workflows genuinely want either or both relaxed (e.g. matching `"VW"` regardless of case in mixed-case documents). Per-call flags let the operator opt in without changing the default.

### D4. Lookup-or-create on the catalogue

`GdprEntityMapper::findOneByValueAndType(string $value, string $type): ?GdprEntity`. New helper. Backed by an SQL `WHERE value = ? AND type = ?` with the existing column types (`value` is a `string`, `type` is a `string`).

The service's flow:

```php
$existing = $this->gdprEntityMapper->findOneByValueAndType($value, $type);
if ($existing !== null) {
    $entity = $existing;
    $reused = true;
} else {
    $now = new DateTimeImmutable();
    $entity = new GdprEntity();
    $entity->setUuid(Uuid::v4()->toRfc4122());
    $entity->setValue($value);
    $entity->setType($type);
    $entity->setCategory(EntityRecognitionHandler::getCategoryForType($type));
    $entity->setDetectedAt($now);
    $entity->setUpdatedAt($now);
    $entity = $this->gdprEntityMapper->insert($entity);
    $reused = false;
}
```

**Why dedup on `(value, type)`:** this is the canonical entity identity in the catalogue. Two rows with the same value + type would produce divergent placeholder IDs (`[PERSON: 17]` in one file, `[PERSON: 42]` in another), breaking the cross-document correlation property the `entity-relation-grondslagen` capability spec made normative.

**Why `category` is server-derived, not operator-supplied:** the `oc_openregister_entities.category` column is `NOT NULL` with no default — every insert path MUST set it. The detector flow (`EntityRecognitionHandler::findOrCreateEntity`) derives the value from `$type` via `getCategoryForType()`; the manual-entity flow uses the same mapping (lifted to `public static` for shared use) so manual-entity rows and detector rows have identical categories for identical types. The endpoint intentionally does NOT accept a `category` field in the request body in v1 — exposing operator-override on category is a follow-up if a concrete use case emerges. Same applies to `updated_at` (also NOT NULL, no default) — populated alongside `detected_at` at insert time.

**Concurrency:** between the `findOneByValueAndType` lookup and the `insert`, a concurrent request could create a row with the same `(value, type)` — two parallel manual-entity calls for the same value race. Mitigations:

- Most direct: a unique index on `(value, type)` and catch the duplicate-key exception, falling back to a re-lookup. Adds a schema migration.
- Pragmatic v1: accept the race. It's narrow (sub-millisecond window) and the worst case is two catalogue rows that differ only in id + uuid — neither is destructive, and the second-write loses against the first only if both happen to be Presidio + manual on the same value at exactly the same instant. The grondslagen-summary already handles multiple catalogue rows with the same value (it groups by id, so two rows produce two report rows — annoying but not corrupt).
- v1.1 follow-up: add the unique index + duplicate-key catch if the race surfaces.

v1 chooses the pragmatic path.

### D5. Idempotent relation creation

`EntityRelationMapper::existsForFileAtPosition(int $fileId, int $entityId, int $chunkId, int $positionStart, int $positionEnd): bool`. Returns true when a row already exists for the exact `(fileId, entityId, chunkId, positionStart, positionEnd)` tuple.

Service flow for relation creation:

```php
$rowsToInsert = [];
$matchesSkipped = 0;
foreach ($matches as $match) {
    if ($this->entityRelationMapper->existsForFileAtPosition(
        $fileId, $entity->getId(), $match['chunkId'],
        $match['positionStart'], $match['positionEnd']
    )) {
        $matchesSkipped++;
        continue;
    }
    $rowsToInsert[] = [
        'uuid'            => Uuid::v4()->toRfc4122(),
        'entityId'        => $entity->getId(),
        'fileId'          => $fileId,
        'chunkId'         => $match['chunkId'],
        'positionStart'   => $match['positionStart'],
        'positionEnd'     => $match['positionEnd'],
        'context'         => $match['context'],
        'detectionMethod' => 'manual',
        'role'            => 'anonymisable',
        'confidence'      => 1.0,
        'anonymized'      => false,
        'skipAnonymization' => false,
        'createdAt'       => new DateTimeImmutable(),
    ];
}
$inserted = $this->entityRelationMapper->insertBatch($rowsToInsert);
```

**Why per-position idempotency:** the operator may invoke the endpoint multiple times for the same value on the same file (UI re-submit, retry-on-network-error, re-edit of an already-redacted file). Without idempotency, each call adds duplicate relations — bloating the grondslagen-summary, breaking the per-row decision metadata semantics (now there are multiple rows representing the same position).

**Why the full `(fileId, entityId, chunkId, positionStart, positionEnd)` tuple as the dedup key:** identical positions across different entities (e.g. position 142 has both `"Jan Jansen"` PERSON and `"Jansen"` PERSON entities) are legitimately distinct rows. The full tuple ensures we only skip true exact-duplicates.

**Performance:** N existence-checks per call. For typical Woo files this is ≤10. Even at 100 matches the total query cost is small (single-column index on (file_id, entity_id) likely exists; if not, the migration can add one).

### D6. Atomic transaction boundary

The full operation runs inside `IDBConnection::beginTransaction()` / `commit()` / `rollBack()`:

```
BEGIN
  lookup-or-create entity                      ─┐
  for each match position:                       │
    if exists → skip                             │  one atomic
    else → buffer for batch insert               │  operation
  batch-insert all buffered relation rows         │
  write audit-trail row(s)                       │
COMMIT  (or ROLLBACK on any exception)         ─┘
```

If the audit-trail insert fails, the entire operation rolls back (consistent with the `entity-relation-grondslagen` PATCH endpoint's atomicity guarantee — audit-write failure rolls back the data change so clients never observe a state change without a matching audit entry).

Exception during the regex/chunk-matcher pass: the operation is aborted before any DB writes — no rollback needed.

### D7. Audit-trail rows

Two action types:

```
action: "entity_create"
  - written ONLY when a NEW catalogue row is inserted (skipped when dedup'd)
  - user: actor UID (per ADR-005, UID not display name)
  - changed: { value, type, category }                     ← PII allowed in audit
  - object: entity.id (the new row's id)
  - timestamps standard

action: "entity_relations_batch_create"
  - written EVERY call (even when zero matches — records the operator's
    attempt, supports forensic "what did operators try")
  - user: actor UID
  - changed: {
      value,          ← PII allowed
      type,
      fileId,
      detectionMethod: "manual",
      matchCount: 2,
      matchesSkipped: 0,
      relationIds: [101, 102]                              ← drill-down handles
    }
  - object: fileId
  - timestamps standard
```

**Per-position drill-down:** the `relationIds` array in the batch-create row gives auditors a handle to read the per-row details from `oc_openregister_entity_relations`. We deliberately don't write a separate audit row per relation — that would balloon the audit trail by O(matches) and the per-row data is already available via the relations table.

**Permitted PII in audit per ADR-022:** audit trail is the explicit forensic exception. ADR-005's no-PII-in-logs constraint applies to ordinary logs, error responses, and debug output — none of those carry the `value` field after this change.

### D8. RBAC: file-write access check

The endpoint requires write access to the file referenced by `{fileId}`. Implementation reuses the same access-check helper that `markAsAnonymized` and `PATCH /api/entity-relations/{id}` already invoke. Three states:

- Caller has write access → proceed.
- Caller has read-only access → 403 `{ "error": "forbidden", "reason": "write access to file required" }`.
- File doesn't exist or caller can't see it → 404 (no oracle distinction from forbidden).

The catalogue write (the entity catalogue is tenant-shared) is gated by the same file-write check rather than by a separate catalogue ACL. Rationale: the operation is operator-facing as "add a manual entity for this file". The shared-catalogue side-effect — the new row persists beyond this file's scope — is an implementation detail invisible to the operator. The natural ACL anchor is the file the operator is anonymising.

**Alternative considered:** require write access to at least one register (option (c) in the discovery). Rejected — looser than necessary, and gives users with no file-write access on the target file a way to write catalogue rows that affect the target file's anonymisation.

### D9. Substitution-mismatch documented but not fixed

`DocumentProcessingHandler::anonymizeDocument` is value-keyed (substitutes every occurrence of the value). Manual-entity rows record positions, but the substitution pass doesn't consult them — every occurrence of the value is redacted, regardless of whether each position is in the relations set.

**Implication for the operator:** typing `"Jan Jansen"` and seeing matchCount = 2 still means EVERY occurrence of `"Jan Jansen"` gets redacted at anonymise time, not just at positions 142 and 8217.

**Why this is acceptable for v1:**

- Matches the current behaviour for Presidio-detected entities. Operators familiar with the system understand this property.
- The match-counts displayed in the response give the operator a reasonable expectation: "we found 2 positions" → the report will show 2 grondslagen-summary rows for this entity.
- A real workflow demanding "redact this occurrence only" hasn't surfaced. When it does, a follow-up `position-aware-substitution` change shifts the substitution pass to honour positions.

**Operator UI implication for DocuDesk:** the form should be clear that "adding 'Jan Jansen'" means "anonymise 'Jan Jansen' wherever it appears in this file" rather than "anonymise just these specific occurrences". DocuDesk-side concern; this OR change doesn't dictate UI copy.

### D10. detectionMethod enum: 'manual'

The `EntityRelation.detectionMethod` column is a free-form string. Existing values written by the detection pipeline: `'presidio'`, `'openanonymiser'`, `'pattern'` (verified by grep). Adding `'manual'` requires no schema change; the column already accepts arbitrary strings.

For type-safety: add a `DetectionMethod` constants class (or const block on `EntityRelationMapper`) with the four current values. Surface it via PhpDoc on the EntityRelation property docblock. Doesn't enforce at the DB level — that's a follow-up if rejection of unknown values is wanted.

Code paths verified during implementation that don't reject unknown values:
- `findEntitiesForAnonymization` joins on the relations table; doesn't filter on detectionMethod.
- DocuDesk's grondslagen-summary template doesn't switch on detectionMethod.
- `EntityRecognitionHandler` (Presidio side) writes detectionMethod = 'presidio' but doesn't read it.

A small test confirming `'manual'` round-trips through insert + find without rejection covers the regression risk.

## Risks / Trade-offs

- **[Concurrent catalogue dedup race]** → Per D4, accepted as narrow / non-destructive in v1. Mitigation = unique index in follow-up if it surfaces.
- **[Cross-chunk match handling correctness]** → Tested in `ChunkTextMatcherTest` with fixture chunks that span a needle across the boundary. The risk is a regex bug in the implementation, not an architectural one.
- **[Operator typo with no matches]** → Mitigated by the 200-with-message response shape. Operator sees feedback; doesn't get a confusing 4xx.
- **[Wide substitution surprise]** → Per D9, the operator's "added 2 occurrences" doesn't constrain redaction to those 2 positions. DocuDesk-side UI copy mitigates surprise; future `position-aware-substitution` change resolves architecturally.
- **[PII leak via HTTP logs]** → Mitigated by ADR-005 in the controller: request log line redacts the `value` field; error responses don't echo the value; only the audit row records it.
- **[Whole-word default too restrictive]** → Toggleable via `wholeWord: false`. Operator escapes the default per call.
- **[Existing operator workflow that depended on the legacy entities-array contract]** → No such workflow exists post-`entity-relation-grondslagen` (the HTTP path is relations-driven; the DI path's entities-array still works but bypasses the catalogue and produces unstable placeholders for operator-supplied values). This change provides the proper alternative.
- **[GdprEntity uniqueness migration]** → If we later add a unique index on `(value, type)` to close the dedup race, existing duplicate rows would need to be merged first. Documented as follow-up risk.
- **[Performance on huge files]** → Bounded by chunk-fetch + regex pass; O(N) in total text size. Documented threshold: well under 100ms for files up to 1MB extracted text.

## Migration Plan

1. Land `ChunkTextMatcher` utility class with unit tests.
2. Extend `GdprEntityMapper` with `findOneByValueAndType` lookup.
3. Extend `EntityRelationMapper` with `existsForFileAtPosition` and `insertBatch`.
4. Land `ManualEntityService` orchestrator.
5. Add the controller method + route registration.
6. Land integration tests against a stacked OR instance.
7. Update the `entity-relation-grondslagen` capability spec to include the new ADDED Requirements (the manual-entity flow).
8. Release. No DB migration; the new endpoint is additive.

**Rollback:** revert the controller wiring + route. The service / matcher / mapper helpers become unused but harmless. No existing data affected. Per-commit clean revert.

## Seed Data

Not applicable. This change adds service code and an endpoint; it does NOT introduce or modify any OpenRegister schemas. No `_registers.json` entries required.

## Open Questions

- **Should `addManualEntity` accept a list of values in one call?** Provisional: no — one value per call. Multi-value would conflate audit semantics (one row per value? per call?) and complicate the response shape. If real workflows need it, batch endpoint can be added later.
- **Should `category` be required or optional?** **Resolved: not in the API at all.** Server-derives from `type` via `EntityRecognitionHandler::getCategoryForType()`. Rationale: the column is NOT NULL with no default; the detector flow already derives consistently; exposing it as operator-input adds a UX decision that operators rarely have the context to make. Follow-up if a real use case for operator-override emerges.
- **Should the response include a `preview` of the post-anonymise rendering?** Provisional: no. The operator typed the value; rendering the placeholder back is trivial and the actual file content isn't modified until the explicit anonymise call. Preview UI is DocuDesk-side.
- **What happens if the file's extraction is stale (relations point at old chunk_ids)?** Provisional: caller's responsibility to re-extract before adding manual entities. The endpoint validates `chunkId` references exist; relations referencing stale chunks would be detected at relation insert time (foreign-key-like check). If extraction has moved on, the endpoint refuses with 422.
- **Should the response carry the per-relation `bases` field?** Provisional: no, manual entries don't seed bases at create time. Operator sets bases later via the existing `PATCH /api/entity-relations/{id}` decision-metadata endpoint. Default `bases = null` per the existing schema.
- **Should we expose a separate "look up entity by value+type" endpoint?** Provisional: no — internal helper only. If a future workflow needs to query the catalogue from outside, a `GET /api/gdpr-entities?value=...&type=...` filter parameter on the existing index endpoint is a smaller surface than a new endpoint.
