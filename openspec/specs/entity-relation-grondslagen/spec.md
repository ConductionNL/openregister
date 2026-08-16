---
status: done
---

# entity-relation-grondslagen Specification

## Purpose
Lets operators annotate detected entity-relations with legal-basis (`bases`) UUIDs and a `skipAnonymization` flag through an audited `PATCH /api/entity-relations/{id}` endpoint, and add manual entities to a file via chunk-aware exact-string matching. The anonymisation flow honours `skipAnonymization` so flagged relations are never redacted, substitutes entities with stable `[<TYPE>: <id>]` placeholders for byte-identical re-runs, and records every decision write in OpenRegister's audit trail while keeping operator-supplied values out of HTTP logs and error bodies.
## Requirements
### Requirement: `EntityRelation` MUST gain an optional `bases` JSON column

The `oc_openregister_entity_relations` table MUST gain a column named `bases` of type JSON (or the platform-equivalent JSON column type), nullable, with default `NULL`. The column MUST hold either `NULL`, an empty array `[]`, or an array of strings (UUIDs). No JSON-schema validation of the array contents is enforced at the database or mapper layer.

The `EntityRelation` PHP entity (`lib/Db/EntityRelation.php`) MUST expose `getBases(): ?array` and `setBases(?array $bases): void`, registered via `addType('bases', 'json')` in the constructor. `jsonSerialize()` MUST include `bases` in its output. Existing rows (pre-migration) MUST read as `bases = null` without errors.

#### Scenario: Migration adds the bases column without disturbing existing rows

- **GIVEN** an OpenRegister install with existing `EntityRelation` rows
- **WHEN** the migration is applied
- **THEN** the `bases` column MUST be added to `oc_openregister_entity_relations`
- **AND** every existing row MUST read with `bases = null` via `EntityRelation::getBases()`
- **AND** no other columns or rows MUST be modified beyond those added by this change

#### Scenario: Mapper reads and writes bases

- **GIVEN** an `EntityRelation` row written with `bases = ["uuid-a", "uuid-b"]`
- **WHEN** the row is read back via `EntityRelationMapper::find($id)`
- **THEN** `getBases()` MUST return the array `["uuid-a", "uuid-b"]`
- **AND** `jsonSerialize()` MUST include `bases` in its output

#### Scenario: Empty bases array is accepted and distinct from null

- **WHEN** the row is persisted with `bases = []`
- **THEN** subsequent reads MUST return `bases = []` (an empty array, not null)
- **AND** `jsonSerialize()['bases']` MUST be `[]`

### Requirement: `EntityRelation` MUST gain a boolean `skip_anonymization` column

The `oc_openregister_entity_relations` table MUST gain a column named `skip_anonymization` of type BOOLEAN, NOT NULL, default `false`.

The `EntityRelation` PHP entity MUST expose `getSkipAnonymization(): bool` and `setSkipAnonymization(bool $skip): void`, registered via `addType('skipAnonymization', 'boolean')` in the constructor. `jsonSerialize()` MUST include `skipAnonymization` in its output. Existing rows (pre-migration) MUST read as `skipAnonymization = false`.

#### Scenario: Migration adds the skip_anonymization column with default false

- **GIVEN** an OpenRegister install with existing `EntityRelation` rows
- **WHEN** the migration is applied
- **THEN** the `skip_anonymization` column MUST be added with default `false`
- **AND** every existing row MUST read with `skipAnonymization = false` via `EntityRelation::getSkipAnonymization()`

#### Scenario: Migration is idempotent across both new columns

- **GIVEN** the migration has already been applied
- **WHEN** the migration runs again (e.g. on upgrade after a previous deploy)
- **THEN** the migration MUST be a no-op
- **AND** no error MUST be raised

### Requirement: A `PATCH /api/entity-relations/{id}` endpoint MUST exist with a decision-only field whitelist

