---
status: draft
---

# Entity Relation Grondslagen — Manual Entity Write Path

## Purpose

Extends the existing `entity-relation-grondslagen` capability with a write path for operator-supplied entities. Today the capability defines the read side (per-row decision metadata, skip-honouring, stable placeholders) and the existing detection pipeline (Presidio) is the only writer of `EntityRelation` rows. This delta adds an HTTP-driven write path so DocuDesk operators can manually add a piece of text as anonymisable on a specific file — producing the same row shape that Presidio would produce, so the existing anonymise pipeline picks it up unchanged.

## ADDED Requirements

### Requirement: `POST /api/files/{fileId}/manual-entities` MUST atomically create or reuse a catalogue entry and create one relation per occurrence found

The endpoint accepts a JSON body with required fields `value` (string) and `type` (string) plus optional match-behaviour flags `wholeWord` (boolean, default true) and `caseSensitive` (boolean, default true). `category` is NOT in the request body in v1 — it is derived server-side from `type` via `EntityRecognitionHandler::getCategoryForType()` so the catalogue stays consistent with detector-produced rows. The implementation MUST:

1. Validate the body. Missing `value` or `type` returns HTTP 400. `Content-Type` other than `application/json` returns HTTP 415.
2. Verify the caller has write access to the file referenced by `{fileId}`. Failure returns HTTP 403 with structured body `{ "error": "forbidden" }`.
3. Verify the file exists and its text-extraction chunks are present. A missing file returns HTTP 404. A file with no chunks returns HTTP 422 with body `{ "error": "file_not_extracted" }`.
4. Inside a single database transaction:
   - Look up `(value, type)` in `oc_openregister_entities`. If a row exists, reuse it; if not, insert a new catalogue row.
   - Match `value` against the file's concatenated chunk text per the matching contract (next Requirement).
   - For each match position not already present in `oc_openregister_entity_relations` for `(fileId, entityId, chunkId, positionStart, positionEnd)`, prepare a relation row with `detectionMethod = 'manual'`, `role = 'anonymisable'`, `confidence = 1.0`, `anonymized = false`, `skipAnonymization = false`.
   - Batch-insert the prepared rows.
   - Write audit-trail rows per the audit Requirement below.
5. On any database failure within the transaction, ALL changes MUST be rolled back; the response is HTTP 500 with `{ "error": "internal_error" }` (no PII).
6. On success with one or more matches: HTTP 201 with the response body documented below.
7. On success with zero matches: HTTP 200 (NOT 4xx) with the catalogue entry created/reused, an empty `relations` array, `matchCount = 0`, and a `message` field explaining no matches were found in this file.

#### Scenario: Successful create with matches found

- **GIVEN** a file with extracted chunks containing the text `"Aanvraag van Jan Jansen voor het loket"` and `"met vriendelijke groet, Jan Jansen, secretaris"` (two occurrences of `"Jan Jansen"`)
- **AND** the caller has write access to the file
- **WHEN** the caller POSTs `{ "value": "Jan Jansen", "type": "PERSON" }`
- **THEN** the response is HTTP 201
- **AND** the response body's `entity` carries the new catalogue row's id and uuid with `reused: false` (assuming no prior entry)
- **AND** the response body's `relations` array contains exactly 2 rows
- **AND** each relation has `chunkId`, `positionStart`, `positionEnd`, `context`, and an id
- **AND** `matchCount` equals 2
- **AND** `matchesSkipped` equals 0
- **AND** `oc_openregister_entities` contains the new row
- **AND** `oc_openregister_entity_relations` contains the 2 new rows with `detection_method = 'manual'`

#### Scenario: Successful create with zero matches (operator typo)

- **GIVEN** a file whose extracted chunks do NOT contain the text `"Jane Doe"`
- **WHEN** the caller POSTs `{ "value": "Jane Doe", "type": "PERSON" }`
- **THEN** the response is HTTP 200 (NOT 4xx)
- **AND** the response body's `entity` carries a catalogue row id and uuid
- **AND** the response body's `relations` array is empty
- **AND** `matchCount` equals 0
- **AND** the response body has a `message` field stating the text was not found in this file
- **AND** the catalogue row IS persisted (so it can be referenced from other files)

#### Scenario: Idempotent re-call

