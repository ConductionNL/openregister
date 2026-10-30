# skos-concept-registers (delta)

## ADDED Requirements

### Requirement: A coded property takes its allowed values from a concept scheme (REQ-MSR-003)

A schema property MAY name a concept scheme by its URI. The property's allowed values SHALL then be the concepts of that scheme, read from the vocabulary register at save time rather than copied into the schema. A create or update holding a value that is not a concept of the scheme SHALL be refused, naming the property and the scheme. Adding a concept to the scheme SHALL make it allowed on every property that names the scheme without a schema edit.

#### Scenario: A value from the scheme is accepted
- **GIVEN** a property `categorie` that names the scheme `https://identifier.overheid.nl/tooi/set/scw_woo_informatiecategorieen`
- **WHEN** a client saves a concept of that scheme in `categorie`
- **THEN** the save succeeds
- @e2e exclude {asserted by CodedValueValidationListenerTest; task 2.3}

#### Scenario: A value outside the scheme is refused
- **GIVEN** the same property
- **WHEN** a client saves `onbekend` in `categorie`
- **THEN** the save is refused and the message names `categorie` and the scheme
- @e2e exclude {asserted by CodedValueValidationListenerTest; task 2.3}

#### Scenario: A new concept is usable at once
- **GIVEN** an administrator adds a concept to the scheme
- **WHEN** a client saves that concept in `categorie`
- **THEN** the save succeeds without any change to the schema
- @e2e exclude {asserted by CodedValueValidationListenerTest; task 2.3}
