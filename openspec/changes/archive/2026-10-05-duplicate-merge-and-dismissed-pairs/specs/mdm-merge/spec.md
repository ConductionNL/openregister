# mdm-merge

## ADDED Requirements

### Requirement: A merge preview offers the choice per property, and execution applies the choice (REQ-DMD-001)

`previewMerge(from, into)` SHALL return, for every property the two objects
declare, the value held by each and the value `SurvivorshipResolver`
proposes. `executeMerge()` SHALL accept a decision map naming, per property,
which of the two values survives, and SHALL apply exactly that map. A
decision map that names a property absent from the preview, or that omits a
property the preview offered, SHALL be refused with HTTP 422 naming the
property, and nothing SHALL be written. When no decision map is supplied the
resolver's proposal SHALL apply, so the existing behaviour is unchanged.

#### Scenario: the reviewer keeps the older address and the newer phone number

- **GIVEN** two party records, one with the correct address and the other with the correct phone number
- **WHEN** the merge is previewed and executed with a decision map naming the first for `address` and the second for `phone`
- **THEN** the survivor holds that address and that phone number
- @e2e exclude {service-level merge, covered by MergeService unit tests}

#### Scenario: a decision map that does not match the preview is refused

- **GIVEN** a preview offering the properties `address`, `phone` and `email`
- **WHEN** execution is requested with a map naming only `address`
- **THEN** the merge is refused with HTTP 422 naming the omitted properties
- **AND** neither object is changed

#### Scenario: no map behaves exactly as today

- **GIVEN** two readable objects and a merge requested with no decision map
- **WHEN** it executes
- **THEN** the survivor is the one `SurvivorshipResolver` computes, unchanged from before this change

### Requirement: A merge is refused when the merger cannot read everything being merged (REQ-DMD-002)

A merge SHALL be refused when either object carries a property the caller
may not read under field-level security. The refusal SHALL name the
properties, SHALL NOT disclose their values, and SHALL be recorded on the
audit trail of both objects as an attempted merge.

#### Scenario: a handler without the medical domain cannot merge two clients

- **GIVEN** a party record carrying a property restricted to a group the caller is not in
- **WHEN** the caller requests a merge of that record with another
- **THEN** the merge is refused naming the property and not its value
- **AND** both objects carry an audit entry for the attempt
