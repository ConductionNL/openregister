---
status: draft
---

# Entity Relation Grondslagen

## Purpose

Defines an optional `bases` link on `EntityRelation` and the contract by which the anonymise endpoint accepts, persists, and strips per-entity legal bases (grondslagen). The link is consumer-app-agnostic — OpenRegister stores the array of UUIDs verbatim and does not validate that they resolve to real `base` objects (the vocabulary lives in the consumer app). Writes are audit-logged and inherit the anonymise endpoint's existing authorization model.

## ADDED Requirements

### Requirement: `EntityRelation` MUST gain an optional `bases` JSON column

The `oc_openregister_entity_relations` table MUST gain a column named `bases` of type JSON (or the platform-equivalent JSON column type), nullable, with default `NULL`. The column MUST hold either `NULL`, an empty array `[]`, or an array of strings (UUIDs). No JSON-schema validation of the array contents is enforced at the database or mapper layer.

The `EntityRelation` PHP entity (`lib/Db/EntityRelation.php`) MUST expose `getBases(): ?array` and `setBases(?array $bases): void`, registered via `addType('bases', 'json')` in the constructor. Existing rows (pre-migration) MUST read as `bases = null` without errors.

#### Scenario: Migration adds the column without disturbing existing rows

- **GIVEN** an OpenRegister install with existing `EntityRelation` rows
- **WHEN** the migration is applied
- **THEN** the `bases` column MUST be added to `oc_openregister_entity_relations`
- **AND** every existing row MUST read with `bases = null` via `EntityRelation::getBases()`
- **AND** no other columns or rows MUST be modified

#### Scenario: Migration is idempotent

- **GIVEN** the migration has already been applied
- **WHEN** the migration runs again (e.g. on upgrade after a previous deploy)
- **THEN** the migration MUST be a no-op
- **AND** no error MUST be raised

#### Scenario: Mapper reads and writes bases

- **GIVEN** an `EntityRelation` row with `bases = ["uuid-a", "uuid-b"]` written via the mapper
- **WHEN** the row is read back via `EntityRelationMapper::find($id)`
- **THEN** `getBases()` MUST return the array `["uuid-a", "uuid-b"]`
- **AND** `jsonSerialize()` MUST include `bases` in its output

#### Scenario: Empty array is accepted and distinct from null

- **WHEN** `setBases([])` is called and the row is persisted
- **THEN** subsequent reads MUST return `bases = []` (an empty array, not null)
- **AND** `jsonSerialize()['bases']` MUST be `[]`

### Requirement: The anonymise endpoint MUST accept per-entity `bases` in the request payload

The anonymise endpoint (currently `FileService::anonymizeDocument(node, payload)` and the controller routes that wrap it) MUST accept an optional `bases` field on each entry in `payload.entities[]`. The field MUST be either absent, `null`, or an array of strings (see also the endpoint-shape-validation Requirement below). The field's presence MUST NOT change any existing behaviour for callers that omit it.

#### Scenario: Anonymise call without bases preserves today's behaviour

- **GIVEN** a request payload with entities that have no `bases` field
- **WHEN** the anonymise endpoint processes the request
- **THEN** the matching `EntityRelation` rows MUST be written with `bases = null`
- **AND** the request forwarded to OpenAnonymiser MUST contain exactly the same fields it would have contained before this change

#### Scenario: Anonymise call with bases populates EntityRelation

- **GIVEN** a request payload with `entities: [{entityId: 42, bases: ["uuid-a"]}, {entityId: 43, bases: ["uuid-b", "uuid-c"]}]`
- **WHEN** the anonymise endpoint processes the request
- **THEN** the EntityRelation row for entity 42 MUST have `bases = ["uuid-a"]`
- **AND** the EntityRelation row for entity 43 MUST have `bases = ["uuid-b", "uuid-c"]`

#### Scenario: Bases field with empty array writes empty array

- **GIVEN** a payload entry with `bases: []`
- **WHEN** the anonymise endpoint processes it
- **THEN** the matching EntityRelation row MUST have `bases = []` (empty array, not null)

### Requirement: The anonymise endpoint MUST validate the SHAPE of `bases` at the entry point but MUST NOT validate its CONTENT

At the endpoint layer (controller / `FileService::anonymizeDocument`), the `bases` field on each entry of `payload.entities[]` MUST be one of:

- absent,
- `null`,
- an array whose every element is a string.

