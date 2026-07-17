---
status: done
---

# Retention Management

## Purpose

@e2e exclude backend MDTO retention lifecycle — covered by PHPUnit
Implement retention lifecycle management for register objects: MDTO-compliant archival metadata, selectielijsten, archiefactiedatum calculation, destruction scheduling with approval workflows, legal holds, and notifications per Archiefwet 1995.
## Requirements
### Requirement: Objects MUST carry MDTO-compliant archival metadata in the retention field
Each object MUST carry archival metadata fields conforming to the MDTO standard within the existing `ObjectEntity.retention` JSON field. These fields enable retention lifecycle management per the Archiefwet 1995.

#### Scenario: Default archival metadata on object creation with archive-enabled schema
- **GIVEN** a schema with `archive.enabled` set to `true` and `archive.defaultNominatie` set to `vernietigen` and `archive.defaultBewaartermijn` set to `P5Y`
- **WHEN** a new object is created without explicit archival metadata
- **THEN** the object's `retention` field MUST include:
  - `archiefnominatie`: `vernietigen`
  - `archiefstatus`: `nog_te_archiveren`
  - `bewaartermijn`: `P5Y`
  - `archiefactiedatum`: creation date plus 5 years in ISO 8601 format
  - `classificatie`: `null` (until selectielijst mapping is configured)

#### Scenario: Archival metadata defaults to undetermined when schema has no archive config
- **GIVEN** a schema without `archive` configuration
- **WHEN** a new object is created
- **THEN** the object's `retention` field MUST NOT include archival metadata fields
- **AND** the object MUST behave as before this change (backward compatible)

#### Scenario: Destroyed objects cannot be modified
- **GIVEN** an object with `retention.archiefstatus` set to `vernietigd`
- **WHEN** a user attempts to update the object's data via PUT or PATCH
- **THEN** the system MUST reject the update with HTTP 409 Conflict
- **AND** the response body MUST include error code `OBJECT_DESTROYED`

#### Scenario: Transferred objects become read-only
- **GIVEN** an object with `retention.archiefstatus` set to `overgebracht`
- **WHEN** a user attempts to update the object
- **THEN** the system MUST reject the update with HTTP 409 Conflict
- **AND** the response body MUST include error code `OBJECT_TRANSFERRED`

#### Scenario: Archival metadata exposed in API responses
- **GIVEN** an object with archival metadata populated in `retention`
- **WHEN** the object is retrieved via `GET /api/objects/{register}/{schema}/{id}`
- **THEN** the response MUST include the full `retention` field containing all archival metadata
- **AND** the `retention.archiefnominatie` and `retention.archiefstatus` fields MUST be filterable in list/search queries

### Requirement: The system MUST support configurable selectielijsten as register objects
Selectielijsten (selection lists) MUST be stored as regular register objects within OpenRegister, mapping object types to retention periods and archival actions per the Selectielijst gemeenten en intergemeentelijke organen (VNG).

#### Scenario: Create a selectielijst entry
- **GIVEN** a register and schema designated for selectielijst management via app settings
- **WHEN** an admin creates a selectielijst entry object with properties `categorie`, `omschrijving`, `bewaartermijn` (ISO 8601 duration), `archiefnominatie`, `bron`, and `toelichting`
- **THEN** the entry MUST be created as a standard register object
- **AND** the entry MUST be retrievable via the standard object API

#### Scenario: Map schema to selectielijst category
- **GIVEN** a selectielijst entry with `categorie` `B1` and `bewaartermijn` `P5Y` and `archiefnominatie` `vernietigen`
- **AND** a schema with `archive.classificatie` set to `B1`
- **WHEN** a new object is created in this schema
- **THEN** the system MUST look up the selectielijst entry for `B1`
- **AND** apply `bewaartermijn` `P5Y` and `archiefnominatie` `vernietigen` to the object's `retention` field
- **AND** calculate `archiefactiedatum` based on the configured afleidingswijze

#### Scenario: Schema-level override of selectielijst retention
- **GIVEN** selectielijst category `A1` has `bewaartermijn` `P10Y`
- **AND** schema `vertrouwelijk-dossier` has `archive.bewaartermijnOverride` set to `P20Y`
- **WHEN** a new object is created in `vertrouwelijk-dossier`
- **THEN** the object MUST use `P20Y` as its `bewaartermijn` instead of the selectielijst's `P10Y`
- **AND** the override MUST be recorded in the audit trail with reason from `archive.overrideReason`

