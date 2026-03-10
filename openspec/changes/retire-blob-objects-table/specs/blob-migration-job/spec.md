## ADDED Requirements

### Requirement: Background job migrates blob objects to magic tables
The system SHALL provide a `TimedJob` that migrates objects from `oc_openregister_objects` to their corresponding magic tables. The job SHALL run every 5 minutes and process up to 100 objects per execution.

#### Scenario: Normal batch migration
- **WHEN** the background job executes and the blob table contains objects with valid register and schema references
- **THEN** the job queries up to 100 objects, groups them by (register, schema) pair, upserts each group into the corresponding magic table via `ultraFastBulkSave()`, and deletes the successfully migrated rows from the blob table

#### Scenario: Objects grouped by register and schema
- **WHEN** the job fetches a batch of 100 objects spanning 3 different register+schema combinations
- **THEN** the job performs 3 separate bulk upsert operations (one per combination) to the respective magic tables

#### Scenario: Magic table does not exist yet
- **WHEN** the job encounters objects for a register+schema pair whose magic table has not been created
- **THEN** the job triggers `MagicMapper::createTableForRegisterSchema()` to auto-create the table before upserting

#### Scenario: No objects remaining
- **WHEN** the job executes and the blob table contains zero rows
- **THEN** the job sets an `appconfig` flag `blob_migration_complete=true` and logs completion at INFO level

### Requirement: Orphaned objects are skipped and logged
The system SHALL skip blob objects that have null or invalid register/schema references. These objects SHALL NOT be deleted automatically.

#### Scenario: Object with null register
- **WHEN** the job encounters an object with a null or empty `register` field
- **THEN** the job logs a WARNING with the object UUID and skips it without deletion

#### Scenario: Object with non-existent schema
- **WHEN** the job encounters an object whose `schema` ID does not correspond to any registered schema
- **THEN** the job logs a WARNING with the object UUID and schema ID, and skips it without deletion

### Requirement: Migration progress is trackable
The system SHALL store migration progress in `appconfig` so that admins can monitor status.

#### Scenario: Progress tracking after each batch
- **WHEN** a batch completes successfully
- **THEN** the job updates `appconfig` keys: `blob_migration_processed` (cumulative count), `blob_migration_remaining` (current blob table row count), and `blob_migration_last_run` (timestamp)

#### Scenario: Admin checks migration status
- **WHEN** an admin queries the OpenRegister admin settings or runs `occ config:app:get openregister blob_migration_remaining`
- **THEN** the system returns the current count of objects still in the blob table