Any other shape — a non-array value (e.g. a number, an object, a single string), or an array containing non-string elements — MUST be rejected with an HTTP 400 response. The 400 response body MUST identify the offending entry by index so the caller can fix the right entry in a multi-entity payload.

At the mapper layer, no further validation MUST be applied: the elements of the `bases` array MUST be persisted verbatim regardless of their content (UUID-shaped, garbage strings, or empty strings). This two-layer contract exists deliberately — endpoint validation rejects ill-typed input early, while the mapper remains content-agnostic so the consumer-app's vocabulary can evolve without OR changes.

#### Scenario: Endpoint rejects `bases` as a non-array

- **GIVEN** a payload entry with `bases: "uuid-a"` (a string, not an array)
- **WHEN** the anonymise endpoint processes the request
- **THEN** the response MUST be HTTP 400
- **AND** no EntityRelation row MUST be modified

#### Scenario: Endpoint rejects array elements that are not strings

- **GIVEN** a payload entry at index 1 with `bases: ["uuid-a", 42]`
- **WHEN** the anonymise endpoint processes the request
- **THEN** the response MUST be HTTP 400
- **AND** the error body MUST identify entity index `1` as the offending entry

#### Scenario: Mapper accepts any string content

- **GIVEN** a validated payload with `bases: ["not-a-uuid", "12345", ""]`
- **WHEN** the endpoint forwards the payload to the mapper layer
- **THEN** the row MUST be persisted with the values verbatim
- **AND** no error MUST be raised

### Requirement: `bases` writes MUST inherit the anonymise endpoint's existing authorization (ADR-005 / ADR-023)

The anonymise endpoint already enforces a per-object authorization check (the caller MUST have write access to the file/object being anonymised). Setting `bases` MUST require no additional authorization beyond that check — `bases` is metadata attached to an existing anonymisation operation that the caller is already authorized to perform. There MUST be no separate group, role, or action-level permission for `bases` writes in this change.

A caller who cannot anonymise a given object MUST NOT be able to set `bases` on its EntityRelation rows, because the persist step (next Requirement) runs only after the endpoint's existing authorization has passed. A caller who CAN anonymise the object MUST be able to set arbitrary `bases` strings as part of that operation.

This decision is recorded explicitly so reviewers and implementers can reason about the authorization surface: there is intentionally NO extra check, and that absence is the intended contract — not an oversight. If a future change introduces a separate action-level permission for `bases` (per ADR-023), it MUST add a new Requirement here.

#### Scenario: Unauthorized caller cannot anonymise and therefore cannot set bases

- **GIVEN** a user without write access to file `F`
- **WHEN** the user POSTs an anonymise request for `F` with `bases` populated
- **THEN** the existing endpoint authorization check MUST fail and the request MUST be rejected (HTTP 403)
- **AND** no EntityRelation row for `F` MUST be modified
- **AND** no bases value MUST be persisted

#### Scenario: Authorized caller can set bases as part of anonymisation

- **GIVEN** a user with write access to file `F`
- **WHEN** the user POSTs an anonymise request for `F` with `bases: ["uuid-a"]`
- **THEN** the request MUST succeed
- **AND** the EntityRelation row's `bases` value MUST be `["uuid-a"]`

### Requirement: The anonymise endpoint MUST persist bases BEFORE forwarding to OpenAnonymiser

The order of operations in the anonymise endpoint MUST be: (1) for each entry, find or upsert the `EntityRelation` row writing the existing fields (`anonymized`, `anonymizedValue`, etc.) plus `bases`; then (2) construct the request to OpenAnonymiser with `bases` stripped from each entry; then (3) forward to OpenAnonymiser. Persistence MUST NOT be conditional on the OpenAnonymiser call succeeding.

#### Scenario: Persist precedes the OpenAnonymiser call

- **GIVEN** a successful anonymise request with bases populated
- **WHEN** the endpoint runs
- **THEN** the EntityRelation rows MUST be written before the HTTP call to OpenAnonymiser is issued
- **AND** the persist write MUST include the bases values

#### Scenario: Persist survives an OpenAnonymiser failure

- **GIVEN** a request whose OpenAnonymiser call fails (network timeout, 500 response)
- **WHEN** the endpoint processes the request
- **THEN** the EntityRelation rows MUST have been written with `bases` populated
- **AND** the rows MUST have `anonymized = false` (or the existing pre-change semantics for failed redaction)

