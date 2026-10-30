## ADDED Requirements

### Requirement: A form role the consumer reads is accepted and forwards nothing (REQ-PVP-004)

An `x-openregister-extends-form` declaration SHALL accept a map role that the
shipped consumer reads to steer the form rather than to fill a property key.
Such a form role SHALL NOT count as a forwarded vocabulary key and SHALL NOT
become a key of a property built from a form submission. The accepted form
roles SHALL be published on the extending-forms read. Today the one form role
is `hideOnCreate`.

#### Scenario: a declaration that maps hideOnCreate is accepted

- **GIVEN** an app whose form declaration maps `hideOnCreate` to its own field
- **WHEN** a schema carrying that declaration is imported
- **THEN** the schema is saved and the declaration reports no errors
- @e2e exclude {validator, covered by unit tests and the dossiq register import}

#### Scenario: a form role forwards no key

- **GIVEN** a declaration mapping `hideOnCreate`
- **WHEN** its forwarded keys are read and a form submission is turned into a property
- **THEN** `hideOnCreate` is not among the forwarded keys and not on the property
- @e2e exclude {pure function, covered by unit tests}

#### Scenario: a role nobody defines is still refused

- **GIVEN** a declaration mapping a role that is neither a vocabulary key, a source alias nor a form role
- **WHEN** the declaration is validated
- **THEN** it is refused naming the role
- @e2e exclude {validator, covered by unit tests}

### Requirement: A closed-list modifier publishes its accepted values (REQ-PVP-005)

A modifier whose value the save path checks against a closed list SHALL
publish that list as `allowedValues` on its row of the property vocabulary.
The published list SHALL be the list the save path enforces, so an app can
check its shipped schemas before an import refuses them. Today that is
`inputControl` and `matchType`.

#### Scenario: inputControl publishes the controls the save path accepts

- **GIVEN** the published property vocabulary
- **WHEN** the `inputControl` modifier row is read
- **THEN** it carries `allowedValues`, every listed value saves, and `textarea` is not listed and is refused
- @e2e exclude {published read, covered by unit tests and the dossiq vocabulary guard}