#### Scenario: Import selectielijst via bulk object creation
- **GIVEN** a CSV file containing VNG selectielijst entries
- **WHEN** an admin imports the file using the existing data import mechanism targeting the selectielijst schema
- **THEN** entries MUST be created or updated (matched on `categorie` code) as standard register objects
- **AND** the import log MUST report created, updated, and skipped counts

### Requirement: The system MUST calculate archiefactiedatum using configurable afleidingswijzen
The `archiefactiedatum` MUST be calculable from multiple derivation methods configured per schema in `Schema.archive.afleidingswijze`.

#### Scenario: Calculate from afgehandeld (closure date)
- **GIVEN** a schema with `archive.afleidingswijze` set to `afgehandeld` and `bewaartermijn` `P5Y`
- **AND** an object is updated with a status indicating closure (configurable status field via `archive.closureField`)
- **WHEN** the system processes the status change
- **THEN** `archiefactiedatum` MUST be set to closure date plus 5 years

#### Scenario: Calculate from eigenschap (property value)
- **GIVEN** a schema with `archive.afleidingswijze` set to `eigenschap` and `archive.bronEigenschap` set to `vervaldatum` and `bewaartermijn` `P10Y`
- **AND** an object has property `vervaldatum` set to `2028-06-15`
- **WHEN** the system calculates the archiefactiedatum
- **THEN** `archiefactiedatum` MUST be set to `2038-06-15`

#### Scenario: Calculate from termijn (closure plus process term)
- **GIVEN** a schema with `archive.afleidingswijze` set to `termijn` and `archive.procestermijn` set to `P2Y` and `bewaartermijn` `P5Y`
- **AND** an object is closed on `2026-01-01`
- **WHEN** the system calculates the archiefactiedatum
- **THEN** the brondatum MUST be `2028-01-01` (closure + procestermijn)
- **AND** `archiefactiedatum` MUST be `2033-01-01` (brondatum + bewaartermijn)

#### Scenario: Recalculate when source property changes
- **GIVEN** an object with `archive.afleidingswijze` `eigenschap` pointing to `vervaldatum`
- **AND** current `archiefactiedatum` is `2038-06-15`
- **WHEN** the `vervaldatum` property is updated to `2030-12-31`
- **THEN** `archiefactiedatum` MUST be recalculated to `2040-12-31`
- **AND** an audit trail entry MUST be created recording the change

### Requirement: The system MUST generate destruction lists via a background job
Objects past their `archiefactiedatum` with `archiefnominatie` `vernietigen` MUST be automatically identified and grouped into destruction lists.

#### Scenario: DestructionCheckJob generates a destruction list
- **GIVEN** 15 objects have `archiefactiedatum` before today and `archiefnominatie` set to `vernietigen` and `archiefstatus` `nog_te_archiveren`
- **WHEN** the `DestructionCheckJob` (TimedJob) runs on its configured schedule
- **THEN** a destruction list MUST be created as a register object containing:
  - References (UUIDs) to all 15 eligible objects
  - Status `in_review`
  - For each object: title, schema, register, UUID, archiefactiedatum, classificatie
- **AND** an INotification MUST be sent to users in the `archivaris` Nextcloud group

#### Scenario: Objects already on a pending destruction list are excluded
- **GIVEN** 10 objects are eligible for destruction
- **AND** 8 of them already appear on an existing destruction list with status `in_review`
- **WHEN** the `DestructionCheckJob` runs
- **THEN** only the 2 new objects MUST be added to a new destruction list

#### Scenario: Configurable destruction check interval
- **GIVEN** the admin sets `retention.destructionCheckInterval` to `604800` (weekly in seconds)
- **WHEN** the `DestructionCheckJob` is registered
- **THEN** it MUST run at the configured interval instead of the default daily interval

#### Scenario: Soft-deleted objects included in destruction lists
- **GIVEN** 3 eligible objects have been soft-deleted (have `deleted` field set)
- **WHEN** the `DestructionCheckJob` generates a destruction list
- **THEN** soft-deleted objects MUST be included and marked as `softDeleted: true` in the list

