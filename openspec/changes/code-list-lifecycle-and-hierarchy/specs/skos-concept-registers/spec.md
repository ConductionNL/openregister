# skos-concept-registers

## ADDED Requirements

### Requirement: A concept carries its own fields and its own validity window (REQ-CLH-001)

A concept scheme MAY declare the shape of its concepts, and concepts SHALL
then be validated against that shape on import and on save. A concept MAY
carry `validFrom` and `validUntil`. Outside its window a concept SHALL NOT
be offered as an option and SHALL still resolve on read with its label, so
objects that already hold it stay readable. A write of an out-of-window
concept SHALL be refused with 422 naming the concept and the window. A
scheme MAY declare an exclusive group, and an object holding two concepts
of one group SHALL be refused.

#### Scenario: a retired value keeps working on old records

- **GIVEN** a concept whose `validUntil` has passed and an object saved with it last year
- **WHEN** the object is read and the property's options are read
- **THEN** the object still resolves the concept with its label
- **AND** the options do not offer it

#### Scenario: a retired value cannot be written today

- **GIVEN** the same concept
- **WHEN** a new object is saved with it
- **THEN** the save fails with 422 naming the concept and its window

#### Scenario: a list item carries its own fields

- **GIVEN** a scheme declaring concepts with `bewaartermijn` and `grondslag`
- **WHEN** a concept is imported without `grondslag`
- **THEN** the import reports that concept as invalid, naming `grondslag`
- @e2e exclude {importer, covered by unit tests}

#### Scenario: two concepts of one exclusive group are refused

- **GIVEN** a scheme with concepts `spoed` and `regulier` in one exclusive group
- **WHEN** an object is saved holding both
- **THEN** the save fails with 422 naming the group and both concepts

### Requirement: A property uses the hierarchy, the context and the weights (REQ-CLH-002)

A coded property MAY declare a branch of the scheme as its source and MAY
require a leaf concept, and its options SHALL then be returned as a tree.
A filter on a branch SHALL match objects holding any narrower concept,
resolved at query time with a bounded depth. A coded property MAY bind its
option subset to the value of another property or to a declared context
key. A concept MAY carry a weight, and a multi-valued coded property MAY
declare a score rolled up from the weights of the concepts it holds,
evaluated by the calculation engine.

#### Scenario: filtering by a branch finds the leaves

- **GIVEN** a scheme where `vergunning` is broader of `kapvergunning` and objects holding the narrower concept
- **WHEN** the object list is filtered on the branch `vergunning`
- **THEN** the objects holding `kapvergunning` are returned

#### Scenario: one property serves two case types with different values

- **GIVEN** a property whose option subset is bound to the value of `zaaktype`
- **WHEN** the options are read for `zaaktype: bezwaar` and for `zaaktype: melding`
- **THEN** each read returns only its own subset

#### Scenario: a leaf rule refuses a broader value

- **GIVEN** a property declaring leaf concepts only
- **WHEN** an object is saved with a concept that has narrower concepts
- **THEN** the save fails with 422 naming the concept
- @e2e exclude {validator, covered by unit tests}

#### Scenario: the weights roll up to a score

- **GIVEN** a multi-valued coded property with weights 3 and 5 on the concepts held, and a declared rolled-up score
- **WHEN** the object is read
- **THEN** the score reads 8
- @e2e exclude {calculation engine, covered by unit tests}