### Requirement: `bases` writes MUST be recorded in OpenRegister's audit trail (ADR-022 / Woo compliance)

Every mutation of an `EntityRelation` row that sets or changes the `bases` value MUST produce an entry in the OpenRegister audit trail. The audit entry MUST include the actor (Nextcloud user UID — per ADR-005, never the display name), the timestamp, the row's stable identifier, and both the previous and new `bases` values. Reads of `EntityRelation` rows MUST NOT produce audit entries.

The audit entry MUST be written through OpenRegister's existing immutable-audit-trail subsystem (the same one that records other `EntityRelation` mutations), not by direct mapper writes that bypass audit. This is the load-bearing compliance requirement of the change — without it, a Woo officer cannot reconstruct which grondslag justified a given redaction, defeating the feature's stated purpose.

#### Scenario: Setting bases for the first time produces an audit entry

- **GIVEN** a request that sets `bases: ["uuid-a"]` on a new EntityRelation row (no prior value)
- **WHEN** the row is persisted
- **THEN** an audit-trail entry MUST exist for that row referencing the action (e.g. `entity_relation_bases_set` or OR's equivalent), the acting user's UID, an ISO-8601 timestamp, the row's identifier, `previousBases: null`, and `newBases: ["uuid-a"]`

#### Scenario: Updating bases produces an audit entry with old + new values

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]`
- **WHEN** a subsequent anonymise call sets `bases: ["uuid-a", "uuid-b"]`
- **THEN** an audit-trail entry MUST be written with `previousBases: ["uuid-a"]` and `newBases: ["uuid-a", "uuid-b"]`

#### Scenario: Reads do not produce audit entries

- **WHEN** an `EntityRelation` row with non-null `bases` is read via `EntityRelationMapper::find` or `findEntitiesForFile`
- **THEN** no audit-trail entry MUST be produced for the read

### Requirement: A retry that omits `bases` MUST reuse the persisted values

When the anonymise endpoint receives a retry request where `bases` is absent from one or more entries that previously had `bases` persisted (i.e. the EntityRelation row already exists with non-null `bases`), the endpoint MUST NOT overwrite the persisted values. The retry MUST proceed with the OpenAnonymiser call using the persisted `bases` for downstream behaviour (audit trail, consumer-app summary rendering), without requiring the caller to resupply `bases`.

A retry that DOES include `bases` MUST overwrite the persisted values; the resulting audit entry MUST record the previous-vs-new transition per the audit-trail Requirement. The endpoint MUST distinguish three caller intents:

- field **absent** → reuse persisted value (no audit entry for `bases` field)
- field **present and `null`** → set to `null` (explicit clear, audit-logged)
- field **present and `[]`** → set to `[]` (explicit empty, audit-logged)

This contract lets DocuDesk's `anonymisation-grondslagen-and-prohibition-gate` issue retries after a gate-fail without re-prompting the operator for grondslagen.

#### Scenario: Retry without `bases` preserves the persisted value

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]` and `anonymized: false` (previous OpenAnonymiser call failed)
- **WHEN** a retry request arrives for the same entity with no `bases` field in the payload
- **THEN** the EntityRelation row's `bases` MUST remain `["uuid-a"]` (unchanged)
- **AND** the retry MUST proceed with the OpenAnonymiser call using the persisted bases
- **AND** on success the row MUST transition to `anonymized: true` with `bases` unchanged
- **AND** no audit-trail entry MUST be produced specifically for the `bases` field (other audit entries for the anonymised-flag transition follow existing OR semantics and are out of scope of this Requirement)

