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

### Using Mock Register Data

The **BAG** mock register provides local test data for developing and testing the sync pipeline without requiring external API access.

**Loading the register:**
```bash
# Load BAG register (32 addresses + 21 objects + 21 buildings, register slug: "bag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
```

**Test data for this spec's use cases:**
- **BAG sync source**: Use loaded BAG `nummeraanduiding` records as the "expected" result for a sync run, verifying the gather-fetch-import pipeline
- **Incremental sync**: Modify a loaded BAG record and re-run sync to test change detection and upsert behavior
- **Schema validation**: BAG records include proper 16-digit identifications, postcodes, and municipality codes -- test schema validation during import

### Current Implementation Status
- **Partial foundations:**
  - `Source` entity (`lib/Db/Source.php`) exists with fields: uuid, title, version, description, databaseUrl, type, organisation — represents an external data source
  - `SourceMapper` (`lib/Db/SourceMapper.php`) for CRUD on source definitions
  - Frontend source management views exist at `src/views/source/`
  - `ImportService` (`lib/Service/ImportService.php`) handles CSV and Excel import but not API-based sync
  - `ConfigurationService` (`lib/Service/ConfigurationService.php`) and `Configuration/ImportHandler` handle configuration imports from external sources (GitHub/GitLab)
- **NOT implemented:**
  - Three-stage sync pipeline (gather, fetch, import)
  - REST API sync source with authentication, mapping rules, and schedule
  - CSV file sync source with automatic column mapping
  - Field mapping and value transformation configuration
  - Create/update/delete sync operations (delta sync)
  - Incremental sync with last-modified tracking
  - Sync execution monitoring and reporting (start time, duration, record counts, errors)
  - Resume after failure (checkpoint/resumability)
  - Scheduled sync via background jobs
  - Sync execution history and logs
- **Partial:**
  - The Source entity captures basic source metadata (URL, type) but not sync-specific configuration (mapping rules, schedule, auth credentials)
  - Import capabilities exist for one-shot file imports but not for ongoing synchronization
  - Configuration import from GitHub/GitLab follows a similar pattern but is specific to app configuration, not data sync

### Standards & References
- **DCAT (Data Catalog Vocabulary)** — W3C standard for describing data catalogs and datasets
- **OAI-PMH (Open Archives Initiative Protocol for Metadata Harvesting)** — Harvesting protocol for metadata
- **BAG API (Kadaster)** — Reference implementation for Dutch base registration sync
- **BRK, BRP, HR APIs** — Dutch government base registration APIs
- **Haal Centraal** — VNG initiative for modern government API access
- **CRON / Nextcloud BackgroundJob** — Scheduling mechanism for sync jobs
- **RFC 7232** — Conditional requests (If-Modified-Since) for incremental sync

### Specificity Assessment
- The spec is well-structured with the three-stage pattern clearly defined.
- Missing: database schema for sync configuration (schedule, mapping rules, auth credentials, last sync timestamp); API endpoints for sync source CRUD and manual trigger; background job implementation details; how sync conflicts are resolved (source wins? merge?).
- Ambiguous: how field mapping and value transformation rules are defined — is it a UI configuration, JSON config, or Twig template? How does this relate to the existing Twig mapping infrastructure?
- Open questions:
  - Should the sync pipeline reuse the existing Twig mapping infrastructure for field transformation?
  - How should authentication credentials for external APIs be stored securely?
  - What is the maximum dataset size that should be supported in a single sync run?
  - Should sync support bidirectional sync (push changes back to source) or only pull?
  - How does sync interact with webhooks — should synced objects trigger webhook events?
