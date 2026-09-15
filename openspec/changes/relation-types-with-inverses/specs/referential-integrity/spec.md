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

#### Scenario: a vocabulary key is shared by two properties

- **GIVEN** a schema declaring `x-openregister-relation-types: [{key: "blocks", label: "blocks", inverseLabel: "blocked by"}]` and two properties with `type: "blocks"`
- **WHEN** the schema is saved and read back
- **THEN** both properties resolve to the same label pair
- @e2e exclude {a schema read with no object in it; asserted in tests/Unit/Service/Relation/RelationTypeResolverTest.php}

### Requirement: Relation rows carry the property and the label for each direction

`GET .../uses` SHALL return, per row, the property name and its `label`.
`GET .../used` SHALL return, per row, the referencing property name and its
`inverseLabel`, falling back to the property title and to a generic
"referenced by" when the property declares no relation.

#### Scenario: the far side reads "blocked by"

- **GIVEN** case A whose `blocks` property references case B, with `inverseLabel` "blocked by"
- **WHEN** `GET .../B/used` is called
- **THEN** the row for A carries property `blocks` and label "blocked by"

#### Scenario: an unannotated reference still reads

- **GIVEN** a property `owner` with a `$ref` and no relation annotation
- **WHEN** `GET .../used` is called on the referenced object
- **THEN** the row carries property `owner`, the property's title as label and "referenced by" as the inverse label
- @e2e exclude {needs a second schema declaring nothing, which the e2e register does not carry; asserted in tests/Unit/Service/Relation/RelationTypeResolverTest.php and tests/Unit/Service/Object/RelationHandlerLabelsTest.php}

### Requirement: A split keeps its provenance, and a relation may declare inheritance (REQ-RTI-003)

Creating an object from an entry of another object SHALL record a typed
relation to the source object and to the entry it came from. A relation
type MAY declare which properties a new child takes from its parent at
creation, from classification, confidentiality and responsible principal.
Inheritance SHALL happen once, at creation, SHALL be recorded on the
relation, and a later change to the parent SHALL NOT change the child.

#### Scenario: one melding becomes two zaken, traceably

- **GIVEN** an object with a timeline entry
- **WHEN** a new object is created from that entry
- **THEN** a typed relation names the source object and the entry

#### Scenario: a sub-case starts with the parent's access triad

- **GIVEN** a relation type declaring inheritance of classification, confidentiality and responsible principal
- **WHEN** a child is created under a parent
- **THEN** the child carries the parent's three values and the relation records that it inherited them

#### Scenario: reclassifying the parent does not reclassify the child

- **GIVEN** that child
- **WHEN** the parent's classification changes
- **THEN** the child's classification is unchanged

### Requirement: An external address and a reference in prose are relation rows (REQ-RTI-004)

A web address outside the product MAY be a relation target, carrying a
title and a type, and SHALL appear wherever relations appear. A reference
recorded from text on an object SHALL create a typed relation row on both
sides, and removing the text SHALL remove the row.

#### Scenario: a link to another system is part of the record

- **GIVEN** an external address added as a relation with a title and a type
- **WHEN** the object's relations are read
- **THEN** it is listed like any other relation

#### Scenario: the graph follows from the prose

- **GIVEN** a declared reference pattern and a note naming another object
- **WHEN** the note is saved
- **THEN** both objects hold a typed relation, and deleting the text removes it
- @e2e exclude {the pattern resolution that triggers this is owned by timeline-entries-are-records; this change owns only recordProseReference/withdrawProseReferences, asserted in tests/Unit/Service/Relation/ObjectRelationServiceTest.php}

### Requirement: The relation graph is readable and exportable within a bounded depth (REQ-RTI-005)

The system SHALL answer, for a root object and a requested depth within an
administered maximum, the objects reachable along relations, with the
relation type and direction on each edge. The answer SHALL say whether it
was truncated by the depth or by a result cap, and SHALL be exportable.

#### Scenario: what is this case linked to

- **GIVEN** an object with relations two steps deep
- **WHEN** the graph is requested at depth two
- **THEN** the objects and the typed, directed edges between them are returned

#### Scenario: a truncated graph says so

- **GIVEN** a request at a depth above the administered maximum
- **WHEN** it runs
- **THEN** the answer is bounded at the maximum and is marked truncated