The system MUST expose `PATCH /api/entity-relations/{id}` accepting a JSON body whose top-level keys MUST be a subset of `{ "bases", "skipAnonymization" }`. Any other top-level key — including the system-controlled fields `anonymized` and `anonymizedValue`, and any structural field (`entityId`, `chunkId`, `fileId`, `objectId`, `emailId`, `positionStart`, `positionEnd`, `confidence`, `detectionMethod`, `context`, `registerId`, `schemaId`, `objectUuid`, `createdAt`) — MUST be rejected with HTTP 400 and an error body identifying the offending field name (e.g. `{ "error": "field_not_editable", "field": "anonymized" }`).

The endpoint MUST be `@NoAdminRequired`. Authorization is governed by the separate Requirement on file/object write-access (below).

A successful PATCH MUST return HTTP 200 with the updated `EntityRelation` row in `jsonSerialize()` shape. PATCH on a non-existent `{id}` MUST return HTTP 404.

The HTTP endpoint MUST be a thin wrapper over `EntityRelationMapper::updateDecisionMetadata` — there MUST be no duplicated whitelist, shape-validation, or audit-trail logic at the controller layer.

#### Scenario: PATCH with `bases` updates the row and returns 200

- **GIVEN** an EntityRelation row with id `42` and `bases: null`
- **WHEN** an authorized caller PATCHes `/api/entity-relations/42` with body `{ "bases": ["uuid-a"] }`
- **THEN** the response MUST be HTTP 200
- **AND** the response body MUST include `bases = ["uuid-a"]`
- **AND** subsequent reads MUST return `bases = ["uuid-a"]`

#### Scenario: PATCH with `skipAnonymization: true` flips the flag

- **GIVEN** an EntityRelation row with id `42` and `skipAnonymization: false`
- **WHEN** an authorized caller PATCHes `/api/entity-relations/42` with body `{ "skipAnonymization": true }`
- **THEN** the response MUST be HTTP 200
- **AND** subsequent reads MUST return `skipAnonymization = true`

#### Scenario: PATCH with both whitelist fields updates both

- **GIVEN** an EntityRelation row with id `42` and `bases: null`, `skipAnonymization: false`
- **WHEN** an authorized caller PATCHes `/api/entity-relations/42` with body `{ "bases": ["uuid-a"], "skipAnonymization": true }`
- **THEN** the response MUST be HTTP 200
- **AND** the row MUST have `bases = ["uuid-a"]` AND `skipAnonymization = true`

#### Scenario: PATCH targeting `anonymized` is rejected

- **GIVEN** any EntityRelation row
- **WHEN** the caller PATCHes with body `{ "anonymized": true }`
- **THEN** the response MUST be HTTP 400
- **AND** the error body MUST identify `anonymized` as the offending field
- **AND** the row MUST NOT be modified
- **AND** no audit entry MUST be written

#### Scenario: PATCH targeting `anonymizedValue` is rejected

- **WHEN** the caller PATCHes with body `{ "anonymizedValue": "tampered" }`
- **THEN** the response MUST be HTTP 400
- **AND** the row MUST NOT be modified

#### Scenario: PATCH targeting a structural field is rejected

- **WHEN** the caller PATCHes with body `{ "entityId": 99 }`
- **THEN** the response MUST be HTTP 400
- **AND** the error body MUST identify `entityId` as the offending field

#### Scenario: Whitelist rejection is atomic — no partial application

- **WHEN** the caller PATCHes with body `{ "bases": ["uuid-a"], "entityId": 99 }`
- **THEN** the response MUST be HTTP 400
- **AND** the row's `bases` MUST NOT be modified

#### Scenario: PATCH on a non-existent relation returns 404

- **GIVEN** there is no EntityRelation with id `999`
- **WHEN** the caller PATCHes `/api/entity-relations/999` with `{ "bases": ["uuid-a"] }`
- **THEN** the response MUST be HTTP 404

### Requirement: `EntityRelationMapper::updateDecisionMetadata` MUST be the single audited write path for the whitelist fields

