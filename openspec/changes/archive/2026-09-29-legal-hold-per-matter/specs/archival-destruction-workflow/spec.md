# archival-destruction-workflow

## ADDED Requirements

### Requirement: Each matter holds an object with its own legal hold

An object SHALL carry one legal hold per matter in `retention.legalHold.holds`,
each with an `id`, an `ownerKey`, a `reason`, `placedBy` and `placedDate`.
Placing a hold with an owner key that already holds the object SHALL update
that hold's reason and SHALL NOT add a second one. Releasing a hold with an
owner key SHALL lift only that owner's hold and move it to `history`; a release
naming no owner SHALL lift every hold. `retention.legalHold.active` SHALL be
true while any hold is in the list. A stored hold without a `holds` list SHALL
be read as one hold owned by `openregister:manual`.

#### Scenario: releasing one matter keeps the other hold

- **GIVEN** an object held by a lawsuit and by an audit, each with its own owner key
- **WHEN** the audit's hold is released with its owner key
- **THEN** the object MUST still have an active legal hold with the lawsuit's reason
- **AND** `history` MUST hold exactly the released audit hold with its release reason
- @e2e exclude covered by LegalHoldPerMatterTest (real LegalHoldService over a real ObjectEntity)

#### Scenario: a stored single-slot hold stays valid

- **GIVEN** an object whose stored `legalHold` is a single active slot with no `holds` list
- **WHEN** a matter places and then releases its own hold
- **THEN** the stored hold MUST still be active with its original reason
- **AND** a release naming no owner MUST lift it
- @e2e exclude covered by LegalHoldPerMatterTest
