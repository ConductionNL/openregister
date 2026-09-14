# referential-integrity

## ADDED Requirements

### Requirement: A reference property declares how it reads from both sides

A `$ref` property MAY carry `x-openregister-relation` with `label`,
`inverseLabel` and `symmetric`, or a `type` naming an entry of the schema's
`x-openregister-relation-types` vocabulary. Schema validation SHALL refuse
an `inverseLabel` on a symmetric relation and a `type` the vocabulary does
not hold. Labels SHALL be i18n keys.

#### Scenario: a symmetric relation with an inverse label is refused

- **GIVEN** a property with `x-openregister-relation: {label: "duplicate of", inverseLabel: "duplicated by", symmetric: true}`
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the property
- @e2e exclude {schema validation, covered by unit tests}

#### Scenario: a vocabulary key is shared by two properties

- **GIVEN** a schema declaring `x-openregister-relation-types: [{key: "blocks", label: "blocks", inverseLabel: "blocked by"}]` and two properties with `type: "blocks"`
- **WHEN** the schema is saved and read back
- **THEN** both properties resolve to the same label pair
- @e2e exclude {schema read, covered by unit tests}

### Requirement: Relation rows carry the property and the label for each direction

`GET .../uses` SHALL return, per row, the property name and its `label`.
`GET .../used` SHALL return, per row, the referencing property name and its
`inverseLabel`, falling back to the property title and to a generic
"referenced by" when the property declares no relation.

#### Scenario: the far side reads "blocked by"

- **GIVEN** case A whose `blocks` property references case B, with `inverseLabel` "blocked by"
- **WHEN** `GET .../B/used` is called
- **THEN** the row for A carries property `blocks` and label "blocked by"
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/relation-types.spec.ts when the panel renders labels}

#### Scenario: an unannotated reference still reads

- **GIVEN** a property `owner` with a `$ref` and no relation annotation
- **WHEN** `GET .../used` is called on the referenced object
- **THEN** the row carries property `owner`, the property's title as label and "referenced by" as the inverse label
- @e2e exclude {fallback, covered by unit tests}
