---
status: draft
---

# Entity Relation Grondslagen

## Purpose

Defines an optional `bases` link on `EntityRelation` and the contract by which the anonymise endpoint accepts, persists, and strips per-entity legal bases (grondslagen). The link is consumer-app-agnostic — OpenRegister stores the array of UUIDs verbatim and does not validate that they resolve to real `base` objects (the vocabulary lives in the consumer app).

## ADDED Requirements

### Requirement: `EntityRelation` MUST gain an optional `bases` JSON column

The `oc_openregister_entity_relations` table MUST gain a column named `bases` of type JSON (or the platform-equivalent JSON column type), nullable, with default `NULL`. The column MUST hold either `NULL`, an empty array `[]`, or an array of strings (UUIDs). No JSON-schema validation of the array contents is enforced at the database or mapper layer.

The `EntityRelation` PHP entity (`lib/Db/EntityRelation.php`) MUST expose `getBases(): ?array` and `setBases(?array $bases): void`, registered via `addType('bases', 'json')` in the constructor. Existing rows (pre-migration) MUST read as `bases = null` without errors.

#### Scenario: Migration adds the column without disturbing existing rows

- **GIVEN** an OpenRegister install with existing `EntityRelation` rows
- **WHEN** the migration is applied
- **THEN** the `bases` column is added to `oc_openregister_entity_relations`
- **AND** every existing row reads with `bases = null` via `EntityRelation::getBases()`
- **AND** no other columns or rows are modified

#### Scenario: Migration is idempotent

- **GIVEN** the migration has already been applied
- **WHEN** the migration runs again (e.g. on upgrade after a previous deploy)
- **THEN** the migration is a no-op
- **AND** no error is raised

#### Scenario: Mapper reads and writes bases

- **GIVEN** an `EntityRelation` row with `bases = ["uuid-a", "uuid-b"]` written via the mapper
- **WHEN** the row is read back via `EntityRelationMapper::find($id)`
- **THEN** `getBases()` returns the array `["uuid-a", "uuid-b"]`
- **AND** `jsonSerialize()` includes `bases` in its output

#### Scenario: Empty array is accepted and distinct from null

- **WHEN** `setBases([])` is called and the row is persisted
- **THEN** subsequent reads return `bases = []` (an empty array, not null)
- **AND** `jsonSerialize()['bases']` is `[]`

#### Scenario: Non-UUID strings are accepted by the mapper layer

- **WHEN** `setBases(["not-a-uuid", "12345"])` is called and persisted
- **THEN** the call succeeds (the mapper does not validate UUID format)
- **AND** the values are returned verbatim on read

### Requirement: The anonymise endpoint MUST accept per-entity `bases` in the request payload

The anonymise endpoint (currently `FileService::anonymizeDocument(node, payload)` and the controller routes that wrap it) MUST accept an optional `bases` field on each entry in `payload.entities[]`. The field MUST be either absent, `null`, or an array of strings. The field's presence MUST NOT change any existing behaviour for callers that omit it.

#### Scenario: Anonymise call without bases preserves today's behaviour

- **GIVEN** a request payload with entities that have no `bases` field
- **WHEN** the anonymise endpoint processes the request
- **THEN** the matching `EntityRelation` rows are written with `bases = null`
- **AND** the request forwarded to OpenAnonymiser contains exactly the same fields it would have contained before this change

#### Scenario: Anonymise call with bases populates EntityRelation

- **GIVEN** a request payload with `entities: [{entityId: 42, bases: ["uuid-a"]}, {entityId: 43, bases: ["uuid-b", "uuid-c"]}]`
- **WHEN** the anonymise endpoint processes the request
- **THEN** the EntityRelation row for entity 42 has `bases = ["uuid-a"]`
- **AND** the EntityRelation row for entity 43 has `bases = ["uuid-b", "uuid-c"]`

#### Scenario: Bases field with empty array writes empty array

- **GIVEN** a payload entry with `bases: []`
- **WHEN** the anonymise endpoint processes it
- **THEN** the matching EntityRelation row has `bases = []` (empty array, not null)