### Requirement: Destruction MUST follow a multi-step approval workflow
Destruction lists MUST be reviewed and approved by authorized users before objects are permanently deleted, per Archiefbesluit 1995 Articles 6-8.

#### Scenario: Approve entire destruction list
- **GIVEN** a destruction list with 15 objects and status `in_review`
- **WHEN** a user in the `archivaris` group approves the list via `POST /api/retention/destruction-lists/{id}/approve`
- **THEN** the status MUST change to `approved`
- **AND** a `DestructionExecutionJob` (QueuedJob) MUST be queued to process the destruction
- **AND** an audit trail entry MUST be created with action `archival.destruction_approved`

#### Scenario: Partially reject destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist excludes 3 objects (with reasons) and approves the remaining 12
- **THEN** only the 12 approved objects MUST be queued for destruction
- **AND** the 3 excluded objects MUST have `archiefactiedatum` extended by the configured extension period (default: `P1Y`)
- **AND** each exclusion reason MUST be recorded in the destruction list object

#### Scenario: Reject entire destruction list
- **GIVEN** a destruction list with 15 objects
- **WHEN** the archivist rejects the list with a reason via `POST /api/retention/destruction-lists/{id}/reject`
- **THEN** no objects MUST be destroyed
- **AND** the status MUST change to `rejected`
- **AND** all objects MUST have `archiefactiedatum` extended by the configured extension period

#### Scenario: Two-step approval for sensitive schemas
- **GIVEN** a schema with `archive.requireDualApproval` set to `true`
- **AND** a destruction list contains objects from this schema
- **WHEN** the first archivist approves
- **THEN** the status MUST change to `awaiting_second_approval`
- **AND** a different archivist MUST provide second approval before destruction proceeds

#### Scenario: Destruction execution processes objects in batches
- **GIVEN** an approved destruction list with 200 objects
- **WHEN** the `DestructionExecutionJob` runs
- **THEN** objects MUST be destroyed in batches of the configured size (default: 50)
- **AND** each destroyed object MUST have an audit trail entry with action `archival.destroyed` referencing the destruction list UUID
- **AND** the destruction list status MUST change to `executed` when all objects are processed

### Requirement: The system MUST generate destruction certificates
After a destruction list is fully executed, the system MUST produce a destruction certificate (verklaring van vernietiging) as an immutable register object.

#### Scenario: Generate destruction certificate after execution
- **GIVEN** a destruction list has been fully executed (all objects destroyed)
- **WHEN** the `DestructionExecutionJob` completes
- **THEN** a destruction certificate MUST be created as a register object containing:
  - Date of destruction
  - Approving archivist(s) user IDs
  - Count of destroyed objects grouped by schema and classificatie
  - Reference to the selectielijst used
  - Reference to the destruction list object
- **AND** the certificate object MUST be stored in the archival register
- **AND** the certificate MUST NOT be deletable (protected by `immutable: true` flag)

### Requirement: The system MUST support legal holds (bevriezing)
Objects under legal hold MUST be exempt from all destruction processes regardless of their archiefactiedatum or archiefnominatie.

#### Scenario: Place legal hold on an object
- **GIVEN** an object eligible for destruction
- **WHEN** an authorized user places a legal hold via `POST /api/retention/legal-holds` with `objectId`, `reason`
- **THEN** the object's `retention.legalHold` MUST be set to `{ active: true, reason: "<reason>", placedBy: "<userId>", placedDate: "<timestamp>" }`
- **AND** the object MUST be excluded from all destruction lists
- **AND** an audit trail entry MUST be created with action `archival.legal_hold_placed`

#### Scenario: Legal hold prevents destruction at execution time
- **GIVEN** a destruction list containing object X
- **AND** a legal hold is placed on object X after the list was approved but before execution
- **WHEN** the `DestructionExecutionJob` processes object X
- **THEN** object X MUST be skipped (not destroyed)
- **AND** the archivist MUST be notified that 1 object was excluded due to legal hold