- **GIVEN** a previous call already created an entity + 2 relations for `(value: "Jan Jansen", type: "PERSON", fileId: 123)`
- **WHEN** the same POST is made again with the same body
- **THEN** the response is HTTP 200 (no NEW rows created, but the operation succeeded)
- **AND** `matchCount + matchesSkipped` still equals 2
- **AND** `matchesSkipped` equals 2
- **AND** `oc_openregister_entity_relations` contains exactly 2 rows for this `(fileId, entityId)` pair — no duplicates
- **AND** the catalogue entry's `reused` field in the response is `true`

#### Scenario: Caller without file-write access is denied

- **GIVEN** a caller with read-only access to the file
- **WHEN** the caller POSTs a valid manual-entity request
- **THEN** the response is HTTP 403
- **AND** the response body is `{ "error": "forbidden" }`
- **AND** no catalogue row is created
- **AND** no relation rows are created

#### Scenario: File extraction has not been run

- **GIVEN** a file that exists but has no text-extraction chunks yet
- **WHEN** the caller POSTs a valid manual-entity request
- **THEN** the response is HTTP 422
- **AND** the response body is `{ "error": "file_not_extracted" }`
- **AND** no catalogue row is created
- **AND** no relation rows are created

### Requirement: Chunk-aware exact-string matching MUST run per-chunk and deduplicate by absolute position

The matcher MUST account for the overlapping-chunk storage model produced by `TextExtractionService` (defaults: `chunk_size = 1000`, `chunk_overlap = 200`). Each `Chunk` row carries `textContent`, `startOffset` and `endOffset` recording the chunk's position in the original extracted text, and a `chunkIndex` ordering field. Because the overlap means any entity in a boundary region appears in two adjacent chunks, the implementation MUST search per-chunk and deduplicate matches by absolute position — a naive concatenation-of-chunks search would double-count every overlap-region occurrence.

Specifically, the match implementation MUST:

1. Fetch all chunks for the file ordered by `chunkIndex` (ascending).
2. Validate the needle length against the file's chunk-overlap parameter. If `strlen(value) > chunk_overlap` (default 200 bytes), return HTTP 400 with a clear error message — the per-chunk algorithm cannot guarantee detection of matches that exceed the overlap window.
3. Build a regex pattern from the supplied `value`:
   - The value MUST be `preg_quote`-escaped against the regex delimiter.
   - When `wholeWord = true` (default), the pattern is wrapped in `\b...\b` boundary assertions.
   - When `caseSensitive = false`, the regex `i` flag is applied.
   - The `u` flag MUST be applied unconditionally to ensure Unicode word boundaries (`\b`) work correctly with non-ASCII text (e.g. Dutch `ë`, `é`).
4. Execute `preg_match_all` on EACH chunk's `textContent` independently (NOT on a concatenation of chunks).
5. For each match in each chunk, compute the absolute position:
   - `chunkRelativeStart` = the match's offset within the chunk's text
   - `absoluteStart` = `chunk.startOffset + chunkRelativeStart`
   - `absoluteEnd` = `absoluteStart + needleByteLength`
6. Deduplicate across all chunks by `(absoluteStart, absoluteEnd)`:
   - Group matches with identical absolute positions.
   - For each group, keep ONE entry — from the chunk with the LOWEST `chunkIndex` containing the match (the "canonical chunk" for that absolute position).
   - This selection MUST be deterministic so that repeat calls pick the same canonical chunk → idempotency check via `(fileId, entityId, chunkId, positionStart, positionEnd)` works without absolute-position arithmetic.
7. Sort the deduplicated list by `absoluteStart`.
8. For each match emit `{ chunkId, positionStart, positionEnd, context }` where `chunkId` and the positions are the canonical chunk's chunkId and the chunk-relative positions in it; `context` is ~30 chars before and after the match extracted from the canonical chunk's `textContent`.

Matches MUST be non-overlapping within each chunk's regex pass (PHP's default `preg_match_all` behaviour).

The implementation MUST NOT concatenate chunk text into a single string for matching, because the overlap regions would produce duplicate matches.

#### Scenario: Match in chunk-overlap region produces ONE relation, not two

