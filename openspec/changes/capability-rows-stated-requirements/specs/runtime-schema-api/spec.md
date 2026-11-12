# runtime-schema-api

## ADDED Requirements

### Requirement: A schema can be downloaded as a standard JSON Schema document

The system SHALL serve each schema as a JSON Schema draft 2020-12 document that carries
its properties, its required list and its constraints, so that tools outside Open
Register can read it and validate against it. The document MUST be the same definition
the save path validates records with.

#### Scenario: an outside validator reads the downloaded schema

- **WHEN** an administrator downloads a schema from its detail page
- **THEN** the file declares `$schema` draft 2020-12, and a record the API refuses for a missing required field also fails a standard JSON Schema validator run with that file
- @e2e exclude {backend document shape, covered by a unit test in task 1.1}