The mapper MUST expose `updateDecisionMetadata(EntityRelation $relation, array $fields, ?IUser $actingUser = null): EntityRelation`. Callers (controller, in-process DI consumers) load the relation themselves via `find()` and pass it in — this keeps the mapper method pure (no hidden DB lookup) and makes it directly unit-testable without DB. The HTTP controller maps `DoesNotExistException` (from its own `find` call) to HTTP 404.

The method MUST:

1. Enforce the whitelist (`bases`, `skipAnonymization`); throw a typed validation exception for any unknown field name.
2. Validate the shape of each present field (`bases` MUST be `null` or an array of strings; `skipAnonymization` MUST be a boolean); throw a typed validation exception on shape mismatch.
3. Compute the diff against the current row state.
4. If the diff is empty (no field actually changes value), the method MUST return the row unmodified and MUST NOT write an audit entry.
5. Otherwise, apply the changed fields, persist via the underlying QBMapper, AND write an audit-trail entry — both MUST be committed transactionally (or use the same failure-handling mode the existing audit-traced mapper writes use today).

The HTTP `PATCH /api/entity-relations/{id}` controller and in-process DI callers MUST both call through this method. There MUST NOT be a parallel write path for `bases` or `skipAnonymization` that bypasses it.

#### Scenario: HTTP and DI callers see identical behaviour

- **GIVEN** an EntityRelation row with `bases: null`
- **WHEN** caller A PATCHes via HTTP with `{ "bases": ["uuid-a"] }`
- **AND** caller B (after A) updates via DI: `$mapper->updateDecisionMetadata(42, ['bases' => ['uuid-b']])`
- **THEN** both calls MUST succeed
- **AND** each call MUST produce one audit-trail entry
- **AND** the final row state MUST be `bases = ["uuid-b"]`