#### Scenario: Release legal hold
- **GIVEN** an object with an active legal hold
- **WHEN** an authorized user releases the hold via `DELETE /api/retention/legal-holds/{id}` with `reason`
- **THEN** `retention.legalHold.active` MUST be set to `false`
- **AND** the hold MUST be moved to `retention.legalHold.history[]` with release metadata
- **AND** the object MUST become eligible for destruction again if archiefactiedatum has passed
- **AND** an audit trail entry MUST be created with action `archival.legal_hold_released`

#### Scenario: Bulk legal hold on schema
- **GIVEN** a schema containing 200 objects
- **WHEN** an authorized user places a legal hold on all objects in the schema via `POST /api/retention/legal-holds/bulk` with `schemaId`, `reason`
- **THEN** all 200 objects MUST receive a legal hold
- **AND** the operation MUST be executed via QueuedJob to avoid timeouts
- **AND** a summary audit trail entry MUST be created

### Requirement: The system MUST send pre-destruction notifications
Objects approaching their archiefactiedatum MUST trigger notifications to give stakeholders time to review, extend retention, or apply legal holds.

#### Scenario: Send notification 30 days before archiefactiedatum
- **GIVEN** an object with `archiefactiedatum` 30 days from today and `archiefnominatie` `vernietigen`
- **AND** the notification lead time is configured to 30 days via `retention.notificationLeadDays`
- **WHEN** the `DestructionCheckJob` runs its notification check
- **THEN** an INotification MUST be sent to users in the `archivaris` group
- **AND** the notification MUST include: object title, schema name, archiefactiedatum, classificatie
- **AND** the notification MUST NOT be sent again for the same object (deduplicated)

#### Scenario: Notification for bewaren objects approaching transfer date
- **GIVEN** an object with `archiefnominatie` `bewaren` and `archiefactiedatum` within the notification lead period
- **WHEN** the notification check runs
- **THEN** the notification MUST indicate the object requires e-Depot transfer (not destruction)

### Requirement: Retention settings MUST be configurable via API
Administrators MUST be able to configure retention-related settings through the existing settings API.

#### Scenario: Get retention archival settings
- **WHEN** an admin calls `GET /api/settings/retention`
- **THEN** the response MUST include:
  - `destructionCheckInterval`: interval in seconds (default: 86400)
  - `notificationLeadDays`: days before archiefactiedatum to notify (default: 30)
  - `defaultExtensionPeriod`: ISO 8601 duration for rejected objects (default: P1Y)
  - `destructionBatchSize`: objects per batch in execution job (default: 50)
  - `selectielijstRegister`: UUID of the register used for selectielijst objects
  - `selectielijstSchema`: UUID of the schema used for selectielijst objects
  - `destructionListRegister`: UUID of the register for destruction lists
  - `destructionListSchema`: UUID of the schema for destruction lists
  - `archivalRegister`: UUID of the register for certificates

#### Scenario: Update retention archival settings
- **GIVEN** an admin with appropriate permissions
- **WHEN** they call `PUT /api/settings/retention` with updated values
- **THEN** the settings MUST be persisted in app configuration
- **AND** the DestructionCheckJob interval MUST be updated if `destructionCheckInterval` changed

### Requirement: Cascading destruction MUST respect referential integrity
When an object is destroyed via an approved destruction list, the system MUST evaluate related objects according to the existing referential integrity cascade rules.

#### Scenario: CASCADE destruction to child objects
- **GIVEN** a schema property referencing another schema with `onDelete: CASCADE`
- **AND** a parent object is on an approved destruction list
- **WHEN** the parent is destroyed
- **THEN** all child objects MUST also be destroyed
- **AND** each cascaded destruction MUST produce an audit trail entry with action `archival.cascade_destroyed`

#### Scenario: RESTRICT prevents destruction
- **GIVEN** an object references another object with `onDelete: RESTRICT`
- **WHEN** the referenced object appears on a destruction list
- **THEN** the destruction list MUST flag the object with a warning about RESTRICT references
- **AND** the archivist MUST resolve the reference before approving

#### Scenario: Legal hold on child blocks parent destruction
- **GIVEN** a parent object approved for destruction
- **AND** a child object (via CASCADE relationship) has an active legal hold
- **WHEN** the DestructionExecutionJob processes the parent
- **THEN** destruction of the parent MUST be halted
- **AND** the archivist MUST be notified about the blocked destruction

