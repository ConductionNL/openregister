# object-lifecycle

## ADDED Requirements

### Requirement: An administrator adds a validation with its own message (REQ-RCT-004)

A schema MAY declare validations. Each declares a condition in the shared
expression vocabulary, a severity of `refuse` or `warn`, the properties the
message concerns, and a message as translatable content. A `refuse`
validation whose condition holds SHALL refuse the save with HTTP 422
carrying the administrator's message verbatim and the named properties. A
`warn` validation SHALL save and return the message. A validation naming a
property the schema does not declare, or carrying no message, SHALL be
refused at schema save.

#### Scenario: the handler reads the sentence somebody wrote

- **GIVEN** a validation refusing a save when `bedrag` is above 50000 and `mandaat` is empty, with the message "Boven 50.000 euro is een mandaat verplicht"
- **WHEN** such an object is saved
- **THEN** the save fails with HTTP 422
- **AND** the response carries that message and names `mandaat`

#### Scenario: a warning does not block the work

- **GIVEN** the same condition declared with severity `warn`
- **WHEN** such an object is saved
- **THEN** the save succeeds and the response carries the message

#### Scenario: a validation without a message is refused

- **GIVEN** a validation declaring a condition and no message
- **WHEN** the schema is saved
- **THEN** the save fails naming the validation
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: the message is translatable content

- **GIVEN** a validation whose message is declared in Dutch and English
- **WHEN** a save is refused for a caller whose language is English
- **THEN** the English message is returned

### Requirement: An administered validation is evaluated on every write path (REQ-RCT-005)

Declared validations SHALL be evaluated at the same point in the save
pipeline as the other declared rules, so that the object API, an import, a
flow node write and a bulk job write all reach them. The write-path
enumeration test SHALL cover the validations, so a path added later that
skips them fails.

#### Scenario: an import meets the same check

- **GIVEN** a schema with a refusing validation
- **WHEN** an import row would violate it
- **THEN** the row is refused with the administrator's message and no object is created

#### Scenario: a new write path that skips validations fails the test

- **GIVEN** the enumerated write paths
- **WHEN** a write path is added that bypasses the save pipeline
- **THEN** the enumeration test fails and names the path
- @e2e exclude {test-suite invariant, covered by the enumeration unit test}
