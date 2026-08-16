## ADDED Requirements

### Requirement: Register-level TMLO toggle

The system SHALL support a `tmloEnabled` boolean in the Register entity's `configuration` JSON field. When `tmloEnabled` is true, all objects created or updated in that register SHALL carry TMLO metadata fields.

#### Scenario: Enable TMLO on a register

- **WHEN** a register is updated with `configuration.tmloEnabled = true`
- **THEN** the register's configuration SHALL persist tmloEnabled=true
- **THEN** new objects in that register SHALL receive TMLO default metadata

#### Scenario: Disable TMLO on a register

- **WHEN** a register is updated with `configuration.tmloEnabled = false`
- **THEN** the register's configuration SHALL persist tmloEnabled=false
- **THEN** new objects in that register SHALL NOT receive TMLO default metadata

### Requirement: Schema-level TMLO defaults

The system SHALL support TMLO default values in the Schema entity's `configuration` JSON field under a `tmloDefaults` key. These defaults SHALL be applied to new objects when their register has TMLO enabled.

Supported defaults:
- `classificatie` -- Default classification code
- `archiefnominatie` -- Default archival nomination (`blijvend_bewaren` or `vernietigen`)
- `bewaarTermijn` -- Default retention period (ISO-8601 duration)
- `vernietigingsCategorie` -- Default destruction category

#### Scenario: Schema with TMLO defaults applied to new object

- **WHEN** a new object is created in a TMLO-enabled register with a schema that has tmloDefaults configured
- **THEN** the object's `tmlo` field SHALL be populated with the schema's default values
- **THEN** the `archiefstatus` SHALL default to `actief`

#### Scenario: Schema without TMLO defaults in TMLO-enabled register

- **WHEN** a new object is created in a TMLO-enabled register with a schema that has no tmloDefaults
- **THEN** the object's `tmlo` field SHALL be an object with all sub-fields set to null except `archiefstatus` which SHALL be `actief`