### Requirement: Retention configuration and rebase administration API
The system SHALL expose an admin-gated API for reading and writing retention settings and
for recalculating deletion times across the corpus. `ConfigurationSettingsController`
provides `getRetentionSettings` (delegating to `SettingsService::getRetentionSettingsOnly()`)
and `updateRetentionSettings` (delegating to `updateRetentionSettingsOnly()`).
`SettingsController::rebase` recalculates deletion times for all objects and logs from the
current retention settings and assigns default owners/organisations to objects that lack
them.

#### Scenario: Read retention settings
- **WHEN** `getRetentionSettings` is called
- **THEN** it MUST return the retention settings from `SettingsService::getRetentionSettingsOnly()`

#### Scenario: Update retention settings
- **GIVEN** an admin posts updated retention values
- **WHEN** `updateRetentionSettings` runs
- **THEN** it MUST persist them via `SettingsService::updateRetentionSettingsOnly()` and return the result

#### Scenario: Rebase recalculates deletion times
- **GIVEN** retention settings have changed
- **WHEN** `SettingsController::rebase` runs
- **THEN** it MUST delegate to `SettingsService::rebase()` to recompute object/log deletion times
- **AND** a service failure MUST surface as HTTP 500 with an `error` field

### Requirement: The system MUST run a daily AVG / GDPR retention pass driven by per-activity bewaartermijn

A `TimedJob` (`AvgRetentionJob`, 24-hour interval) MUST invoke `AvgRetentionService::runRetentionPass()` which walks every published `Verwerkingsactiviteit` catalog row, computes a bewaartermijn cut-off per activity, finds audit-trail objects whose **latest** `created` timestamp predates the cut-off, and soft-deletes them. This is distinct from the Archiefwet destruction-list workflow: it operates on the audit-trail's `processing_activity_id` column, not on `archiefactiedatum`, and serves GDPR Article 5(1)(e) (storage limitation).

#### Scenario: Daily job runs the retention pass unless disabled

- **GIVEN** the app-config key `avg_retention_enabled` is unset or `true`
- **WHEN** the `AvgRetentionJob` (24-hour `TimedJob`) fires
- **THEN** it MUST call `AvgRetentionService::runRetentionPass()` with the `dryRun` flag read from app-config key `avg_retention_dry_run` (default `false`)
- **AND** it MUST log an info entry with `evaluatedActivities`, `skippedActivities`, `objectsErased`, and the resolved `dryRun` flag
- **AND** any `\Throwable` raised by the service MUST be caught and logged at `error` level without re-throwing

#### Scenario: Disable toggle short-circuits the job

- **GIVEN** the app-config key `avg_retention_enabled` is the string `"false"` (or any value parsed as boolean `false` by `FILTER_VALIDATE_BOOLEAN`)
- **WHEN** the `AvgRetentionJob` fires
- **THEN** the job MUST log an info entry indicating retention is disabled
- **AND** it MUST return without invoking the service

#### Scenario: Pass evaluates only published activities

- **GIVEN** the catalog contains two `Verwerkingsactiviteit` rows with `status='published'` and one with `status='draft'`
- **WHEN** `runRetentionPass(dryRun: false)` is invoked
- **THEN** the service MUST iterate exactly the two published activities (via `VerwerkingsactiviteitMapper::findAll(status: 'published')`)
- **AND** the summary's `evaluatedActivities` MUST equal the number of activities that produced a per-activity result, and `skippedActivities` MUST equal the number that returned `null`

#### Scenario: Activity without bewaartermijn is skipped

- **GIVEN** a published activity whose `bewaartermijn` is null or the empty string
- **WHEN** `processActivity()` is invoked for that activity
- **THEN** the method MUST return `null` and the activity MUST NOT contribute to `perActivity`
- **AND** the summary's `skippedActivities` counter MUST be incremented by 1

#### Scenario: Activity with unparseable bewaartermijn is skipped with a warning

- **GIVEN** a published activity whose `bewaartermijn` is a string that is not a valid ISO-8601 duration (e.g. `"10 years"` instead of `"P10Y"`)
- **WHEN** `computeCutoff()` attempts `new DateInterval($duration)` and catches the resulting `\Exception`
- **THEN** `computeCutoff()` MUST return `null`
- **AND** `processActivity()` MUST log a warning `[AVG retention] Unparseable bewaartermijn — skipping activity` carrying the activity uuid and the offending value
- **AND** the activity MUST be reported under `skippedActivities`