- **GIVEN** a file with two adjacent chunks: chunk 0 spans absolute offset 0..950 ending with `"...met groet, Jan Jansen, secretaris"`, and chunk 1 spans absolute offset 750..1750 starting with `"...secretaris. De heer Jan Jansen ontvangt..."`
- **AND** the text `"Jan Jansen"` appears at absolute position 925-935 (in chunk 0's tail AND chunk 1's overlap head)
- **WHEN** the caller POSTs `{ "value": "Jan Jansen", "type": "PERSON" }`
- **THEN** the matcher finds the match in chunk 0 at chunkRelativeStart 925 AND in chunk 1 at chunkRelativeStart 175 — both resolving to absolute position 925-935
- **AND** the dedup step keeps ONLY the chunk 0 entry (lowest chunkIndex containing the match)
- **AND** exactly ONE relation row is inserted with `chunkId` referencing chunk 0 and `positionStart = 925`
- **AND** the `matchCount` in the response equals 1 (NOT 2)
- **AND** a re-call of the same endpoint returns `matchCount + matchesSkipped = 1` with the existing relation found at the canonical (chunk 0) position

#### Scenario: Distinct matches in chunks WITHOUT overlap collision

- **GIVEN** a file with two chunks containing `"Jan Jansen"` at distinct absolute positions: one at absolute 100 (entirely within chunk 0, not in overlap) and one at absolute 1500 (entirely within chunk 1, not in overlap)
- **WHEN** the caller POSTs the request
- **THEN** the matcher finds two distinct absolute positions
- **AND** both are kept (different absolute positions → not duplicates)
- **AND** two relation rows are created — one with `chunkId` = chunk 0 + `positionStart` = 100, one with `chunkId` = chunk 1 + `positionStart` = (chunk-relative offset)

#### Scenario: Needle longer than chunk_overlap is rejected

- **GIVEN** a file extracted with `chunk_overlap = 200`
- **WHEN** the caller POSTs `{ "value": "<a 250-char string>", "type": "OTHER" }`
- **THEN** the response is HTTP 400
- **AND** the response body identifies the cause as "value too long for chunked matching" with the overlap limit
- **AND** the body does NOT echo the offending value (ADR-005)
- **AND** no catalogue row is created
- **AND** no relation rows are created

#### Scenario: Whole-word default rejects substring matches

- **GIVEN** a file containing the text `"Janitor cleans on Tuesday in January"` (containing the substring `"Jan"` inside `"Janitor"` and `"January"`)
- **WHEN** the caller POSTs `{ "value": "Jan", "type": "PERSON" }` (default `wholeWord: true`)
- **THEN** `matchCount` equals 0 — neither `Janitor` nor `January` matches
- **AND** no relation rows are created

#### Scenario: Whole-word disabled finds substring matches

- **GIVEN** the same file as the previous scenario
- **WHEN** the caller POSTs `{ "value": "Jan", "type": "PERSON", "wholeWord": false }`
- **THEN** `matchCount` equals 2 — substring matches inside `Janitor` and `January`
- **AND** two relation rows are created at the substring positions

#### Scenario: Case-sensitive default rejects mismatched case

- **GIVEN** a file containing `"jan jansen"` in lowercase
- **WHEN** the caller POSTs `{ "value": "Jan Jansen", "type": "PERSON" }` (default `caseSensitive: true`)
- **THEN** `matchCount` equals 0

#### Scenario: Case-insensitive option finds mismatched case

- **GIVEN** the same lowercase file
- **WHEN** the caller POSTs `{ "value": "Jan Jansen", "type": "PERSON", "caseSensitive": false }`
- **THEN** `matchCount` equals 1
- **AND** one relation row is created

#### Scenario: Non-overlapping matches

- **GIVEN** a file containing `"Jan Jan Jansen"` (three contiguous tokens)
- **WHEN** the caller POSTs `{ "value": "Jan", "type": "PERSON" }` (whole-word default)
- **THEN** `matchCount` equals 3 (one match per `Jan` token)
- **AND** no match overlaps another in offset

#### Scenario: Unicode word boundary handling

- **GIVEN** a file containing the Dutch text `"Geboren in 's-Gravenhage"` (with an apostrophe + hyphen in the word)
- **WHEN** the caller POSTs `{ "value": "s-Gravenhage", "type": "LOCATION", "wholeWord": true }`
- **THEN** the regex engine MUST handle the boundary correctly per Unicode word-character semantics
- **AND** no internal-server-error occurs from regex compilation

### Requirement: Catalogue lookup-or-create MUST use `(value, type)` as the dedup key

When the endpoint processes a manual-entity request, the catalogue lookup MUST query `oc_openregister_entities` for `WHERE value = :value AND type = :type`. If exactly one row matches, it is reused — its `id` is referenced by all new relation rows. If no row matches, a new row is inserted with `value`, `type`, the server-derived `category` (from `EntityRecognitionHandler::getCategoryForType($type)`), `uuid`, `detectedAt`, and `updatedAt`. The response body MUST surface this distinction via an `entity.reused` boolean.

