# runtime-schema-api

## ADDED Requirements

### Requirement: The property vocabulary is published (REQ-PVP-001)

The system SHALL publish every property type it accepts, with the
constraint keys that type takes, the formats it supports and a description
a human can read. The published list SHALL be generated from the same
source the save-time validator reads, so the two cannot disagree. Each
entry SHALL state whether converting a populated property to that type is
supported.

#### Scenario: an editor is generated rather than typed

- **GIVEN** an instance whose validator accepts more property types than any
  leaf editor offers
- **WHEN** the property vocabulary is read
- **THEN** every type the validator accepts is returned, each with its
  constraint keys, its formats and its conversion answer

#### Scenario: the published list cannot drift from the validator

- **GIVEN** a type accepted by the validator
- **WHEN** the vocabulary is read
- **THEN** that type is present
- @e2e exclude {generated from one source, covered by a unit test that compares the two}

### Requirement: A declared property is validated against the vocabulary (REQ-PVP-002)

A schema save naming a property type, a constraint key or a format that
the vocabulary does not hold SHALL fail with HTTP 422 naming the offending
value. A property that validates today SHALL continue to validate.

#### Scenario: a typo does not become an untyped string

- **GIVEN** a schema declaring a property of type `sting`
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming `sting`

#### Scenario: an unknown constraint key is refused

- **GIVEN** a property declaring a constraint key the vocabulary does not hold
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming the key
- @e2e exclude {validator, covered by unit tests}

### Requirement: An app's property form declares what it forwards (REQ-PVP-003)

An application that lets an administrator author schema properties through
its own form SHALL declare which vocabulary keys that form forwards. The
declaration SHALL map each vocabulary key to the application's own field
name, in that direction, because that is the direction the shipped consumer
reads. The declaration SHALL be validated against the vocabulary, a
vocabulary key the vocabulary does not hold SHALL be refused naming it, and
a forwarded property SHALL be validated exactly as a directly declared one.
The declared narrowing SHALL be readable, so the difference between the
app's list and the vocabulary can be counted.

#### Scenario: a narrower editor is a stated narrowing

- **GIVEN** an app whose property form forwards six of the vocabulary's keys,
  each named on the left of its map
- **WHEN** its declaration is read
- **THEN** the six are listed and the keys it does not forward can be derived

#### Scenario: forwarding a key nobody defines is refused

- **GIVEN** an app declaring that its form forwards a key the vocabulary does not hold
- **WHEN** the declaration is saved
- **THEN** it is refused naming the key
- @e2e exclude {validator, covered by unit tests}

#### Scenario: a key an app owns stays out of the vocabulary until it is defined

- **GIVEN** a property annotation in the `x-` namespace that this layer does
  not define
- **WHEN** a schema carrying it is saved
- **THEN** the save succeeds and the annotation is not published as a
  vocabulary key
- @e2e exclude {vendor-extension passthrough, covered by a unit test}
