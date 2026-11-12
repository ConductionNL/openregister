## ADDED Requirements

### Requirement: A register groups record types and moves as one unit

A register MUST hold a named set of schemas. The register MUST list its schemas, and an export
of the register MUST carry those schemas and, when asked, their records. Importing that export on
another instance MUST create the register with the same schemas.

#### Scenario: A register lists its record types
- **GIVEN** a register `meldingen` holding the schemas `melding` and `melder`
- **WHEN** a client requests `GET /api/registers/meldingen/schemas`
- **THEN** the response lists `melding` and `melder` and no other schema

#### Scenario: A register is shared as one file
- **GIVEN** the register `meldingen` with its two schemas and 40 records
- **WHEN** an administrator exports it with its records and imports the file on another instance
- **THEN** that instance has a register `meldingen` with the schemas `melding` and `melder` and 40 records