#### Scenario: Cut-off is computed as now-minus-bewaartermijn

- **GIVEN** the current reference time is `2026-05-24T00:00:00Z` and bewaartermijn is `"P10Y"`
- **WHEN** `computeCutoff()` runs
- **THEN** the returned cut-off `DateTime` MUST equal `2016-05-24T00:00:00Z`
- **AND** it MUST be returned in ISO-8601 form (`format('c')`) within the per-activity summary's `cutoff` field

#### Scenario: Overdue candidates are those whose newest audit row predates the cut-off

- **GIVEN** an activity uuid `act-1` with cut-off `2016-05-24`
- **AND** the `openregister_audit_trails` table contains rows for object A (latest `created` = `2015-12-01`) and object B (latest `created` = `2020-03-15`), both tagged `processing_activity_id='act-1'`
- **WHEN** `findOverdueObjectsForActivity('act-1', cutoff)` runs
- **THEN** the SQL MUST `GROUP BY object, object_uuid, register, schema` and apply `HAVING MAX(created) < :cutoff`
- **AND** object A MUST be returned and object B MUST NOT
- **AND** each returned row MUST carry `object` (int), `object_uuid` (string|null), `register` (int|null), `schema` (int|null)

#### Scenario: SQL failure during enumeration is contained per activity

- **GIVEN** the query builder for an activity uuid throws a `\Throwable`
- **WHEN** `findOverdueObjectsForActivity()` catches it
- **THEN** the method MUST log a warning `[AVG retention] Failed to enumerate overdue objects for activity` with the activity uuid and the error message
- **AND** the method MUST return `[]` so the retention pass continues with the next activity

#### Scenario: Erasure is a soft-delete carrying the activity attribution

- **GIVEN** `processActivity()` resolves three candidate rows and `dryRun=false`
- **WHEN** `erasePastRetention()` runs
- **THEN** for each candidate the loader MUST call `MagicMapper::find($uuidOrIntId, _rbac: false, _multitenancy: false)`
- **AND** each loaded `ObjectEntity` MUST have its `deleted` field set to an array `{ deletedBy: 'system', deletedAt: <ISO 8601 ATOM>, reason: 'avg-bewaartermijn', activityUuid: <activity uuid>, bewaartermijn: <activity bewaartermijn> }`
- **AND** the same `ObjectEntity` MUST have its `processingActivityId` set to the owning activity's uuid before being passed to `objectMapper->update()`
- **AND** the per-activity `erased` count MUST equal the number of successful `update()` calls; failures MUST be logged at warning level and MUST NOT abort the loop

#### Scenario: Dry run reports candidates without writing

- **GIVEN** `runRetentionPass(dryRun: true)` is invoked with two overdue candidates for an activity
- **WHEN** `processActivity()` runs
- **THEN** `erasePastRetention()` MUST NOT be called
- **AND** the per-activity entry MUST report `matchedObjects=2`, `erased=0`
- **AND** the summary's `dryRun` field MUST be `true`

#### Scenario: Candidate loader prefers UUID over int id

- **GIVEN** a candidate row with both `object_uuid='abc-123'` and `object=42`
- **WHEN** `loadCandidate()` runs
- **THEN** it MUST call `objectMapper->find('abc-123', ...)` (uuid wins)

#### Scenario: Candidate loader falls back to int id when uuid is empty

- **GIVEN** a candidate row with `object_uuid=null` and `object=42`
- **WHEN** `loadCandidate()` runs
- **THEN** it MUST call `objectMapper->find(42, ...)`

#### Scenario: Candidate that no longer exists is skipped silently

- **GIVEN** `objectMapper->find()` raises `DoesNotExistException` for a candidate (e.g. already hard-deleted)
- **WHEN** `loadCandidate()` catches it
- **THEN** the method MUST return `null` without logging
- **AND** the candidate MUST NOT count toward `erased`

### Requirement: The system MUST prune the realtime events log daily

A separate `TimedJob` (`RealtimeEventRetentionJob`, 24-hour interval) MUST bound the size of the `openregister_realtime_events` table by deleting rows older than a configurable retention window. This is independent of the AVG retention pass and of the Archiefwet destruction workflow — it manages the SSE event ledger used by the realtime-updates feature.