#### Scenario: A semantic-no-op PATCH produces no audit entry

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]` and `skipAnonymization: false`
- **WHEN** the caller PATCHes with body `{ "bases": ["uuid-a"], "skipAnonymization": false }` (values identical to current)
- **THEN** the response MUST be HTTP 200
- **AND** the returned row MUST be unchanged
- **AND** NO audit-trail entry MUST be written

#### Scenario: An empty PATCH body produces no audit entry

- **GIVEN** any EntityRelation row
- **WHEN** the caller PATCHes with body `{}`
- **THEN** the response MUST be HTTP 200
- **AND** NO audit-trail entry MUST be written

### Requirement: PATCH MUST validate the SHAPE of `bases` and `skipAnonymization` at the entry point but MUST NOT validate `bases` content

When `bases` is present in the PATCH body, the field MUST be:

- `null`, OR
- an array whose every element is a string.

Any other shape — a non-array value (e.g. a number, an object, a single string), or an array containing non-string elements — MUST be rejected with HTTP 400. The error body MUST identify the offending shape (e.g. `{ "error": "invalid_bases_shape", "reason": "must be null or array of strings" }`).

When `skipAnonymization` is present, it MUST be a boolean. Any other type MUST be rejected with HTTP 400.

The mapper MUST NOT apply any further validation to the string content of the `bases` array. The elements MUST be persisted verbatim regardless of whether they look like UUIDs, are well-formed, or resolve to any known `base` object. This is the deliberate content-agnostic contract; consumer apps own the vocabulary.

#### Scenario: PATCH rejects `bases` as a non-array

- **WHEN** the caller PATCHes with body `{ "bases": "uuid-a" }`
- **THEN** the response MUST be HTTP 400
- **AND** the row MUST NOT be modified

#### Scenario: PATCH rejects array elements that are not strings

- **WHEN** the caller PATCHes with body `{ "bases": ["uuid-a", 42] }`
- **THEN** the response MUST be HTTP 400

#### Scenario: PATCH rejects `skipAnonymization` as a non-boolean

- **WHEN** the caller PATCHes with body `{ "skipAnonymization": "yes" }`
- **THEN** the response MUST be HTTP 400

#### Scenario: Mapper accepts any string content in bases

- **GIVEN** a validated PATCH body `{ "bases": ["not-a-uuid", "12345", ""] }`
- **WHEN** the mapper writes the row
- **THEN** the row MUST be persisted with the values verbatim
- **AND** no error MUST be raised

### Requirement: PATCH MUST require write-access to the relation's parent file/object (ADR-005 / ADR-023)

The endpoint MUST require that the acting user can write the file or object the relation points at. The check MUST resolve in this order:

1. If the relation has `fileId` set — the acting user MUST be able to write the file (same check `FileTextController::anonymizeFile` implicitly inherits today).
2. Else if the relation has `objectId` (with `registerId` + `schemaId` for disambiguation) — the acting user MUST be able to update the underlying object.
3. Else if the relation has `emailId` — the email MUST be accessible (writeable) to the acting user.
4. Otherwise the PATCH MUST be denied with HTTP 403.

An unauthenticated session MUST receive HTTP 401 (Nextcloud's session-required path), not 403.

The endpoint MUST NOT require the admin role. The endpoint MUST NOT introduce a separate action-level permission for editing the decision fields (per ADR-023, action-level permissions are opt-in; the absence here is deliberate). If a future change introduces such a permission, it MUST add a new Requirement here.

#### Scenario: User without write-access to the parent file is rejected

- **GIVEN** an EntityRelation row with `fileId: F` and an authenticated user `bob` with no write-access to `F`
- **WHEN** `bob` PATCHes the relation with `{ "bases": ["uuid-a"] }`
- **THEN** the response MUST be HTTP 403
- **AND** the row MUST NOT be modified
- **AND** no audit-trail entry MUST be written

#### Scenario: Authorized user can PATCH

- **GIVEN** an EntityRelation row with `fileId: F` and user `alice` with write-access to `F`
- **WHEN** `alice` PATCHes the relation with `{ "skipAnonymization": true }`
- **THEN** the response MUST be HTTP 200
- **AND** the row's `skipAnonymization` MUST be `true`

#### Scenario: Unauthenticated request is rejected with 401

- **GIVEN** no authenticated session
- **WHEN** a client PATCHes `/api/entity-relations/42`
- **THEN** the response MUST be HTTP 401

### Requirement: Successful PATCH writes MUST be recorded in OpenRegister's audit trail (ADR-022 / Woo compliance)

Every successful `updateDecisionMetadata` write that produces a non-empty diff MUST emit one audit-trail entry through OpenRegister's existing immutable-audit-trail subsystem. The entry MUST include:

- `actor` — the acting user's Nextcloud UID (per ADR-005, the UID, NOT the display name).
- `timestamp` — an ISO-8601 datetime.
- `subjectType` — the table or canonical entity type (`openregister_entity_relations` or equivalent OR convention).
- `subjectId` — the row identifier.
- `changedFields` — an object whose keys are the whitelist fields that actually changed (subset of `bases`, `skipAnonymization`) and whose values are `{ "previous": <old>, "new": <new> }`. Fields that were submitted but already held the new value MUST NOT appear.

Reads of `EntityRelation` rows — via `EntityRelationMapper::find`, `findEntitiesForFile`, `findByEntityId`, `findByFileId`, or any other read API — MUST NOT produce audit-trail entries.

#### Scenario: Setting bases for the first time produces an audit entry

- **GIVEN** an EntityRelation row with `bases: null`
- **WHEN** an authorized caller PATCHes `{ "bases": ["uuid-a"] }`
- **THEN** an audit-trail entry MUST be written with the acting user UID, an ISO-8601 timestamp, the row identifier
- **AND** `changedFields.bases` MUST be `{ "previous": null, "new": ["uuid-a"] }`

#### Scenario: Flipping skip produces an audit entry

- **GIVEN** an EntityRelation row with `skipAnonymization: false`
- **WHEN** an authorized caller PATCHes `{ "skipAnonymization": true }`
- **THEN** the audit-trail entry MUST record `changedFields.skipAnonymization = { "previous": false, "new": true }`

#### Scenario: Reads do not produce audit entries

- **WHEN** an `EntityRelation` row is read via `EntityRelationMapper::find` or `findEntitiesForFile`
- **THEN** no audit-trail entry MUST be produced for the read

### Requirement: PATCH MUST follow standard partial-update semantics

For each whitelist field, the body shape MUST map to behaviour as follows:

| `bases` | Effect | Audit? |
|---|---|---|
| absent | unchanged | no |
| `null` | set to `null` (clear) | yes, only if previous wasn't already null |
| `[]` | set to `[]` (distinct from null) | yes, only if previous wasn't already `[]` |
| `["..."]` | set to the array | yes, only if previous differs |

| `skipAnonymization` | Effect | Audit? |
|---|---|---|
| absent | unchanged | no |
| `true` | set to `true` | yes, only if previous was `false` |
| `false` | set to `false` | yes, only if previous was `true` |

A retry that omits a field does not touch the persisted value — supports retry-by-omission cleanly.

#### Scenario: PATCH that omits `bases` preserves the persisted value

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]`
- **WHEN** an authorized caller PATCHes `{ "skipAnonymization": true }` (omitting `bases`)
- **THEN** the row's `bases` MUST remain `["uuid-a"]`
- **AND** the audit-trail entry MUST record `skipAnonymization` only — `bases` MUST NOT appear in `changedFields`

