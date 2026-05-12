---
status: draft
---

# Entity Relation Grondslagen

## Purpose

Defines two new operator-decision fields on `EntityRelation` (`bases` — legal grondslagen for redaction; `skipAnonymization` — opt-out from redaction) and an audited write path for them: a new `PATCH /api/entity-relations/{id}` HTTP endpoint backed by an `EntityRelationMapper::updateDecisionMetadata` DI method. The fields are per-relation (per-position) — operators may make different decisions for different occurrences of the same entity within a file if their UX surfaces that granularity. The anonymise flow honours `skipAnonymization`: skipped rows are not redacted and retain `anonymized=false`. `bases` is consumer-vocabulary metadata — OpenRegister stores the UUIDs verbatim and does not validate that they resolve. Writes are audit-logged; the PATCH endpoint inherits the relation's parent file/object write-access check.

## ADDED Requirements

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

The mapper MUST expose `updateDecisionMetadata(int $id, array $fields, ?IUser $actingUser = null): EntityRelation`. The method MUST:

1. Resolve the row by `$id`; throw `DoesNotExistException` if missing.
2. Enforce the whitelist (`bases`, `skipAnonymization`); throw a typed validation exception for any unknown field name.
3. Validate the shape of each present field (`bases` MUST be `null` or an array of strings; `skipAnonymization` MUST be a boolean); throw a typed validation exception on shape mismatch.
4. Compute the diff against the current row state.
5. If the diff is empty (no field actually changes value), the method MUST return the row unmodified and MUST NOT write an audit entry.
6. Otherwise, apply the changed fields, persist via the underlying QBMapper, AND write an audit-trail entry — both MUST be committed transactionally (or use the same failure-handling mode the existing audit-traced mapper writes use today).

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

For each whitelist field, the body shapes behave as follows:

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

## Notes

### Consumer-owned vocabulary

The `bases` array contains UUID-shaped strings whose meaning is defined by the consumer app, not by OpenRegister. OpenRegister persists the array verbatim and never resolves the UUIDs.

For DocuDesk-driven anonymisation — the first consumer of this capability — the UUIDs SHOULD resolve to objects in the `base` register defined by DocuDesk's `add-dossier-schema` change. That schema seeds six canonical Woo Art. 5 *uitzonderingsgronden*:

| Slug | Woo Art. 5 reference | Description (NL, excerpt) |
|---|---|---|
| `persoonsgegevens` | Art. 5.1 Woo jo. AVG Art. 4 lid 1 | Herleidbare gegevens van een natuurlijke persoon. |
| `bijzondere-persoonsgegevens` | Art. 5.1 Woo jo. AVG Art. 9 | Bijzondere persoonsgegevens (medisch, religieus, etc.). |
| `strafrechtelijk` | Art. 5.1 Woo jo. AVG Art. 10 | Strafrechtelijke gegevens. |
| `bedrijfs-fabricagegegevens` | Art. 5.1 sub c Woo | Vertrouwelijke bedrijfs- en fabricagegegevens. |
| `onevenredige-benadeling` | Art. 5.2 Woo | Onevenredige benadeling van betrokkenen of derden. |
| `nationale-veiligheid` | Art. 5.1 sub a/b Woo | Nationale veiligheid / opsporing. |

Other consumers MAY define their own `base` vocabulary; OpenRegister does not enforce a single registry. Consumers MUST handle the case where a stored `bases` UUID does not resolve to a known `base` object (e.g. by surfacing it as "onbekende grondslag" or filtering it from rendered summaries) — this graceful-degradation behaviour is the consumer's responsibility, not OpenRegister's.

### How this change fits the broader anonymise flow

This change adds a **decision-time** write path (the PATCH endpoint) and a **filter** on the existing anonymise-time path (the skip predicate). Detection time (`EntityRecognitionHandler::detectEntities*`) is unchanged. `DocumentProcessingHandler::anonymizeDocument` is unchanged in its text-replacement logic; the change is in *which* entities reach it (the skip filter excludes some).

### Per-relation granularity

Each `EntityRelation` row is one detected occurrence at one offset. The PATCH endpoint operates per-row, so operators CAN express different decisions for different occurrences of the same entity within the same file — for example, set `skipAnonymization=true` at position 100 while leaving position 250 unflagged. Whether any consumer UI surfaces that fine-grained capability is a separate question. DocuDesk's review UI typically aggregates at entity level for ergonomic reasons (one decision per `(fileId, entityId)`, written to all matching relations); other consumers can use the finer granularity if they need it.

### Decision-vs-state separation

The PATCH whitelist (`bases`, `skipAnonymization`) is intentionally **decision-only**. The post-hoc system fields `anonymized` and `anonymizedValue` record what the redaction code path actually did; they are written by `markAsAnonymized` (or future system-level redaction paths) and MUST NEVER be writable by an operator. Letting operators flip those would manufacture false audit history (claim a redaction without one having happened) and break the audit-trail invariant "`anonymized=true` ⟹ the redaction code ran for this row".