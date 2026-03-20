---
status: draft
---
# Data Sync and Harvesting

## Purpose
Implement a robust, multi-source data synchronization and harvesting pipeline that enables OpenRegister to pull data from external APIs (REST, OData, SOAP), file feeds (CSV, JSON, XML), other OpenRegister instances, and Dutch government base registrations (BAG, BRK, BRP, HR) into register schemas. The sync pipeline MUST follow CKAN's proven three-stage pattern (gather, fetch, import) with per-record status tracking, support both scheduled (cron) and event-triggered execution, and provide incremental sync via last-modified tracking or change tokens. The system MUST handle conflict resolution, field mapping via the existing Mapping/Twig infrastructure, authentication for secured sources, and comprehensive monitoring with audit trails -- all within Nextcloud's multi-tenant architecture.

**Source**: Gap identified in cross-platform analysis; CKAN's `ckanext-harvest` three-stage pipeline is the primary reference pattern. OpenCatalogi's `DirectoryService` demonstrates async federation sync with anti-loop protection within the Nextcloud ecosystem. Existing foundation includes `Source` entity (`lib/Db/Source.php`), `SyncConfigurationsJob` (`lib/Cron/SyncConfigurationsJob.php`), `ImportService` (`lib/Service/ImportService.php`), and `Mapping` entity for Twig-based data transformation.

## ADDED Requirements

### Requirement: The system MUST support configurable sync source definitions with connection details, authentication, and scheduling
Administrators MUST be able to define external data sources specifying the source type, endpoint URL or file path, authentication credentials, target register and schema, field mapping reference, sync schedule (cron expression or interval), and conflict resolution strategy. The `Source` entity (`lib/Db/Source.php`) MUST be extended with sync-specific fields: `syncEnabled` (boolean), `syncSchedule` (string, cron expression), `syncInterval` (integer, hours), `lastSyncDate` (datetime), `lastSyncStatus` (string: `success|partial|failed|running`), `authType` (string: `none|apikey|basic|oauth2|certificate`), `authConfig` (json, encrypted credentials), `mappingId` (integer, reference to `Mapping` entity), `conflictStrategy` (string: `source-wins|local-wins|newest-wins|manual`), and `deleteStrategy` (string: `soft-delete|hard-delete|ignore`). This mirrors the sync fields already present on the `Configuration` entity (`syncEnabled`, `syncInterval`, `lastSyncDate`).

#### Scenario: Define a REST API sync source for BAG addresses
- **GIVEN** the admin navigates to Sources management and creates a new sync source
- **WHEN** they configure:
  - Name: `BAG Adressen`
  - Type: `rest-api`
  - URL: `https://api.bag.kadaster.nl/v2/adressen`
  - Authentication: API key header (`X-Api-Key: <key>`)
  - Target register: `bag` (ID: 1), Target schema: `nummeraanduiding` (ID: 3)
  - Mapping: reference to Mapping entity `bag-address-mapping` (ID: 5)
  - Schedule: cron `0 2 * * *` (daily at 02:00)
  - Conflict strategy: `source-wins`
  - Delete strategy: `soft-delete`
- **THEN** the sync source MUST be persisted via `SourceMapper::insert()` with all sync fields populated
- **AND** a `SourceCreatedEvent` MUST be dispatched (per event-driven-architecture spec)
- **AND** the source MUST appear in the Sources list with a "Sync enabled" badge

#### Scenario: Define a CSV file sync source from Nextcloud Files
- **GIVEN** the admin creates a sync source of type `csv-file`
- **WHEN** they configure:
  - Name: `Productenlijst import`
  - File path: `/admin/files/imports/producten.csv` (Nextcloud Files path)
  - Delimiter: `;`, Encoding: `UTF-8`
  - Target schema: `producten`
  - Column mapping: `Productnaam -> title`, `Omschrijving -> description`, `Prijs -> price`
- **THEN** the system MUST validate that the CSV file exists and is readable
- **AND** the system MUST validate the column mapping against the target schema's property definitions
- **AND** unmapped required properties MUST generate a warning with option to set default values

#### Scenario: Define an OData sync source
- **GIVEN** the admin creates a sync source of type `odata`
- **WHEN** they configure:
  - URL: `https://services.odata.org/V4/Northwind/Northwind.svc/Products`
  - Authentication: OAuth2 client credentials
  - Select fields: `ProductID,ProductName,UnitPrice`
  - Filter: `$filter=Discontinued eq false`
- **THEN** the system MUST validate the OData endpoint by issuing a `$metadata` request
- **AND** the system MUST auto-generate a field mapping proposal based on the OData entity type

#### Scenario: Define a SOAP/XML sync source
- **GIVEN** the admin creates a sync source of type `soap`
- **WHEN** they configure:
  - WSDL URL: `https://example.gov.nl/services/brp?wsdl`
  - Operation: `ZoekPersoon`
  - Authentication: certificate-based (mTLS)
  - XPath mapping: `//Persoon/BSN -> bsn`, `//Persoon/Naam/Voornaam -> firstName`
- **THEN** the system MUST parse the WSDL to validate the operation exists
- **AND** the XPath mappings MUST be validated against example response data

