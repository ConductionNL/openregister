# objects-crud (delta)

## ADDED Requirements

### Requirement: A refused save names the field that broke the rule (REQ-MSR-001)

When an object create or update fails validation against its schema, the system SHALL refuse the write with HTTP 400 and SHALL NOT store any part of it. The response SHALL carry one message per failure, and each message SHALL name the property path that failed (for a nested property the full path, such as `adres.postcode`) and the rule it broke (required, type, format, enum, length, item count or pattern).

#### Scenario: A required field is missing
- **GIVEN** a schema `melding` with `omschrijving` required
- **WHEN** a client creates a `melding` without `omschrijving`
- **THEN** the response is 400
- **AND** the message names `omschrijving` as required
- **AND** no object is stored
- @e2e exclude {asserted by ValidateObjectTest on formatValidationError; task 2.1}

#### Scenario: A nested field has the wrong format
- **GIVEN** a schema with an object property `adres` whose `postcode` has `pattern: ^[1-9][0-9]{3}[A-Z]{2}$`
- **WHEN** a client saves `adres.postcode` as `12AB`
- **THEN** the message names `adres.postcode` and the pattern rule
- @e2e exclude {asserted by ValidateObjectTest; task 2.1}

### Requirement: An object-typed property is validated against its own JSON Schema (REQ-MSR-002)

A property of type `object` or an array of objects MAY carry its own JSON Schema (`properties`, `required`, `format`, `enum`, nested `$ref`). The system SHALL validate the property's value against that schema before save, as part of validating the whole object, and SHALL refuse the save under REQ-MSR-001 when the value does not conform. A property of type `object` without its own schema SHALL accept any JSON object.

#### Scenario: A JSON field that misses a nested required key
- **GIVEN** a schema with a property `contactgegevens` of type `object` that requires `email`
- **WHEN** a client saves `contactgegevens` as `{"telefoon": "0612345678"}`
- **THEN** the save is refused and the message names `contactgegevens.email`
- @e2e exclude {asserted by ValidateObjectTest; task 2.2}

#### Scenario: A free JSON field
- **GIVEN** a property of type `object` with no nested schema
- **WHEN** a client saves any JSON object in it
- **THEN** the save succeeds
- @e2e exclude {asserted by ValidateObjectTest; task 2.2}
