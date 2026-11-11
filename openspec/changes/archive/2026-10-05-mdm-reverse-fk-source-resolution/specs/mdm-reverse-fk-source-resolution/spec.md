## ADDED Requirements

### Requirement: Optional `sourceLink` block selects the source-resolution mode

The `x-openregister-survivorship` and `x-openregister-merge` annotations SHALL
accept an optional `sourceLink` object that selects how a master object's
competing source records are resolved. `sourceLink.mode` SHALL be one of:

- `"embedded"` (the default when `sourceLink` is absent) — sources are read
  from the master payload's `sourceLinkField` as embedded records and/or uuid
  reference strings, exactly as before this change.
- `"reverseFk"` — sources are separate objects that reference the master. In
  this mode `sourceLink` SHALL also declare `sourceSchema` (the slug or id of
  the source schema) and `referenceField` (the field on a source object holding
  the master's UUID). `sourceRegister` is optional and defaults to the master's
  own register.

An annotation with no `sourceLink` block MUST behave exactly as it did before
this change (embedded mode), so every existing survivorship/merge schema is
unaffected.

#### Scenario: Absent `sourceLink` defaults to embedded mode
- **WHEN** a schema's survivorship/merge annotation has no `sourceLink` block
- **THEN** sources MUST be resolved from the master payload's `sourceLinkField` (embedded records + uuid references), identical to pre-change behaviour

#### Scenario: reverseFk mode is shape-validated
- **WHEN** a schema declares `sourceLink.mode = "reverseFk"` without `sourceSchema` or without `referenceField`
- **THEN** the schema import MUST still succeed
- **AND** the invalid `sourceLink` MUST be ignored with a logged warning and the engine MUST fall back to embedded mode, consistent with the non-fatal degradation of a malformed survivorship annotation

### Requirement: Reverse-FK source records are resolved by query

OpenRegister SHALL resolve a reverse-FK master's competing source records by
query. When a master's annotation selects the reverseFk `sourceLink` mode, the
engine MUST query the configured `sourceSchema` (within `sourceRegister`,
defaulting to the master's register) for objects whose `referenceField` equals
the master's UUID. The resolved
objects' payloads SHALL be passed to `SurvivorshipResolver` as the source-record
array (the resolver already reads each source's `values`/`mappedAttributes`).
Resolution MUST be RBAC- and multitenancy-scoped like every other object read.
A master with no linked sources MUST resolve to an empty source set (an empty
golden record), never an error.

#### Scenario: Golden record is projected from reverse-FK sources
- **GIVEN** a `masterEntity` object and two `sourceRecord` objects whose `referenceField` holds that master's UUID, each supplying competing attribute values at different trust tiers
- **WHEN** the master's golden record is recomputed (on save, on merge preview, or via the conflict view)
- **THEN** the golden record MUST contain the winning value per attribute resolved across those two source objects
- **AND** the attribute provenance MUST cite the winning source's `sourceSystem` and trust tier

#### Scenario: Master with no linked sources yields an empty golden record
- **WHEN** a reverse-FK master has no source objects referencing it
- **THEN** recompute MUST return an empty golden record without error

### Requirement: Reverse-FK merge relinks source back-references

For a reverse-FK schema, merging a losing master into a surviving master SHALL
rewrite each of the losing master's source objects' `referenceField` to the
surviving master's UUID (a persisted write per source object), rather than
merging an embedded array on the survivor payload. The survivor's golden record
SHALL then be recomputed over the union of both masters' now-commonly-referenced
source objects. The merge snapshot SHALL record each moved source object's uuid
and its prior `referenceField` value so the reversal can restore it.

#### Scenario: Merge moves the loser's sources onto the survivor
- **GIVEN** a reverse-FK losing master L with sources S1, S2 and a surviving master W with source S3
- **WHEN** L is merged into W
- **THEN** S1 and S2's `referenceField` MUST be updated to W's UUID
- **AND** W's recomputed golden record MUST reflect the union {S1, S2, S3}

#### Scenario: Reversal restores the moved back-references
- **WHEN** a reverse-FK merge is reversed within its reversal window
- **THEN** each moved source object's `referenceField` MUST be restored to its pre-merge master UUID
- **AND** both masters' golden records MUST be recomputed to their pre-merge projection

### Requirement: Source-object changes recompute the linked master

For a reverse-FK relationship, saving or deleting a source object SHALL trigger
recomputation and rematerialisation of the golden record of the master that the
source references (via `referenceField`). This keeps the master current as its
sources change, without the steward editing the master directly. The trigger
MUST be resilient: a recompute failure for one master MUST be logged and MUST
NOT abort the source object's own save/delete.

#### Scenario: Editing a source refreshes its master's golden record
- **GIVEN** a reverse-FK master whose golden record currently reflects source S
- **WHEN** S's mapped attributes are changed and S is saved
- **THEN** the referenced master's golden record MUST be recomputed to reflect S's new values

#### Scenario: A recompute failure does not block the source save
- **WHEN** the post-save master recompute throws (e.g. the referenced master no longer exists)
- **THEN** the source object's save MUST still succeed
- **AND** the failure MUST be logged, not surfaced as a request error
