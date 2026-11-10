# Delta: referential-integrity

## ADDED Requirements

### Requirement 16: A reference that cites a row by a key property MUST be able to refuse its delete
A schema property whose values cite rows of another schema by a key property (a code, not a uuid) MUST be able to declare `referenceSchema` (the cited schema, on the property or on `items`), `referenceKey` (the cited schema's property, e.g. `code`) and `onDelete: RESTRICT`. Deleting a cited row MUST then be refused with the citing rows as blockers, exactly as for a uuid reference. `referenceSchema` MUST NOT engage the `$ref` relation behaviour (rendering, `_extend`, uuid validation). A key reference MUST be honoured only with `RESTRICT`: saving a schema that declares `referenceKey` with any other action MUST be refused, and such a relation that reaches the relation index anyway MUST be left out with a warning. `onDelete` MUST be accepted on a property with `referenceSchema` as it is on one with `$ref`. The check MUST fail closed: when the citing rows cannot be read, the delete MUST be refused with a blocker whose `reason` is `unverifiable`; only a citing table that does not exist yet MUST count as "nothing cites it". A row without a value for the key MUST be deletable. This is the generic reference guard (capability C25 of hydra's `procedures-are-configuration`), so an app needs no delete listener of its own.

#### Scenario: A cited code refuses the delete
- **GIVEN** schema `note` with property `labels` = `{type: array, items: {type: string}, referenceSchema: label, referenceKey: code, onDelete: RESTRICT}`
- **AND** a note whose `labels` holds `A1`
- **WHEN** the `label` row with `code` `A1` is deleted
- **THEN** the delete MUST be refused with `DELETION_BLOCKED`, naming the note as blocker
- **AND** the citing table MUST be searched for `A1`, not for the label's uuid

#### Scenario: An unreadable citation refuses the delete
- **GIVEN** the same schemas, and the citing table exists but cannot be read
- **WHEN** the `label` row is deleted
- **THEN** the delete MUST be refused with a blocker whose `reason` is `unverifiable`

#### Scenario: A key reference does not cascade
- **GIVEN** a key reference declared with `onDelete: CASCADE`
- **WHEN** the relation index is built
- **THEN** the relation MUST be left out and a warning logged

