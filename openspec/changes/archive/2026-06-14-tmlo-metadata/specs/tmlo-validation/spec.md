## ADDED Requirements

### Requirement: TMLO status transition validation

The system SHALL validate archival status transitions to ensure required fields are present before allowing a status change. The valid transitions and their requirements are:

- `actief` -> `semi_statisch`: No additional requirements
- `semi_statisch` -> `overgebracht`: Requires `archiefactiedatum`, `classificatie`, `archiefnominatie` to be set. `archiefnominatie` MUST be `blijvend_bewaren`.
- `semi_statisch` -> `vernietigd`: Requires `archiefactiedatum`, `classificatie`, `archiefnominatie`, `vernietigingsCategorie` to be set. `archiefnominatie` MUST be `vernietigen`.
- `actief` -> `overgebracht`: NOT allowed (must go through `semi_statisch` first)
- `actief` -> `vernietigd`: NOT allowed (must go through `semi_statisch` first)
- Any status -> `actief`: NOT allowed (cannot revert to active)

#### Scenario: Valid transition from actief to semi_statisch

- **WHEN** an object's archiefstatus is changed from `actief` to `semi_statisch`
- **THEN** the transition SHALL be accepted without additional validation

#### Scenario: Transition to overgebracht with missing fields

- **WHEN** an object's archiefstatus is changed from `semi_statisch` to `overgebracht` but `classificatie` is null
- **THEN** the system SHALL reject the update with a 422 error listing the missing required fields

#### Scenario: Transition to vernietigd with wrong archiefnominatie

- **WHEN** an object's archiefstatus is changed to `vernietigd` but `archiefnominatie` is `blijvend_bewaren`
- **THEN** the system SHALL reject the update with a 422 error indicating archiefnominatie must be `vernietigen`

#### Scenario: Invalid direct transition from actief to overgebracht

- **WHEN** an object's archiefstatus is changed directly from `actief` to `overgebracht`
- **THEN** the system SHALL reject the update with a 422 error indicating the transition is not allowed

### Requirement: TMLO field value validation

The system SHALL validate TMLO field values to ensure they conform to allowed values:

- `archiefnominatie` MUST be one of: `blijvend_bewaren`, `vernietigen`
- `archiefstatus` MUST be one of: `actief`, `semi_statisch`, `overgebracht`, `vernietigd`
- `bewaarTermijn` MUST be a valid ISO-8601 duration string (e.g., `P7Y`, `P5Y6M`)
- `archiefactiedatum` MUST be a valid ISO-8601 date string

#### Scenario: Invalid archiefnominatie value rejected

- **WHEN** an object is saved with `tmlo.archiefnominatie = "invalid_value"`
- **THEN** the system SHALL reject the save with a 422 error listing valid values

#### Scenario: Valid ISO-8601 duration accepted

- **WHEN** an object is saved with `tmlo.bewaarTermijn = "P10Y"`
- **THEN** the value SHALL be accepted and stored
