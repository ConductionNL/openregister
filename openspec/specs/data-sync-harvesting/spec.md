# data-sync-harvesting Specification

## Purpose
Implement batch synchronization of data from external APIs and data sources into register schemas. The sync pipeline MUST follow a three-stage pattern (gather, fetch, import) for reliable data federation. Sources MUST include REST APIs, other registers, file feeds (CSV/JSON), and government base registrations.

**Source**: Gap identified in cross-platform analysis; three platforms implement data sync/harvesting.

## ADDED Requirements

### Requirement: The system MUST support configurable sync source definitions
Administrators MUST be able to define external data sources with connection details, mapping rules, and sync schedules.

#### Scenario: Define a REST API sync source
- GIVEN the admin creates a sync source:
  - Name: `BAG Adressen`
  - Type: `rest-api`
  - URL: `https://api.bag.kadaster.nl/v2/adressen`
  - Authentication: API key header
  - Target schema: `adressen`
  - Mapping: JSON path to schema property mapping
  - Schedule: daily at 02:00
- THEN the sync source MUST be stored and schedulable

#### Scenario: Define a CSV file sync source
- GIVEN the admin creates a sync source:
  - Name: `Productenlijst import`
  - Type: `csv-file`
  - File path: Nextcloud Files path or upload
  - Delimiter: `;`
  - Target schema: `producten`
  - Column mapping: CSV column to schema property
- THEN the sync source MUST validate the CSV structure against the mapping

### Requirement: The sync pipeline MUST follow a three-stage pattern
Each sync execution MUST follow gather (identify records), fetch (retrieve data), import (store in register) stages for reliability and resumability.

#### Scenario: Three-stage sync execution
- GIVEN a sync source `BAG Adressen` is scheduled to run
- WHEN the sync starts
- THEN Stage 1 (Gather): the system MUST query the source API to identify all records to sync
  - AND store the list of record identifiers
- THEN Stage 2 (Fetch): for each identified record, the system MUST fetch the full data
  - AND store the raw fetched data
- THEN Stage 3 (Import): for each fetched record, the system MUST map and validate the data
  - AND create or update the corresponding register object

#### Scenario: Resume after failure
- GIVEN a sync execution failed during Stage 2 (Fetch) after processing 500 of 1000 records
- WHEN the sync is resumed
- THEN the system MUST continue from record 501 (not restart from the beginning)
- AND already-fetched records MUST NOT be re-fetched

### Requirement: The system MUST support field mapping and transformation
Each sync source MUST define how source fields map to target schema properties, with optional transformations.

#### Scenario: Direct field mapping
- GIVEN source JSON field `straatnaam` maps to schema property `street`
- WHEN a record with `straatnaam: "Kerkstraat"` is imported
- THEN the object property `street` MUST be set to `Kerkstraat`

#### Scenario: Value transformation
- GIVEN a mapping with transformation: source `status` value `A` maps to target value `actief`
- WHEN a record with `status: "A"` is imported
- THEN the object property `status` MUST be set to `actief`

### Requirement: Sync MUST support create, update, and delete operations
The sync pipeline MUST handle new records (create), changed records (update), and removed records (delete or mark inactive).

#### Scenario: Create new objects from sync
- GIVEN 10 new records in the source that do not exist in the register
- WHEN the sync import stage runs
- THEN 10 new objects MUST be created in the target schema

#### Scenario: Update existing objects from sync
- GIVEN source record `addr-1` has changed since last sync
- AND the register already has an object with external ID `addr-1`
- WHEN the sync import stage runs
- THEN the existing object MUST be updated with the new data
- AND the audit trail MUST record the sync update

#### Scenario: Handle deleted source records
- GIVEN source record `addr-5` existed in the last sync but is absent from the current sync
- WHEN the sync import stage runs
- THEN the system MUST either soft-delete the object (set status to `inactive`) or hard-delete it
- AND the behavior MUST be configurable per sync source

### Requirement: Sync executions MUST be monitored
Each sync execution MUST produce a detailed log with statistics and error reports.

#### Scenario: View sync execution report
- GIVEN a sync execution has completed
- WHEN the admin views the execution report
- THEN the report MUST show:
  - Start time, duration, status (success/partial/failed)
  - Records gathered, fetched, imported
  - Records created, updated, deleted, skipped, errored
  - Error details for each failed record

### Requirement: Sync MUST support incremental updates
The system MUST support delta syncs using last-modified timestamps or change tokens to avoid re-fetching unchanged records.

#### Scenario: Incremental sync with last-modified
- GIVEN the last sync ran at 2026-03-14T02:00:00Z
- WHEN a new sync starts
- THEN the gather stage MUST request only records modified after 2026-03-14T02:00:00Z
- AND unchanged records MUST NOT be fetched or imported