The catalogue's `category` column is NOT NULL with no default; the manual-entity insert MUST set it. The derivation matches the detector flow's mapping so manual-entity rows and detector rows share the same category for the same type.

#### Scenario: Existing catalogue row is reused

- **GIVEN** a row in `oc_openregister_entities` with `id = 17`, `value = "Jan Jansen"`, `type = "PERSON"` (e.g. created by a previous Presidio detection on another file)
- **WHEN** the caller POSTs `{ "value": "Jan Jansen", "type": "PERSON" }` for a different file
- **THEN** the response's `entity.id` equals 17
- **AND** the response's `entity.reused` is `true`
- **AND** no new row is inserted into `oc_openregister_entities`
- **AND** the new relation rows reference `entity_id = 17`
- **AND** the placeholder in any subsequent anonymisation of this file uses `[PERSON: 17]` — matching the placeholder on the other file

#### Scenario: New catalogue row created when no match exists

- **GIVEN** no row in `oc_openregister_entities` with `value = "Saskia Bakker"` and `type = "PERSON"`
- **WHEN** the caller POSTs `{ "value": "Saskia Bakker", "type": "PERSON" }`
- **THEN** a new row is inserted into `oc_openregister_entities` with these values
- **AND** the response's `entity.reused` is `false`

#### Scenario: New catalogue row carries the server-derived category

- **GIVEN** no row in `oc_openregister_entities` with `value = "Acme Corp"` and `type = "ORGANIZATION"`
- **WHEN** the caller POSTs `{ "value": "Acme Corp", "type": "ORGANIZATION" }`
- **THEN** a new row is inserted with `category = "business_data"` (the value returned by `EntityRecognitionHandler::getCategoryForType("ORGANIZATION")`)
- **AND** the response's `entity.reused` is `false`

### Requirement: Audit-trail rows MUST be written for entity-create and batch-relation-create

The implementation MUST write at least one audit-trail row per call:

