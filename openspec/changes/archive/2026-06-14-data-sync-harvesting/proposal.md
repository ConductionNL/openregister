# Data Sync and Harvesting

## Problem
OpenRegister can import data once (CSV/Excel via `ImportService`) and can sync
*configurations* from Git/URL (`SyncConfigurationsJob`), but it has no
repeatable, scheduled pipeline for harvesting *data* records from external
sources (REST APIs, other OpenRegister instances, Dutch base registrations)
into register schemas. The `Source` entity carries no sync configuration, there
is no per-record status tracking, and there is no conflict-resolution or
incremental-sync machinery.

## Proposed Solution
Add a robust, multi-source harvesting pipeline that follows CKAN's proven
three-stage pattern (gather → fetch → import) with per-record status tracking,
scheduled execution via a Nextcloud `TimedJob`, configurable conflict
resolution, and incremental sync via last-modified / change-token checkpoints —
reusing OpenRegister's existing `Source`, `Mapping`, `ObjectService` and
multi-tenant infrastructure rather than building a parallel system.

This change is additive only:

- **`Source` entity + migration** gains sync fields (`syncEnabled`,
  `syncSchedule`, `syncInterval`, `lastSyncDate`, `lastSyncStatus`,
  `lastSyncToken`, `authType`, `authConfig`, `mappingId`, `targetRegister`,
  `targetSchema`, `conflictStrategy`, `deleteStrategy`, `batchSize`). Existing
  sources default to `syncEnabled = false` and behave exactly as before.
- **`SyncRecord` entity + mapper + `openregister_sync_records` table** track each
  record through the pipeline (`pending → fetched → imported/unchanged/conflict`
  / error states) for resume-after-failure and execution reporting.
- **`HarvestPipelineService`** orchestrates gather/fetch/import against an
  injectable `SourceFetcherInterface` (transport decoupled for testability),
  applying the configured `Mapping`, persisting via `ObjectService::saveObject`
  (which validates against the target schema), and resolving conflicts.
- **`SyncConflictResolver`, `SyncScheduleService`, `SyncRecordStatus`** hold the
  pure decision logic (conflict strategies, due-source selection, status state
  machine) so they are fully unit-testable without a live DB or HTTP client.
- **`RestApiSourceFetcher`** is the built-in transport (REST + OpenRegister
  federation) with API-key/basic auth, pagination, and If-Modified-Since;
  resolved via a `SourceFetcherRegistry` so additional transports plug in.
- **`SyncDataJob`** (hourly `TimedJob`, registered in `info.xml`) selects due
  sources and runs the pipeline with overlap protection.
- **`SourcesController::syncNow` / `syncStatus`** expose admin-only manual
  trigger and read-only status, organisation-scoped via `SourceMapper::find()`.

## Scope
Implements scheduled + manual REST/OpenRegister harvesting end-to-end. OData and
SOAP transports, bi-directional push, the manual conflict-resolution UI, and
webhook/event triggers are specified but deferred to follow-up transports that
register against `SourceFetcherRegistry`; the seams are in place.
