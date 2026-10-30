# runtime-schema-api

## ADDED Requirements

### Requirement: A property declares its meaning and its help text (REQ-CLH-003)

A property MAY declare a semantic role of `title`, `status`, `assignee` or
`term`, and a schema declaring the same role on two properties SHALL fail
to save naming both. A property MAY carry administered help text,
resolvable per language, beside its existing description, and the schema
read SHALL return it in the negotiated language.

#### Scenario: one list component serves an unknown schema

- **GIVEN** a schema declaring `onderwerp` as the title and `fase` as the status
- **WHEN** the schema is read
- **THEN** the roles are returned with the properties that hold them

#### Scenario: two titles are refused

- **GIVEN** a schema declaring the role `title` on two properties
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming both properties
- @e2e exclude {validator, covered by unit tests}

#### Scenario: help text is read in the caller's language

- **GIVEN** a property with Dutch and English help text
- **WHEN** the schema is read with `Accept-Language: nl`
- **THEN** the Dutch help text is returned
- @e2e exclude {negotiation, covered by unit tests}

### Requirement: Uniqueness over a named field combination (REQ-CLH-004)

A schema MAY declare a uniqueness constraint over a named combination of
properties, with the action `refuse` or `report` on a breach. A `refuse`
constraint SHALL fail the save with 422 naming the combination and the
conflicting object. A `report` constraint SHALL let the save succeed and
SHALL record the breach where it can be read.

#### Scenario: a second bezwaar on one besluit is refused

- **GIVEN** a constraint over `besluit` and `indiener` with action `refuse`, and an object holding that pair
- **WHEN** a second object with the same pair is saved
- **THEN** the save fails with 422 naming both properties and the existing object

#### Scenario: a reporting constraint does not block the intake

- **GIVEN** a constraint over `email` with action `report`
- **WHEN** a second object with the same e-mail is saved
- **THEN** the save succeeds and the breach is recorded and readable

### Requirement: A property type change on populated objects is a declared conversion (REQ-CLH-005)

The system SHALL publish which property type conversions it supports. A
conversion request on a property that has stored values SHALL first return
a preview over those values, naming how many convert and which do not. A
conversion that is not on the published list SHALL be refused with a
reason, never attempted.

#### Scenario: an administrator sees what a conversion would cost

- **GIVEN** a string property with 4,000 stored values of which 12 are not numeric
- **WHEN** a conversion to number is previewed
- **THEN** the preview reports 3,988 convertible and names the 12 that are not

#### Scenario: an unsupported conversion is refused

- **GIVEN** a request to convert a file property to a number
- **WHEN** the conversion is requested
- **THEN** it is refused with a reason and the property is unchanged
- @e2e exclude {validator, covered by unit tests}
