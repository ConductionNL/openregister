## ADDED Requirements

### Requirement: TMLO metadata fields on ObjectEntity

The system SHALL store TMLO-compliant archival metadata on each ObjectEntity as a JSON column named `tmlo`. The `tmlo` field SHALL contain the following sub-fields conforming to TMLO 1.2 / MDTO:

- `classificatie` (string, nullable) -- Archival classification code from the VNG Selectielijst
- `archiefnominatie` (string, nullable) -- One of: `blijvend_bewaren`, `vernietigen`
- `archiefactiedatum` (string ISO-8601 date, nullable) -- Date when the archival action (transfer or destruction) SHALL occur
- `archiefstatus` (string, nullable) -- One of: `actief`, `semi_statisch`, `overgebracht`, `vernietigd`
- `bewaarTermijn` (string ISO-8601 duration, nullable) -- Retention period (e.g., `P7Y` for 7 years)
- `vernietigingsCategorie` (string, nullable) -- Destruction category from the VNG Selectielijst result types

When TMLO is not enabled on the register, the `tmlo` field SHALL be null or an empty object.

#### Scenario: Object created in TMLO-enabled register carries tmlo field

- **WHEN** an object is created in a register with tmloEnabled=true
- **THEN** the object SHALL have a `tmlo` field in its `@self` metadata containing the six core TMLO sub-fields

#### Scenario: Object created in non-TMLO register has no tmlo field

- **WHEN** an object is created in a register with tmloEnabled=false or tmloEnabled not set
- **THEN** the object SHALL have a null or empty `tmlo` field in its `@self` metadata

#### Scenario: TMLO field persisted and retrieved

- **WHEN** an object with TMLO metadata is saved and then retrieved
- **THEN** the `tmlo` field SHALL contain all previously saved sub-fields with their values intact

### Requirement: Database migration for tmlo column

The system SHALL add a `tmlo` JSON column to the `openregister_objects` table via a Nextcloud migration. The column SHALL be nullable with a default of NULL.

#### Scenario: Migration adds tmlo column

- **WHEN** the database migration runs
- **THEN** the `openregister_objects` table SHALL have a new `tmlo` column of type JSON (or TEXT for SQLite), nullable, default NULL