- `action = "entity_create"` — written ONLY when a NEW catalogue row is inserted (skipped when reusing an existing entity). The row carries `user = actor UID`, `object = entity.id`, and `changed = { value, type, category }`. Per ADR-022, the PII value is recorded in the audit (audit is the explicit forensic exception to ADR-005's no-PII-in-logs constraint).
- `action = "entity_relations_batch_create"` — written EVERY call, including when zero matches were found. The row carries `user = actor UID`, `object = fileId`, and `changed = { value, type, fileId, detectionMethod: "manual", matchCount, matchesSkipped, relationIds: [int, int, ...] }`. The `relationIds` array provides drill-down handles to the per-row relation data without needing one audit row per relation.

If the audit-trail write itself fails, the entire transaction MUST be rolled back. Clients MUST NOT observe a state change without a matching audit entry.

#### Scenario: Successful create writes both audit rows

- **GIVEN** a successful manual-entity request creating a new catalogue entry and 2 relations
- **WHEN** the transaction commits
- **THEN** the audit trail contains 1 row with `action = "entity_create"`
- **AND** the audit trail contains 1 row with `action = "entity_relations_batch_create"`
- **AND** the batch row's `changed.relationIds` lists the 2 new relation ids in their insertion order

#### Scenario: Reuse path writes only the batch row

- **GIVEN** a successful manual-entity request that reused an existing catalogue entry
- **WHEN** the transaction commits
- **THEN** no `entity_create` audit row is written (the entity already existed)
- **AND** one `entity_relations_batch_create` audit row IS written

#### Scenario: Zero-match call still writes an audit row

- **GIVEN** a manual-entity request that found zero matches in the file
- **WHEN** the call returns HTTP 200
- **THEN** the audit trail contains 1 row with `action = "entity_relations_batch_create"`
- **AND** the row's `changed.matchCount` is 0
- **AND** the row's `changed.relationIds` is an empty array
- **AND** the row's `changed.value` records the operator's typed text (forensic — supports "what searches did operators attempt")

#### Scenario: Audit write failure rolls back the data write

- **GIVEN** the audit-trail mapper is failing (e.g. database error during insert)
- **WHEN** a manual-entity request would otherwise succeed
- **THEN** the entire transaction is rolled back
- **AND** no catalogue row remains
- **AND** no relation rows remain
- **AND** the response is HTTP 500 with body `{ "error": "internal_error" }`

### Requirement: HTTP logs and error responses MUST NOT contain the operator-supplied `value` (ADR-005)

Per ADR-005, PII is permitted only in audit-trail rows — not in HTTP request/response logs, error responses, or debug output. The controller and service MUST:

- Sanitize the request-log line by redacting the `value` field. Permitted log content: `{ fileId, type, wholeWord, caseSensitive, valueLength: <int> }` — the value's length but not its content.
- NOT echo the `value` in any 4xx or 5xx response body. Validation errors say "missing required field" / "invalid type" without quoting the offending content.
- NOT include the value in exception messages that may propagate to a 5xx response.

The `value` MAY appear in the 2xx success response body (the caller already submitted it and is the rightful recipient of the response).

The `value` MUST appear in audit-trail rows (ADR-022 forensic exception).

#### Scenario: 4xx response does not echo the value

- **GIVEN** a malformed request with `value = "Some PII text"` and a missing `type` field
- **WHEN** the response is generated
- **THEN** the response body does NOT contain `"Some PII text"`
- **AND** the body identifies the missing field by name (`type`) but not by value

#### Scenario: Request log line does not contain the value

- **GIVEN** any manual-entity request with `value = "Saskia Bakker"`
- **WHEN** the controller logs the request
- **THEN** the log entry does NOT contain `"Saskia Bakker"`
- **AND** the log entry MAY contain `valueLength: 13`, `fileId`, `type`, and the boolean flags

### Requirement: `detectionMethod = 'manual'` MUST round-trip through insert + find without rejection

The string `'manual'` MUST be accepted as a value for `EntityRelation.detectionMethod`. No code path on the read side (`findEntitiesForAnonymization`, `findEntityIdsByValueForFile`, `findSkippedEntityValuesForFile`) MUST filter out rows by detectionMethod. Manual-method relation rows MUST behave identically to Presidio-method rows in the existing anonymise pipeline.

#### Scenario: Manual relation is included in findEntitiesForAnonymization

- **GIVEN** a file with 1 Presidio-method relation and 1 manual-method relation, both non-skipped
- **WHEN** `findEntitiesForAnonymization($fileId)` is called
- **THEN** the result contains both relations
- **AND** the existing anonymise pipeline produces a substitution for both

#### Scenario: Manual relation honours skip flag identically to Presidio relation

- **GIVEN** a manual-method relation with `skip_anonymization = true`
- **WHEN** the anonymise pipeline runs
- **THEN** the relation's value is NOT substituted in the file
- **AND** the relation retains `anonymized = false` after the pass

### Requirement: The substitution pass remains value-keyed; positions on manual relations are forensic markers

The new manual-entity endpoint records `positionStart`, `positionEnd`, and `chunkId` on each relation row. `DocumentProcessingHandler::anonymizeDocument` (the substitution pass) MUST continue to use value-keyed `strtr`-style substitution — every occurrence of the entity's value in the file is redacted, regardless of whether that occurrence corresponds to a recorded position on a relation row. The position fields serve as forensic/report markers, NOT as redaction filters.

This Requirement makes the existing substitution behaviour normative AND documents the constraint that manual relations inherit it. A future change (`position-aware-substitution`) MAY shift the substitution pass to honour positions; until that lands, this Requirement holds.

#### Scenario: Substitution redacts every occurrence regardless of position records

- **GIVEN** a file containing `"Jan Jansen"` at 3 positions: 142, 1051, 8217
- **AND** manual-entity relations recorded for only positions 142 and 8217 (operator's call recorded those two; position 1051 has no relation row because the matcher missed it in a prior fence-post bug, or the operator deleted it)
- **WHEN** the anonymise pipeline runs
- **THEN** all 3 occurrences of `"Jan Jansen"` in the output file are replaced with `[PERSON: <id>]`
- **AND** the placeholder is identical at all 3 positions

#### Scenario: Operator UI surfaces this property (out of normative scope; documented for DocuDesk reference)

- **GIVEN** the operator adds a manual entity and the response shows `matchCount = 2`
- **THEN** the operator's expectation (per DocuDesk UI copy) is "this value will be redacted everywhere it appears in this file", NOT "redaction is limited to those 2 positions"
- **AND** the spec leaves the UI copy responsibility to DocuDesk (this OR spec doesn't normatively constrain it)