#### Scenario: Explicit `bases: null` clears the persisted value

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]`
- **WHEN** an authorized caller PATCHes `{ "bases": null }`
- **THEN** the row's `bases` MUST be `null`
- **AND** `changedFields.bases` MUST be `{ "previous": ["uuid-a"], "new": null }`

#### Scenario: Explicit `bases: []` sets empty array

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]`
- **WHEN** an authorized caller PATCHes `{ "bases": [] }`
- **THEN** the row's `bases` MUST be `[]` (empty array, not null)

### Requirement: The anonymise flow MUST honour `skipAnonymization`

The system MUST filter out relations where `skip_anonymization = true` from both anonymise paths:

1. **HTTP path** (`POST /api/files/:fileId/anonymize` → `FileTextController::anonymizeFile`): the relations returned for text-replacement MUST exclude rows where `skip_anonymization = true`. `EntityRelationMapper::markAsAnonymized($fileId, ...)` MUST update only rows where `skip_anonymization = false` — skipped rows MUST retain `anonymized = false`.

2. **DI path** (`FileService::anonymizeDocument(Node, entities[])`): regardless of what entities the caller passes, the OR-side service MUST consult the persisted `skip_anonymization` state per entity-relation and filter out skipped rows before calling `DocumentProcessingHandler::anonymizeDocument`. Defensive filtering inside OR — the contract is "skipped relations are never redacted, full stop", regardless of caller behaviour.

#### Scenario: HTTP anonymise skips relations flagged with skipAnonymization

- **GIVEN** a file `F` with three EntityRelation rows: R1 (`skip=false`), R2 (`skip=true`), R3 (`skip=false`)
- **WHEN** an authorized caller POSTs `/api/files/F/anonymize`
- **THEN** the response MUST be HTTP 200 and the redacted file MUST contain R1's and R3's placeholders but NOT R2's
- **AND** R1's and R3's rows MUST have `anonymized = true`
- **AND** R2's row MUST have `anonymized = false`
- **AND** R2's `skipAnonymization = true` MUST be unchanged

#### Scenario: DI anonymise filters skipped rows even when the caller includes them

- **GIVEN** a file `F` with relations R1 (`skip=false`) and R2 (`skip=true`)
- **AND** a DI caller that includes both R1 and R2 in the `entities[]` array passed to `FileService::anonymizeDocument`
- **WHEN** the call runs
- **THEN** OR MUST filter R2 out server-side before text-replacement
- **AND** the resulting file MUST NOT include R2's placeholder
- **AND** R2's row MUST have `anonymized = false`

