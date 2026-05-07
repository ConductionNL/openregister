# tmlo-auto-populate Specification

## Purpose
TBD - created by archiving change tmlo-metadata. Update Purpose after archive.
## Requirements
### Requirement: Auto-populate TMLO metadata on object creation

The system SHALL automatically populate TMLO metadata when an object is created in a TMLO-enabled register. The population logic SHALL:

1. Check if the object's register has `configuration.tmloEnabled = true`
2. Look up the object's schema for `configuration.tmloDefaults`
3. Merge schema defaults into the object's `tmlo` field
4. Set `archiefstatus` to `actief` if not already set
5. Calculate `archiefactiedatum` from `bewaarTermijn` if both the retention period is set and no explicit archiefactiedatum is provided

#### Scenario: Auto-populate with schema defaults

- **WHEN** an object is created in a TMLO-enabled register
- **THEN** the TmloService SHALL populate the `tmlo` field with schema-level defaults
- **THEN** the `archiefstatus` SHALL be set to `actief`

#### Scenario: Calculate archiefactiedatum from bewaarTermijn

- **WHEN** an object is created with `tmlo.bewaarTermijn = "P7Y"` and no archiefactiedatum
- **THEN** the `archiefactiedatum` SHALL be calculated as creation date + 7 years

#### Scenario: Explicit TMLO values override defaults

- **WHEN** an object is created with explicit TMLO values in the request body
- **THEN** the explicit values SHALL override any schema defaults
- **THEN** only missing fields SHALL be populated from defaults

### Requirement: TmloService as central TMLO logic handler

The system SHALL provide a `TmloService` class that encapsulates all TMLO-related logic:
- Populating TMLO defaults on object creation
- Validating TMLO metadata for status transitions
- Generating MDTO-compliant XML export
- Querying objects by archival status

#### Scenario: TmloService is injectable via DI

- **WHEN** a controller or service needs TMLO functionality
- **THEN** `TmloService` SHALL be available via Nextcloud's dependency injection container

