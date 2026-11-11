# schema-vocabulaire

## ADDED Requirements

### Requirement: A property may declare where its values come from (REQ-VOC-030)

A schema property MAY carry `x-openregister-property-source` naming a provider,
optionally what to ask it and how its answer is used. The key SHALL be published
in the property vocabulary. A declaration naming no provider, naming a provider
that is not an identifier, or carrying a mode the platform does not know SHALL be
refused when the schema is saved, naming the property. The mode SHALL default to
`live`. A property carrying both this key and `x-openregister-object-source`
SHALL be refused.

#### Scenario: a field bound to a registry

- **GIVEN** a property declaring a provider and the mode `live`
- **WHEN** the schema is saved
- **THEN** it is accepted
- **AND** the key appears in the published vocabulary

#### Scenario: a binding with nothing to ask

- **GIVEN** a property declaring the key with no provider
- **WHEN** the schema is saved
- **THEN** it is refused, naming the property

#### Scenario: a mode nobody knows is not a guess

- **GIVEN** a property declaring a mode the platform does not know
- **WHEN** the schema is saved
- **THEN** it is refused rather than read as the default
- @e2e exclude {schema save refusal, covered by unit tests}

#### Scenario: the two source keys are not interchangeable

- **GIVEN** a property carrying both source keys
- **WHEN** the schema is saved
- **THEN** it is refused, because one binds a field and the other a whole schema