#### Scenario: Retry with new `bases` overwrites the persisted value

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]`
- **WHEN** a retry request arrives for the same entity with `bases: ["uuid-b"]`
- **THEN** the EntityRelation row's `bases` MUST be updated to `["uuid-b"]`
- **AND** an audit-trail entry MUST record the transition (`previousBases: ["uuid-a"]`, `newBases: ["uuid-b"]`)

#### Scenario: Retry with explicit `bases: null` clears the persisted value

- **GIVEN** an EntityRelation row with `bases: ["uuid-a"]`
- **WHEN** a retry request arrives with `bases: null`
- **THEN** the EntityRelation row's `bases` MUST be set to `null`
- **AND** the transition MUST be audit-logged (`previousBases: ["uuid-a"]`, `newBases: null`)

### Requirement: The anonymise endpoint MUST strip `bases` from the payload before forwarding to OpenAnonymiser

Before issuing the HTTP call to OpenAnonymiser, the service MUST construct a copy of the entity list with the `bases` field removed from every entry. The OpenAnonymiser request body MUST be byte-equivalent to what it would have been before this change (modulo any other unrelated field reorderings introduced by the JSON encoder).

#### Scenario: OpenAnonymiser sees no `bases` field

- **GIVEN** a request payload with bases populated on every entry
- **WHEN** the anonymise endpoint forwards to OpenAnonymiser
- **THEN** the request body to OpenAnonymiser MUST contain no `bases` field on any entity entry
- **AND** all other fields (`text`, `entityType`, `score`, etc.) MUST be forwarded unchanged

#### Scenario: Mixed payload — some entries with bases, some without

- **GIVEN** a payload where entries 1, 3 have `bases` populated and entry 2 has none
- **WHEN** the endpoint forwards to OpenAnonymiser
- **THEN** the forwarded request MUST have no `bases` field on any of the three entries
- **AND** entries 1, 3 MUST still have their EntityRelation rows updated with the supplied bases

### Requirement: OpenRegister MUST NOT validate that `bases` UUIDs resolve

The persistence layer accepts any string array. OpenRegister MUST NOT issue any cross-register lookup to verify that the supplied UUIDs correspond to actual objects in any register. The vocabulary that bases reference is owned by the consumer app (see Notes for the canonical DocuDesk `base` vocabulary).

#### Scenario: Unknown UUID strings are accepted

- **GIVEN** a payload with `bases: ["00000000-0000-0000-0000-000000000000"]` (a UUID that doesn't resolve to any object)
- **WHEN** the endpoint processes the request
- **THEN** the row MUST be persisted with the value verbatim
- **AND** no error MUST be raised
- **AND** no cross-register query MUST be issued

### Requirement: The change MUST be additive and non-breaking

Existing callers (and the OpenAnonymiser contract) MUST be unaffected when no `bases` field is present in any entry of the payload. No existing field is removed, renamed, or repurposed. No existing scenario in any other capability MUST be invalidated.

#### Scenario: Pre-change client continues to work

- **GIVEN** a client that constructs anonymise payloads using the pre-change schema (no `bases` field anywhere)
- **WHEN** that client sends a request to the anonymise endpoint
- **THEN** the request MUST succeed with identical behaviour to before this change
- **AND** the resulting EntityRelation rows MUST have `bases = null`
- **AND** the OpenAnonymiser request body MUST be identical to what it would have been pre-change

## Notes

### Consumer-owned vocabulary

The `bases` array contains UUID-shaped strings whose meaning is defined by the consumer app, not by OpenRegister. OpenRegister persists the array verbatim and never resolves the UUIDs (see Requirement: *OpenRegister MUST NOT validate that bases UUIDs resolve*).

For DocuDesk-driven anonymisation — the first consumer of this capability — the UUIDs SHOULD resolve to objects in the `base` register defined by DocuDesk's [`add-dossier-schema`](https://github.com/ConductionNL/docudesk/pull/135) change. That schema seeds six canonical Woo Art. 5 *uitzonderingsgronden*:

| Slug | Woo Art. 5 reference | Description (NL, excerpt) |
|---|---|---|
| `persoonsgegevens` | Art. 5.1 Woo jo. AVG Art. 4 lid 1 | Herleidbare gegevens van een natuurlijke persoon. |
| `bijzondere-persoonsgegevens` | Art. 5.1 Woo jo. AVG Art. 9 | Bijzondere persoonsgegevens (medisch, religieus, etc.). |
| `strafrechtelijk` | Art. 5.1 Woo jo. AVG Art. 10 | Strafrechtelijke gegevens. |
| `bedrijfs-fabricagegegevens` | Art. 5.1 sub c Woo | Vertrouwelijke bedrijfs- en fabricagegegevens. |
| `onevenredige-benadeling` | Art. 5.2 Woo | Onevenredige benadeling van betrokkenen of derden. |
| `nationale-veiligheid` | Art. 5.1 sub a/b Woo | Nationale veiligheid / opsporing. |

Other consumers MAY define their own `base` vocabulary; OpenRegister does not enforce a single registry. Consumers MUST handle the case where a stored `bases` UUID does not resolve to a known `base` object (e.g. by surfacing it as "onbekende grondslag" or filtering it from rendered summaries) — this graceful-degradation behaviour is the consumer's responsibility, not OpenRegister's.