#### Scenario: `markAsAnonymized` does not flip anonymized on skipped rows

- **GIVEN** a file `F` with relations R1 (`skip=false`) and R2 (`skip=true`)
- **WHEN** `EntityRelationMapper::markAsAnonymized($F, ...)` runs
- **THEN** R1 MUST have `anonymized = true`
- **AND** R2 MUST have `anonymized = false`

#### Scenario: Flipping skip to true after anonymise does not retroactively un-redact

- **GIVEN** R1 has `anonymized = true` from a prior redaction pass
- **WHEN** an authorized caller PATCHes R1 with `{ "skipAnonymization": true }`
- **THEN** R1's `skipAnonymization` MUST be `true`
- **AND** R1's `anonymized` MUST remain `true` (the prior redaction is not undone)
- **AND** the audit-trail records the skip flip but no un-redact event is fabricated

### Requirement: OpenRegister MUST NOT validate that `bases` UUIDs resolve

The mapper accepts any string array; OpenRegister MUST NOT issue any cross-register lookup to verify that the supplied UUIDs correspond to actual `base` objects in any register. The vocabulary lives in the consumer app (see Notes for the canonical DocuDesk `base` vocabulary).

#### Scenario: Unknown UUID strings are accepted

- **GIVEN** an authorized caller PATCHes `{ "bases": ["00000000-0000-0000-0000-000000000000"] }` (a UUID that doesn't resolve)
- **WHEN** the endpoint processes the request
- **THEN** the row MUST be persisted with the value verbatim
- **AND** no error MUST be raised
- **AND** no cross-register query MUST be issued

### Requirement: The DI anonymise path MUST substitute each entity using the stable `[<TYPE>: <entity_id>]` placeholder format

`DocumentProcessingHandler::anonymizeDocument` MUST substitute each detected entity occurrence with a placeholder of the form `[<TYPE>: <entity_id>]`, where:

- `<TYPE>` is the entity's `entityType` (e.g. `PERSON`, `ORGANIZATION`, `LOCATION`, `EMAIL`, `BSN`).
- `<entity_id>` is the integer primary key of the matching row in `openregister_entities` (the canonical entity catalogue), looked up via `EntityRelationMapper::findEntityIdsByValueForFile($fileId)` for the file being anonymised.
- Whitespace MUST be exactly one space between the colon and the id.

The placeholder is stable across anonymise calls on the same source file: re-running the redaction with the same entity catalogue produces byte-identical output, which is required for the grondslagen-summary report's traceability invariant.

Fallback: when an entity in the substitution map has no matching row in `openregister_entities` for the file (an edge case for DI callers that bypass the standard detection path), the implementation MAY fall back to a per-call 8-character UUID fragment as `<id>` — this is not stable across calls but preserves the placeholder shape. Implementations SHOULD log a warning when this fallback is invoked.

#### Scenario: Stable placeholder uses the entity catalogue id

- **GIVEN** an `openregister_entities` row with `id=7`, `value="Jan Jansen"`, `type="PERSON"`
- **AND** an `EntityRelation` referencing that entity on a file
- **WHEN** `DocumentProcessingHandler::anonymizeDocument` runs for that file with `{ text: "Jan Jansen", entityType: "PERSON" }`
- **THEN** every occurrence of "Jan Jansen" in the redacted file content MUST be replaced with the literal string `[PERSON: 7]`

#### Scenario: Re-anonymising the same file is byte-identical

- **GIVEN** a file whose anonymised output was produced via this path at time T₁
- **WHEN** the same file is re-anonymised at time T₂ with the same entity catalogue state
- **THEN** the anonymised output at T₂ MUST be byte-identical to the output at T₁
- **AND** every placeholder MUST carry the same `<entity_id>` it had at T₁

#### Scenario: Missing entity row falls back to per-call UUID fragment

- **GIVEN** a DI caller passes an entity `{ text: "Jane Doe", entityType: "PERSON" }` that does NOT correspond to any row in `openregister_entities` for this file
- **WHEN** the anonymise path runs
- **THEN** the placeholder MUST follow the shape `[PERSON: <8-char-hex>]` where the hex is a UUID v4 fragment
- **AND** a warning MUST be logged identifying the fallback (without including the entity text per ADR-005)

### Requirement: Sole canonical caller of `markAsAnonymized` for the redaction flow MUST be `DocumentProcessingHandler::anonymizeDocument`

To prevent double-write conflicts where two callers both invoke `EntityRelationMapper::markAsAnonymized($fileId, ...)` on the same file (the second UPDATE overwrites the first's `anonymizedValue`), the call MUST be made by exactly ONE component in the redaction code path: `DocumentProcessingHandler::anonymizeDocument`. The HTTP controller (`FileTextController::anonymizeFile`) and any event listeners or DI orchestrators MUST NOT call `markAsAnonymized` independently — they invoke `anonymizeDocument`, and `anonymizeDocument` handles the marking.

The value written to `anonymizedValue` is a generic per-file marker (`'[REDACTED]'`), not the per-entity placeholder. Per-entity placeholders are recorded by the per-row substitution that happens inside the file content; the column's purpose is binary "this file's relations have been redacted at least once" plus a generic marker for audit log readability.

#### Scenario: HTTP anonymise call writes exactly one mark

- **GIVEN** a `POST /api/files/:fileId/anonymize` call against a file with non-skipped entities
- **WHEN** the controller invokes `FileService::anonymizeDocument` which delegates to `DocumentProcessingHandler::anonymizeDocument`
- **THEN** `EntityRelationMapper::markAsAnonymized` MUST be called exactly once for this fileId
- **AND** the controller MUST NOT issue its own `markAsAnonymized` call

#### Scenario: DI/event anonymise call writes exactly one mark

- **GIVEN** an event listener or DI orchestrator invokes `DocumentProcessingHandler::anonymizeDocument`
- **WHEN** the anonymise path runs to completion
- **THEN** `markAsAnonymized` MUST be called exactly once for this fileId, by `anonymizeDocument` itself

### Requirement: The change MUST be additive in API shape; only anonymise behaviour gains a skip filter

The existing API endpoints — `FileService::anonymizeDocument(Node, entities[])` (DI) and `POST /api/files/:fileId/anonymize` (HTTP) — MUST keep their existing parameters and response shape. They MUST NOT consume any new field on entity-payload entries (e.g. erroneous `bases` on payload entries MUST be silently ignored). `EntityRelationMapper::markAsAnonymized`'s signature MUST be unchanged; only its WHERE clause gains the `AND skip_anonymization = 0` predicate.

Existing rows (pre-migration) MUST continue to read with `bases = null` and `skipAnonymization = false`. OpenAnonymiser integration (`EntityRecognitionHandler::detectWithOpenAnonymiser`) MUST be unchanged.

#### Scenario: Existing anonymise call ignores extra fields on payload entries

- **GIVEN** a caller using `FileService::anonymizeDocument(Node, entities[])` with entity entries that include a (no-op) `bases` field
- **WHEN** the anonymise flow runs
- **THEN** the call MUST succeed with identical text-replacement behaviour to today
- **AND** the `bases` field on payload entries MUST NOT alter the EntityRelation rows (PATCH endpoint is the only path for that)

#### Scenario: HTTP anonymise route's signature is unchanged

- **WHEN** a caller using the pre-change HTTP shape calls `POST /api/files/:fileId/anonymize`
- **THEN** the request signature MUST be accepted exactly as before
- **AND** the response shape MUST be identical to before (modulo the skip-filtered set of entities)
- **AND** if no relations under the file have `skip_anonymization = true`, the behaviour MUST be identical to the pre-change behaviour

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