#### Scenario: Default retention window is 7 days

- **GIVEN** the app-config key `realtime_event_retention_seconds` is unset
- **WHEN** the job runs
- **THEN** it MUST call `RealtimeEventMapper::deleteOlderThan(retentionSeconds: 604800)` (7 × 86400)
- **AND** it MUST log an info entry with `retentionSeconds=604800` and `deletedRows=<count returned by mapper>`

#### Scenario: Configurable retention window override

- **GIVEN** the app-config key `realtime_event_retention_seconds` is set to `"259200"` (3 days)
- **WHEN** the job runs
- **THEN** it MUST pass `259200` as the `retentionSeconds` argument to `deleteOlderThan()`

#### Scenario: Setting retention to zero or negative disables the prune

- **GIVEN** the app-config key `realtime_event_retention_seconds` is `"0"` (or any string parsed as int ≤ 0)
- **WHEN** the job fires on its scheduled tick
- **THEN** the job MUST log an info entry `[RealtimeEventRetentionJob] Retention disabled (value <= 0), skipping prune`
- **AND** it MUST return without touching the `openregister_realtime_events` table

#### Scenario: Mapper failure is logged and swallowed

- **GIVEN** `deleteOlderThan()` raises a `\Throwable` (e.g. DB connection error)
- **WHEN** the job's `run()` catches it
- **THEN** the job MUST log the error at `error` level with the message prefix `[RealtimeEventRetentionJob] Prune failed:`
- **AND** the job MUST NOT re-throw — the next scheduled tick will retry

#### Scenario: Job interval is fixed at 24 hours

- **GIVEN** the constructor builds a new `RealtimeEventRetentionJob`
- **WHEN** the parent `TimedJob` is configured
- **THEN** `setInterval(seconds: 86400)` MUST be called (running more often than daily is intentionally rejected per the implementation contract)

### Requirement: The retention settings handler MUST expose app metadata and normalise mixed-type toggle values

`ObjectRetentionHandler` is the back-end handler for the Retention/Object/Archival settings sections. Beyond the read/write methods already covered by the settings retrofit, it MUST provide a version-info endpoint backing the settings UI's "About" panel and MUST coerce mixed-type boolean inputs from stored JSON (strings, ints, native booleans) to PHP `bool`.

#### Scenario: Version info reports app name and version

- **WHEN** `ObjectRetentionHandler::getVersionInfoOnly()` is called
- **THEN** the method MUST return an array containing exactly `appName` = `"Open Register"` and `appVersion` = `"0.2.3"`
- **AND** any thrown `Exception` MUST be re-thrown as `\RuntimeException` with the prefix `Failed to retrieve version information:`

#### Scenario: Native booleans pass through unchanged

- **GIVEN** the input value is the PHP boolean `true` (resp. `false`)
- **WHEN** `convertToBoolean()` is called
- **THEN** it MUST return `true` (resp. `false`) without further processing

#### Scenario: Truthy strings normalise to true

- **GIVEN** the input value is the string `"true"`, `"1"`, `"yes"`, or `"on"` (case-insensitive)
- **WHEN** `convertToBoolean()` is called
- **THEN** it MUST return `true`
- **AND** any other string (e.g. `"false"`, `"no"`, `"random"`) MUST return `false`

#### Scenario: Numeric values use C-style zero/non-zero semantics

- **GIVEN** the input value is the int `0`, `1`, `42`, or the float `0.0`
- **WHEN** `convertToBoolean()` is called
- **THEN** the method MUST return `false` for `0` / `0.0` and `true` for any non-zero numeric value
- **AND** the comparison MUST use `(int) $value !== 0` (so the float `0.5` casts to int `0` and returns `false`)

#### Scenario: convertToBoolean drives audit/search-trail toggle parsing

- **GIVEN** the stored `retention` JSON blob contains `auditTrailsEnabled: "true"` and `searchTrailsEnabled: 1`
- **WHEN** `getRetentionSettingsOnly()` decodes the blob
- **THEN** both flags MUST be returned as PHP boolean `true` (via `convertToBoolean()`), not as the raw string/int — so downstream callers can rely on strict `=== true` checks