### Requirement: The anonymise endpoint MUST persist bases BEFORE forwarding to OpenAnonymiser

The order of operations in the anonymise endpoint MUST be: (1) for each entry, find or upsert the `EntityRelation` row writing the existing fields (`anonymized`, `anonymizedValue`, etc.) plus `bases`; then (2) construct the request to OpenAnonymiser with `bases` stripped from each entry; then (3) forward to OpenAnonymiser. Persistence MUST NOT be conditional on the OpenAnonymiser call succeeding.

#### Scenario: Persist precedes the OpenAnonymiser call

- **GIVEN** a successful anonymise request with bases populated
- **WHEN** the endpoint runs
- **THEN** the EntityRelation rows are written before the HTTP call to OpenAnonymiser is issued
- **AND** the persist write includes the bases values

#### Scenario: Persist survives an OpenAnonymiser failure

- **GIVEN** a request whose OpenAnonymiser call fails (network timeout, 500 response)
- **WHEN** the endpoint processes the request
- **THEN** the EntityRelation rows have been written with `bases` populated
- **AND** the rows have `anonymized = false` (or whatever the existing pre-change semantics dictate for failed redaction)
- **AND** a retry of the same request succeeds in transitioning the rows to `anonymized = true` without re-prompting the caller for `bases`

### Requirement: The anonymise endpoint MUST strip `bases` from the payload before forwarding to OpenAnonymiser

Before issuing the HTTP call to OpenAnonymiser, the service MUST construct a copy of the entity list with the `bases` field removed from every entry. The OpenAnonymiser request body MUST be byte-equivalent to what it would have been before this change (modulo any other unrelated field reorderings introduced by the JSON encoder).

#### Scenario: OpenAnonymiser sees no `bases` field

- **GIVEN** a request payload with bases populated on every entry
- **WHEN** the anonymise endpoint forwards to OpenAnonymiser
- **THEN** the request body to OpenAnonymiser contains no `bases` field on any entity entry
- **AND** all other fields (`text`, `entityType`, `score`, etc.) are forwarded unchanged

#### Scenario: Mixed payload — some entries with bases, some without

- **GIVEN** a payload where entries 1, 3 have `bases` populated and entry 2 has none
- **WHEN** the endpoint forwards to OpenAnonymiser
- **THEN** the forwarded request has no `bases` field on any of the three entries
- **AND** entries 1, 3 still have their EntityRelation rows updated with the supplied bases

### Requirement: OpenRegister MUST NOT validate that `bases` UUIDs resolve

The persistence layer accepts any string array. OpenRegister MUST NOT issue any cross-register lookup to verify that the supplied UUIDs correspond to actual objects in any register. The vocabulary that bases reference is owned by the consumer app.

#### Scenario: Unknown UUID strings are accepted

- **GIVEN** a payload with `bases: ["00000000-0000-0000-0000-000000000000"]` (UUID that doesn't resolve)
- **WHEN** the endpoint processes the request
- **THEN** the row is persisted with the value verbatim
- **AND** no error is raised
- **AND** no cross-register query is issued

#### Scenario: Garbage strings are accepted

- **GIVEN** a payload with `bases: ["not-even-a-uuid", ""]`
- **WHEN** the endpoint processes the request
- **THEN** the row is persisted with the values verbatim

### Requirement: The change MUST be additive and non-breaking

Existing callers (and the OpenAnonymiser contract) MUST be unaffected when no `bases` field is present in any entry of the payload. No existing field is removed, renamed, or repurposed. No existing scenario in any other capability is invalidated.

#### Scenario: Pre-change client continues to work

- **GIVEN** a client that constructs anonymise payloads using the pre-change schema (no `bases` field anywhere)
- **WHEN** that client sends a request to the anonymise endpoint
- **THEN** the request succeeds with identical behaviour to before this change
- **AND** the resulting EntityRelation rows have `bases = null`
- **AND** the OpenAnonymiser request body is identical to what it would have been pre-change