#### Scenario: Define a sync source for another OpenRegister instance (federation)
- **GIVEN** the admin creates a sync source of type `openregister`
- **WHEN** they configure:
  - URL: `https://other-instance.example.nl/index.php/apps/openregister/api`
  - Authentication: Basic Auth or API token
  - Source register: `publicaties` (remote), Target register: `publicaties` (local)
  - Source schema: `publicatie` (remote), Target schema: `publicatie` (local)
- **THEN** the system MUST validate connectivity by calling the remote instance's API
- **AND** the system MUST implement anti-loop protection (as in OpenCatalogi's `DirectoryService`) to prevent infinite sync cycles between instances

### Requirement: The sync pipeline MUST follow a three-stage pattern (gather, fetch, import) with per-record status tracking
Each sync execution MUST proceed through three sequential stages: (1) **Gather** -- connect to the source and collect a list of record identifiers to process; (2) **Fetch** -- retrieve the full data for each identified record and store raw fetched data; (3) **Import** -- map, validate, and persist each record into the target register/schema. Each record MUST be tracked individually with status: `new`, `changed`, `unchanged`, `error`, `skipped`. The pipeline MUST be implemented as a Nextcloud `QueuedJob` (like `WebhookDeliveryJob` and `HookRetryJob`) to enable background processing and resumability.

#### Scenario: Three-stage sync execution for a REST API source
- **GIVEN** sync source `BAG Adressen` is triggered (manually or by schedule)
- **WHEN** the sync pipeline starts
- **THEN** Stage 1 (Gather): the system MUST query `GET /v2/adressen?page=1&pageSize=100` and paginate through all pages
  - **AND** store each record identifier (e.g., `nummeraanduidingIdentificatie`) in a `sync_records` tracking table
  - **AND** set each record status to `pending`
  - **AND** log: `"Gather complete: 2,450 records identified"`
- **THEN** Stage 2 (Fetch): for each pending record, the system MUST fetch the full record data
  - **AND** store the raw JSON response per record
  - **AND** update record status to `fetched` or `fetch_error`
- **THEN** Stage 3 (Import): for each fetched record, the system MUST:
  - Apply the configured `Mapping` entity's Twig transformation rules (reusing the existing `Mapping` infrastructure from `lib/Db/Mapping.php`)
  - Validate the mapped data against the target schema's JSON Schema definition
  - Create or update the corresponding `ObjectEntity` via `ObjectService::saveObject()`
  - Update record status to `imported`, `import_error`, or `unchanged`

#### Scenario: Resume after failure mid-pipeline
- **GIVEN** a sync execution failed during Stage 2 (Fetch) after fetching 500 of 1,000 records
- **WHEN** the administrator clicks "Resume" or the retry job fires
- **THEN** the system MUST resume from record 501 using the persisted `sync_records` tracking data
- **AND** already-fetched records (1-500) MUST NOT be re-fetched
- **AND** the sync execution log MUST show the original start time and the resume time

#### Scenario: Pipeline handles paginated API responses
- **GIVEN** a REST API source returns paginated results with `next` link headers or `_links.next`
- **WHEN** the Gather stage runs
- **THEN** the system MUST follow pagination until all pages are exhausted
- **AND** support pagination styles: page-number (`?page=N`), offset-limit (`?offset=N&limit=M`), cursor-based (`?cursor=abc123`), and link-header (`Link: <url>; rel="next"`)
- **AND** respect rate limiting headers (`Retry-After`, `X-RateLimit-Remaining`)

#### Scenario: Pipeline processes records in configurable batch sizes
- **GIVEN** a sync source with `batchSize: 50` configured
- **WHEN** the Fetch and Import stages process 2,450 records
- **THEN** records MUST be processed in batches of 50 using ReactPHP concurrent promises (as in `ImportService`)
- **AND** memory MUST be managed by clearing processed batch data between batches (following `ImportService::DEFAULT_CHUNK_SIZE` pattern)
- **AND** the system MUST log progress: `"Batch 3/49: 150 records processed"`

### Requirement: The system MUST support incremental sync using last-modified tracking or change tokens
The sync system MUST support delta synchronization to avoid re-fetching and re-processing unchanged records. Incremental sync MUST use source-specific mechanisms: `If-Modified-Since` headers (RFC 7232), `lastModified` query parameters, `deltaToken`/`skiptoken` (OData), or source-provided change feeds. The `Source` entity MUST persist the last successful sync checkpoint (timestamp, token, or cursor) for each source.

#### Scenario: Incremental sync with If-Modified-Since header
- **GIVEN** sync source `BAG Adressen` last synced successfully at `2026-03-14T02:00:00Z`
- **WHEN** a new sync starts
- **THEN** the Gather stage MUST send `If-Modified-Since: Sat, 14 Mar 2026 02:00:00 GMT` header
- **AND** the source API returns only 15 modified records (instead of 2,450 total)
- **AND** the sync report MUST show: `"Incremental sync: 15 changed, 2,435 unchanged (not fetched)"`
- **AND** upon completion, `lastSyncDate` MUST be updated to the current execution timestamp

#### Scenario: Incremental sync with source-side change token
- **GIVEN** a sync source supports OData delta tokens
- **WHEN** the source returns `@odata.deltaLink` with token `abc123` at end of sync
- **THEN** the system MUST persist `abc123` as `lastSyncToken` on the Source entity
- **AND** the next Gather stage MUST use `?$deltatoken=abc123` to request only changes since the last sync

#### Scenario: Full resync forced by administrator
- **GIVEN** a sync source with incremental sync enabled and a valid `lastSyncDate`
- **WHEN** the administrator clicks "Full Resync" on the source
- **THEN** the system MUST ignore `lastSyncDate` and `lastSyncToken` for this execution
- **AND** all records MUST be gathered, fetched, and imported from scratch
- **AND** the `lastSyncDate` and `lastSyncToken` MUST be updated upon completion
- **AND** the sync report MUST indicate `"Full resync (manual override)"`

### Requirement: The system MUST support field mapping and transformation via the existing Mapping entity
Each sync source MUST reference a `Mapping` entity (`lib/Db/Mapping.php`) that defines how source fields map to target schema properties. Mappings MUST support Twig templating for value transformation, `unset` rules for removing unwanted fields, `cast` rules for type conversion, and `passThrough` mode for forwarding unmapped fields. This reuses the existing Twig mapping infrastructure rather than creating a parallel system.

#### Scenario: Direct field mapping with Twig templates
- **GIVEN** a Mapping entity with rules:
  ```json
  {
    "mapping": {
      "street": "{{ source.openbareRuimteNaam }}",
      "houseNumber": "{{ source.huisnummer }}",
      "postalCode": "{{ source.postcode }}",
      "city": "{{ source.woonplaatsNaam }}"
    }
  }
  ```
- **WHEN** a source record `{"openbareRuimteNaam": "Kerkstraat", "huisnummer": 42, "postcode": "1234AB", "woonplaatsNaam": "Utrecht"}` is imported
- **THEN** the mapped object MUST be: `{"street": "Kerkstraat", "houseNumber": 42, "postalCode": "1234AB", "city": "Utrecht"}`

#### Scenario: Value transformation with Twig expressions
- **GIVEN** a Mapping with transformation rules:
  ```json
  {
    "mapping": {
      "status": "{{ source.statusCode == 'A' ? 'actief' : (source.statusCode == 'I' ? 'inactief' : 'onbekend') }}",
      "fullAddress": "{{ source.straat }} {{ source.huisnummer }}, {{ source.postcode }} {{ source.plaats }}"
    }
  }
  ```
- **WHEN** a record with `statusCode: "A"` and address fields is imported
- **THEN** `status` MUST be `"actief"` and `fullAddress` MUST be the concatenated address string

#### Scenario: Type casting via Mapping cast rules
- **GIVEN** a Mapping with `"cast": {"price": "float", "quantity": "integer", "isActive": "boolean"}`
- **WHEN** source data contains `{"price": "19.95", "quantity": "100", "isActive": "true"}`
- **THEN** the imported object MUST have `price` as float `19.95`, `quantity` as integer `100`, `isActive` as boolean `true`

#### Scenario: Auto-generate mapping proposal from source schema
- **GIVEN** a sync source of type `rest-api` with a discoverable schema (OpenAPI spec, JSON Schema, or OData metadata)
- **WHEN** the admin creates or edits the sync source
- **THEN** the system MUST offer to auto-generate a `Mapping` entity by matching source field names to target schema property names
- **AND** exact name matches MUST be mapped automatically, while fuzzy matches (e.g., `straatnaam` to `street`) MUST be suggested for manual confirmation

### Requirement: Sync MUST support create, update, and delete operations with configurable strategies
The import stage MUST determine whether each record is new (create), changed (update), or removed (delete) by comparing source data to existing register objects. Record matching MUST use a configurable identity field (external ID, UUID, or composite key). Delete handling MUST be configurable per source: `soft-delete` (set status to `inactive`), `hard-delete` (remove from register), or `ignore` (leave orphaned records).

#### Scenario: Create new objects from sync
- **GIVEN** 10 source records with external IDs that do not match any existing object's `_sourceId` field
- **WHEN** the Import stage processes these records
- **THEN** 10 new `ObjectEntity` instances MUST be created via `ObjectService::saveObject()`
- **AND** each object MUST store the external ID in metadata field `_sourceId` and source reference in `_syncSourceId`
- **AND** 10 `ObjectCreatedEvent` events MUST be dispatched (per event-driven-architecture spec)

#### Scenario: Update existing objects with change detection
- **GIVEN** source record `addr-1` has field `woonplaatsNaam` changed from `"Utrecht"` to `"Amersfoort"` since last sync
- **AND** the register has an object with `_sourceId: "addr-1"`
- **WHEN** the Import stage processes this record
- **THEN** a content hash comparison MUST detect the change
- **AND** the existing object MUST be updated with the new mapped data
- **AND** the audit trail MUST record the update with actor `system/sync/<source-uuid>`
- **AND** an `ObjectUpdatedEvent` MUST be dispatched with both old and new state

#### Scenario: Detect and handle deleted source records (soft-delete)
- **GIVEN** sync source configured with `deleteStrategy: "soft-delete"`
- **AND** source record `addr-5` existed in the previous sync but is absent from the current Gather results
- **WHEN** the Import stage completes
- **THEN** the object with `_sourceId: "addr-5"` MUST have its `status` set to `inactive`
- **AND** the audit trail MUST record: `"Soft-deleted by sync: source record no longer present"`
- **AND** the sync report MUST list `addr-5` under "Deleted records"

#### Scenario: Skip unchanged records
- **GIVEN** source record `addr-2` has identical content hash to the last synced version
- **WHEN** the Import stage processes this record
- **THEN** no update MUST be performed
- **AND** the record status MUST be set to `unchanged`
- **AND** no `ObjectUpdatedEvent` MUST be dispatched
- **AND** the sync report MUST count this record as "unchanged/skipped"

### Requirement: Sync MUST support conflict resolution with configurable strategies
When both the source and local register have modified the same record since the last sync, the system MUST detect the conflict and apply the configured resolution strategy. Strategies MUST include: `source-wins` (overwrite local changes), `local-wins` (keep local changes, skip source update), `newest-wins` (compare timestamps, keep the most recent), and `manual` (flag for administrator review).

#### Scenario: Source-wins conflict resolution
- **GIVEN** sync source configured with `conflictStrategy: "source-wins"`
- **AND** object `addr-1` was modified locally at `2026-03-18T10:00:00Z` and in the source at `2026-03-18T08:00:00Z`
- **WHEN** the Import stage detects both sides have changed since last sync
- **THEN** the source data MUST overwrite the local changes
- **AND** the audit trail MUST record: `"Conflict resolved: source-wins (local changes overwritten)"`

#### Scenario: Manual conflict resolution queue
- **GIVEN** sync source configured with `conflictStrategy: "manual"`
- **AND** 3 records have conflicts detected during import
- **WHEN** the Import stage encounters these conflicts
- **THEN** the 3 records MUST be flagged with status `conflict` in the sync tracking table
- **AND** an admin notification MUST be sent via Nextcloud's notification system
- **AND** the admin MUST be able to view a conflict resolution UI showing local vs. source data side-by-side
- **AND** the admin MUST be able to choose per-record: accept source, keep local, or manually merge

#### Scenario: Newest-wins with timestamp comparison
- **GIVEN** sync source configured with `conflictStrategy: "newest-wins"`
- **AND** local modification at `2026-03-18T14:00:00Z`, source modification at `2026-03-18T16:00:00Z`
- **WHEN** the Import stage detects the conflict
- **THEN** the source version MUST win because `16:00 > 14:00`
- **AND** the audit trail MUST record: `"Conflict resolved: newest-wins (source: 2026-03-18T16:00:00Z > local: 2026-03-18T14:00:00Z)"`

### Requirement: Sync executions MUST produce detailed monitoring reports and maintain execution history
Each sync execution MUST produce a comprehensive execution report and all reports MUST be persisted for historical review. The system MUST expose sync status via the API and the admin UI. This mirrors the pattern already established by `SyncConfigurationsJob` which tracks `synced`, `skipped`, and `failed` counts and updates `lastSyncDate` and status on the `Configuration` entity.

#### Scenario: View sync execution report after completion
- **GIVEN** a sync execution for source `BAG Adressen` has completed
- **WHEN** the admin views the execution report
- **THEN** the report MUST show:
  - Execution ID, source name, source UUID
  - Start time, end time, duration
  - Status: `success`, `partial` (some records failed), or `failed`
  - Sync type: `incremental` or `full`
  - Stage timings: gather duration, fetch duration, import duration
  - Record counts: gathered, fetched, imported, created, updated, unchanged, deleted, errored, skipped
  - Error details: for each failed record, the record identifier, stage of failure, and error message
  - Bytes transferred, API calls made

#### Scenario: View sync execution history with trend analysis
- **GIVEN** source `BAG Adressen` has 30 sync execution reports over the past month
- **WHEN** the admin views the sync history
- **THEN** the system MUST display a chronological list with status icons (green/yellow/red)
- **AND** show trend metrics: average duration, average record count, error rate trend
- **AND** allow filtering by status (`success`, `partial`, `failed`) and date range

#### Scenario: Real-time sync progress monitoring
- **GIVEN** a sync execution is currently running for source `BAG Adressen`
- **WHEN** the admin views the source details
- **THEN** the UI MUST show real-time progress: `"Stage 2/3 (Fetch): 1,200/2,450 records (49%)"`
- **AND** estimated time remaining based on current processing rate
- **AND** a "Cancel" button MUST be available to abort the running sync

#### Scenario: API endpoint for sync status
- **GIVEN** an external monitoring system needs to check sync health
- **WHEN** it calls `GET /api/sources/{id}/sync-status`
- **THEN** the API MUST return: `{"status": "success", "lastSyncDate": "2026-03-19T02:15:00Z", "recordsProcessed": 2450, "nextScheduledRun": "2026-03-20T02:00:00Z"}`

### Requirement: The system MUST handle errors gracefully with partial failure support and automatic retry
Individual record failures during any pipeline stage MUST NOT abort the entire sync execution. Failed records MUST be logged with error details and retried according to a configurable retry policy. The retry mechanism MUST follow the pattern established by `HookRetryJob` (`lib/BackgroundJob/HookRetryJob.php`) which uses Nextcloud's `IJobList` for queued retry jobs with exponential backoff.

#### Scenario: Partial failure during import with continuation
- **GIVEN** 2,450 records are being imported
- **AND** records at positions 150, 800, and 2,100 fail schema validation
- **WHEN** the Import stage processes all records
- **THEN** 2,447 records MUST be successfully imported
- **AND** 3 records MUST be marked as `import_error` with validation error details
- **AND** the overall sync status MUST be `partial` (not `failed`)
- **AND** the sync report MUST list the 3 failed records with actionable error messages

#### Scenario: Automatic retry with exponential backoff
- **GIVEN** a sync source configured with `retryPolicy: {"maxRetries": 3, "backoffMultiplier": 2, "initialDelay": 60}`
- **AND** a record fails during Fetch due to a transient HTTP 503 error
- **WHEN** the retry policy is applied
- **THEN** retry 1 MUST be scheduled after 60 seconds
- **AND** retry 2 MUST be scheduled after 120 seconds (60 * 2)
- **AND** retry 3 MUST be scheduled after 240 seconds (60 * 2 * 2)
- **AND** if all 3 retries fail, the record MUST be marked as `permanent_error`

#### Scenario: Source API completely unavailable
- **GIVEN** the source API returns HTTP 500 for all requests during the Gather stage
- **WHEN** the system attempts to start the sync
- **THEN** the sync MUST fail immediately with status `failed` and reason `"Source API unavailable: HTTP 500"`
- **AND** the system MUST NOT attempt Fetch or Import stages
- **AND** the next scheduled sync MUST still run at the configured time

### Requirement: Authentication credentials for external sources MUST be stored securely
Sync source authentication credentials MUST be stored encrypted in the database, never logged in plaintext, and accessible only to administrators. The system MUST support multiple authentication methods: none, API key (header or query parameter), HTTP Basic, OAuth2 (client credentials, authorization code), and mutual TLS (certificate-based).

#### Scenario: Store API key credentials encrypted
- **GIVEN** the admin configures a sync source with API key authentication
- **WHEN** they enter the API key `sk_live_abc123xyz789`
- **THEN** the key MUST be encrypted using Nextcloud's `ICredentialsManager` or `ICrypto` before database storage
- **AND** the API response for the source MUST mask the key as `sk_live_***789`
- **AND** server logs MUST never contain the plaintext key

#### Scenario: OAuth2 client credentials flow
- **GIVEN** a sync source configured with OAuth2 authentication
- **WHEN** the sync pipeline starts
- **THEN** the system MUST first obtain an access token from the configured token endpoint using client credentials
- **AND** cache the access token until expiry (respecting `expires_in`)
- **AND** use the bearer token for all Gather and Fetch API calls
- **AND** automatically refresh the token if a 401 response is received mid-sync

#### Scenario: Credential rotation without sync disruption
- **GIVEN** an admin updates the API key for sync source `BAG Adressen`
- **WHEN** a sync is currently running with the old key
- **THEN** the running sync MUST complete with the old key
- **AND** the next sync MUST use the new key
- **AND** the credential change MUST be recorded in the audit trail

### Requirement: Imported data MUST be validated against the target schema before persistence
Every record entering the Import stage MUST be validated against the target schema's JSON Schema definition before being persisted. Validation MUST cover: required properties, type constraints, format validators (email, URI, date), enum restrictions, string length limits, and numeric ranges. This reuses the existing schema validation infrastructure in `ObjectService::saveObject()`.

#### Scenario: Valid record passes schema validation
- **GIVEN** target schema `nummeraanduiding` requires properties `identificatie` (string, 16 chars), `postcode` (pattern: `^\d{4}[A-Z]{2}$`), and `huisnummer` (integer, min: 1)
- **WHEN** a mapped record `{"identificatie": "0307200000012345", "postcode": "3511AB", "huisnummer": 42}` is validated
- **THEN** validation MUST pass and the record MUST be persisted

#### Scenario: Invalid record fails validation with detailed errors
- **GIVEN** the same schema requirements
- **WHEN** a mapped record `{"identificatie": "short", "postcode": "invalid", "huisnummer": -1}` is validated
- **THEN** validation MUST fail with errors:
  - `"identificatie: String length 5 is less than minimum 16"`
  - `"postcode: 'invalid' does not match pattern ^\d{4}[A-Z]{2}$"`
  - `"huisnummer: -1 is less than minimum 1"`
- **AND** the record MUST be marked as `import_error` with these validation messages

#### Scenario: Validation mode configurable (strict vs. lenient)
- **GIVEN** a sync source with `validationMode: "lenient"`
- **WHEN** a record has a non-required property with an invalid format
- **THEN** the system MUST log a warning but still import the record with the invalid value
- **AND** the sync report MUST flag these as "imported with warnings"

### Requirement: The system MUST maintain a complete sync audit trail integrated with the existing audit system
All sync operations MUST be recorded in the audit trail with the sync source as the actor. Audit entries MUST distinguish sync-originated changes from user-originated changes. The audit trail MUST support tracing any object back to its sync source and the specific sync execution that created or last modified it.

#### Scenario: Audit trail for sync-created objects
- **GIVEN** a sync execution creates 50 new objects
- **WHEN** an administrator views the audit trail for one of these objects
- **THEN** the creation entry MUST show:
  - Actor: `system/sync/bag-adressen` (source UUID)
  - Action: `created`
  - Sync execution ID reference
  - Source record identifier (`_sourceId`)
- **AND** the object's metadata MUST contain `_syncSourceId`, `_sourceId`, `_lastSyncExecutionId`, and `_lastSyncDate`

#### Scenario: Audit trail distinguishes sync vs. manual changes
- **GIVEN** object `addr-1` was created by sync and later manually edited by user `admin`
- **WHEN** the next sync updates `addr-1` (source-wins conflict resolution)
- **THEN** the audit trail MUST show three entries:
  1. Created by `system/sync/bag-adressen` (sync execution #1)
  2. Updated by `admin` (manual edit)
  3. Updated by `system/sync/bag-adressen` (sync execution #2, conflict: source-wins, local changes overwritten)

#### Scenario: Bulk audit trail query for sync execution
- **GIVEN** sync execution #42 processed 2,450 records
- **WHEN** the admin queries `GET /api/audit-trail?syncExecutionId=42`
- **THEN** the API MUST return all audit entries created during that execution
- **AND** support filtering by action (`created`, `updated`, `deleted`) and status (`success`, `error`)

### Requirement: The system MUST support bi-directional sync for federated OpenRegister instances
For sync sources of type `openregister` (instance-to-instance federation), the system MUST support pushing local changes back to the source instance. Bi-directional sync MUST implement anti-loop detection (as in OpenCatalogi's `DirectoryService` which uses broadcast headers to prevent infinite sync cycles) and conflict resolution.

#### Scenario: Push local changes to remote OpenRegister instance
- **GIVEN** a bi-directional sync source connecting local and remote OpenRegister instances
- **AND** a local object `pub-1` is modified by a local user
- **WHEN** the next outbound sync runs
- **THEN** the system MUST push the updated object to the remote instance via its API
- **AND** the push request MUST include an `X-OpenRegister-Sync-Origin: <local-instance-id>` header
- **AND** the remote instance MUST recognize this header and skip re-syncing the change back (anti-loop)

#### Scenario: Anti-loop protection prevents infinite sync cycles
- **GIVEN** Instance A syncs with Instance B, and Instance B syncs with Instance A
- **WHEN** a record is modified on Instance A and synced to Instance B
- **THEN** Instance B MUST detect the `X-OpenRegister-Sync-Origin` header matching Instance A
- **AND** Instance B MUST NOT re-sync this change back to Instance A
- **AND** the sync log on Instance B MUST record: `"Skipped re-sync to origin instance A"`

#### Scenario: Federation with schema version mismatch
- **GIVEN** local schema `publicatie` is at version `2.1.0` and remote schema is at version `1.5.0`
- **WHEN** the sync attempts to pull remote records
- **THEN** the system MUST detect the schema version mismatch
- **AND** apply backward-compatible mapping if the major version matches (2.x to 1.x = breaking, warning)
- **AND** log a warning: `"Schema version mismatch: remote 1.5.0, local 2.1.0 — some fields may not map correctly"`

### Requirement: The system MUST support webhook-triggered and event-triggered sync in addition to scheduled sync
Beyond cron-based scheduling, sync MUST be triggerable by inbound webhooks (push-based sync) and by events from the event-driven-architecture. This enables real-time data propagation when sources support webhook notifications. The webhook endpoint MUST validate incoming payloads using HMAC signatures or shared secrets.

#### Scenario: Webhook-triggered sync from external source
- **GIVEN** sync source `BAG Adressen` has a webhook endpoint registered: `POST /api/sources/{id}/webhook`
- **WHEN** the BAG API sends a webhook notification: `{"event": "record.updated", "id": "0307200000012345"}`
- **THEN** the system MUST validate the webhook signature using the configured HMAC secret
- **AND** trigger a targeted sync for only the changed record (not a full sync)
- **AND** the single record MUST go through the full fetch-and-import pipeline

#### Scenario: Event-triggered sync when related data changes
- **GIVEN** a workflow is configured to trigger sync source `HR Bedrijven` when a `klant` object's `kvkNummer` is updated
- **WHEN** the `ObjectUpdatedEvent` fires for the klant object with changed `kvkNummer`
- **THEN** the system MUST trigger a targeted sync from the KvK API for the specific company
- **AND** update the related `bedrijf` object in the register with fresh data from the HR API

#### Scenario: Manual one-click sync trigger
- **GIVEN** the admin views sync source `BAG Adressen` in the admin UI
- **WHEN** they click the "Sync Now" button
- **THEN** the system MUST immediately queue a sync execution as a `QueuedJob`
- **AND** redirect the admin to the execution monitoring view
- **AND** the manual trigger MUST respect the same pipeline stages and error handling as scheduled syncs

### Requirement: Sync performance MUST be optimized with configurable batch sizes, throttling, and concurrency limits
The sync pipeline MUST provide performance controls to prevent overloading source APIs, the local database, or available memory. Controls MUST include: batch size (records per processing chunk), concurrency limit (parallel fetch/import operations), throttle delay (milliseconds between API calls), maximum records per execution, and timeout per record. These settings follow the patterns established in `ImportService` (`DEFAULT_CHUNK_SIZE = 5`, `MINIMAL_CHUNK_SIZE = 2`, `MAX_CONCURRENT_OPERATIONS`).

#### Scenario: Throttled API access to respect rate limits
- **GIVEN** sync source configured with `throttleDelay: 200` (milliseconds between API calls)
- **AND** the source API returns `X-RateLimit-Remaining: 10` and `X-RateLimit-Reset: 1679788800`
- **WHEN** the Fetch stage makes API calls
- **THEN** the system MUST wait at least 200ms between consecutive API calls
- **AND** when `X-RateLimit-Remaining` drops below 5, the system MUST pause until the reset time
- **AND** the sync report MUST include total wait time due to throttling

#### Scenario: Memory-bounded batch processing
- **GIVEN** sync source configured with `batchSize: 25` and `maxConcurrency: 5`
- **WHEN** processing 2,450 records in the Import stage
- **THEN** records MUST be processed in batches of 25
- **AND** within each batch, at most 5 records MUST be processed concurrently using ReactPHP promises
- **AND** each completed batch MUST free its memory before the next batch starts
- **AND** PHP memory usage MUST stay below the configured `memory_limit`

#### Scenario: Maximum records limit prevents runaway syncs
- **GIVEN** sync source configured with `maxRecordsPerExecution: 10000`
- **WHEN** the Gather stage identifies 50,000 records
- **THEN** the system MUST process only the first 10,000 records in this execution
- **AND** log: `"Record limit reached: 10,000/50,000. Remaining records will be processed in the next execution."`
- **AND** persist a cursor/offset so the next execution continues from record 10,001

### Requirement: Sync MUST respect multi-tenant organisation isolation
In a multi-tenant OpenRegister deployment, sync sources and their imported data MUST be scoped to the owning organisation. The `Source` entity already has an `organisation` field enforced by `MultiTenancyTrait` in `SourceMapper`. Sync operations MUST inherit this isolation: a source owned by Organisation A MUST only create/update objects visible to Organisation A.

#### Scenario: Sync creates objects within the source's organisation scope
- **GIVEN** sync source `BAG Adressen` belongs to organisation `gemeente-utrecht` (UUID: `org-123`)
- **WHEN** the Import stage creates new objects
- **THEN** all created objects MUST have their `organisation` field set to `org-123`
- **AND** objects MUST be visible only to users who are members of `gemeente-utrecht`
- **AND** the sync execution itself MUST be logged under `gemeente-utrecht`

#### Scenario: Organisation admin can only manage their own sync sources
- **GIVEN** user `admin-utrecht` is an admin of organisation `gemeente-utrecht`
- **AND** user `admin-amsterdam` is an admin of organisation `gemeente-amsterdam`
- **WHEN** `admin-utrecht` lists sync sources via `GET /api/sources`
- **THEN** only sources belonging to `gemeente-utrecht` MUST be returned (enforced by `SourceMapper::applyOrganisationFilter()`)
- **AND** attempting to trigger sync for a source owned by `gemeente-amsterdam` MUST return HTTP 403

#### Scenario: Cross-organisation sync via shared registers
- **GIVEN** a register `landelijke-producten` is shared across organisations
- **AND** a sync source owned by `gemeente-utrecht` imports into this shared register
- **WHEN** objects are created by the sync
- **THEN** objects MUST be visible to all organisations that have access to the shared register
- **AND** the objects MUST still track their sync source origin (`_syncSourceId`) for audit purposes

### Requirement: Scheduled sync MUST use Nextcloud's BackgroundJob infrastructure with configurable intervals
Sync scheduling MUST be implemented as Nextcloud `TimedJob` instances (following the pattern of `SyncConfigurationsJob` which runs hourly and checks each configuration's `syncInterval`). Each sync source MUST support independent scheduling via cron expressions or interval-based timing. The scheduler MUST handle overlapping executions by skipping a run if the previous execution is still in progress.

#### Scenario: Cron-based scheduling with interval check
- **GIVEN** sync source `BAG Adressen` configured with `syncInterval: 24` (hours) and `syncEnabled: true`
- **AND** `lastSyncDate` is `2026-03-18T02:00:00Z`
- **WHEN** the `SyncDataJob` TimedJob runs at `2026-03-19T02:00:00Z` (24 hours later)
- **THEN** the system MUST determine the source is due for sync (`hoursPassed >= syncInterval`)
- **AND** queue a sync execution for this source

#### Scenario: Skip execution if previous sync still running
- **GIVEN** sync source `BAG Adressen` has a running sync execution (status: `running`)
- **WHEN** the scheduler checks if a new sync should start
- **THEN** the system MUST skip this source with log: `"Skipping BAG Adressen: previous sync still running (started 2026-03-19T02:00:00Z)"`
- **AND** NOT queue a new execution

#### Scenario: Multiple sources with independent schedules
- **GIVEN** three sync sources:
  - `BAG Adressen`: every 24 hours
  - `KvK Bedrijven`: every 6 hours
  - `Productenlijst CSV`: every 1 hour
- **WHEN** the master `SyncDataJob` runs hourly
- **THEN** each source MUST be independently evaluated against its own `syncInterval` and `lastSyncDate`
- **AND** only due sources MUST be queued for execution

## Using Mock Register Data

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
- **Conflict resolution**: Manually edit a synced BAG record, then re-sync to test source-wins/local-wins/manual strategies
- **Multi-tenant isolation**: Create two organisations, assign sync sources to each, verify objects are scoped correctly

## Current Implementation Status
- **Existing foundations:**
  - `Source` entity (`lib/Db/Source.php`) with fields: uuid, title, version, description, databaseUrl, type, organisation -- represents an external data source with multi-tenancy via `MultiTenancyTrait`
  - `SourceMapper` (`lib/Db/SourceMapper.php`) with CRUD, RBAC verification, and organisation filtering
  - `SyncConfigurationsJob` (`lib/Cron/SyncConfigurationsJob.php`) -- hourly TimedJob that syncs configurations from GitHub, GitLab, URL, and local sources with `isDueForSync()` interval checking and `synced/skipped/failed` status tracking
  - `Configuration` entity has sync fields: `syncEnabled`, `syncInterval`, `lastSyncDate`, `sourceType`, `sourceUrl` -- same pattern needed for `Source` entity
  - `ImportService` (`lib/Service/ImportService.php`) -- handles CSV and Excel import with ReactPHP chunked processing, concurrency limits, and progress tracking
  - `Mapping` entity (`lib/Db/Mapping.php`) -- Twig-based field transformation with mapping rules, unset, cast, and passThrough modes
  - `ConfigurationService` with `ImportHandler` (`lib/Service/Configuration/ImportHandler.php`) -- handles configuration imports from external sources
  - `WebhookService` (`lib/Service/WebhookService.php`) -- webhook delivery with CloudEvents formatting and retry via `WebhookDeliveryJob`
  - `HookRetryJob` (`lib/BackgroundJob/HookRetryJob.php`) -- queued retry with exponential backoff pattern
  - Event-driven architecture with 39+ typed event classes and `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectDeletedEvent`
  - OpenCatalogi `DirectoryService` (`opencatalogi/lib/Service/DirectoryService.php`) -- async federation sync with anti-loop protection using broadcast headers
  - Frontend source management views at `src/views/source/`
- **NOT yet implemented:**
  - Three-stage sync pipeline (gather, fetch, import) for data sources
  - Sync-specific fields on `Source` entity (syncEnabled, syncSchedule, authType, authConfig, mappingId, conflictStrategy, deleteStrategy)
  - Per-record status tracking table (`sync_records`)
  - REST API, OData, and SOAP sync source handlers
  - OpenRegister-to-OpenRegister federation sync with anti-loop protection
  - Incremental sync with last-modified tracking and change tokens
  - Conflict resolution strategies (source-wins, local-wins, newest-wins, manual)
  - Sync execution monitoring, reporting, and history persistence
  - Webhook-triggered and event-triggered sync
  - Encrypted credential storage for source authentication
  - Bi-directional sync (push local changes to remote)
  - Performance controls (batch size, throttling, concurrency limits)
  - Sync-specific `SyncDataJob` background job
  - Real-time sync progress monitoring in UI
  - Automatic retry for failed records with exponential backoff

## Cross-References
- **data-import-export**: One-shot file import (CSV/Excel) via `ImportService` -- sync extends this with scheduled, repeatable, API-based imports. The batch processing and ReactPHP concurrency patterns from `ImportService` MUST be reused.
- **workflow-integration**: Workflows can trigger syncs (event-triggered sync) and syncs can trigger workflows (synced objects dispatch events that workflows listen to). The n8n integration enables complex sync orchestration beyond the built-in pipeline.
- **event-driven-architecture**: All sync-created/updated/deleted objects MUST dispatch the standard typed events (`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`). Webhooks configured on schemas MUST fire for sync-originated changes.
- **audit-trail**: Sync operations MUST use the existing audit trail infrastructure with `system/sync/<source-uuid>` as the actor.
- **multi-tenancy**: Sync sources and their imported data MUST respect organisation isolation via the existing `MultiTenancyTrait` on `SourceMapper`.

## Standards & References
- **CKAN ckanext-harvest** -- Reference implementation for three-stage pipeline (gather/fetch/import) with `IHarvester` interface and per-record status tracking
- **OpenCatalogi DirectoryService** -- Reference implementation for Nextcloud-native async federation sync with anti-loop protection
- **DCAT (Data Catalog Vocabulary)** -- W3C standard for describing data catalogs and datasets
- **OAI-PMH (Open Archives Initiative Protocol for Metadata Harvesting)** -- Harvesting protocol for metadata
- **BAG API (Kadaster)** -- Reference implementation for Dutch base registration sync
- **BRK, BRP, HR APIs** -- Dutch government base registration APIs
- **Haal Centraal** -- VNG initiative for modern government API access
- **OData v4** -- OASIS standard for RESTful APIs with delta query support
- **RFC 7232** -- Conditional requests (If-Modified-Since) for incremental sync
- **CloudEvents v1.0** -- Event format for webhook payloads (already used by `WebhookService`)
- **Nextcloud BackgroundJob** -- `TimedJob` for scheduled sync, `QueuedJob` for execution pipeline
- **Nextcloud ICrypto / ICredentialsManager** -- Secure credential storage for source authentication
