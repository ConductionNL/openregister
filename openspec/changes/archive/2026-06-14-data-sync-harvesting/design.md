# Design: Data Sync and Harvesting

## Approach
Reuse OpenRegister's existing source/import/mapping/object infrastructure and
add a thin, well-factored harvest layer on top. The CKAN three-stage pattern
(gather → fetch → import) is implemented as orchestration over an injectable
transport so the pipeline logic is unit-testable without real network or DBAL.

## Key decisions

- **Additive `Source` schema.** Sync configuration lives directly on the
  `Source` entity, mirroring the sync fields already on `Configuration`. A new
  migration adds nullable columns (default `sync_enabled = false`) so existing
  sources are untouched.
- **Per-record tracking table.** `openregister_sync_records` holds one row per
  source record per execution, with a status state machine
  (`SyncRecordStatus`), content hash (change detection) and raw payload
  (resume + reporting). Modelled on CKAN harvest objects.
- **Transport behind an interface.** `SourceFetcherInterface` (gather + fetch)
  decouples the pipeline from any specific protocol. `RestApiSourceFetcher`
  implements REST + OpenRegister federation (auth, pagination, If-Modified-
  Since). A `SourceFetcherRegistry` resolves the fetcher per source type, so
  OData/SOAP/CSV transports can register later without touching the pipeline.
- **Pure decision logic.** Conflict resolution (`SyncConflictResolver`),
  schedule windowing (`SyncScheduleService`) and the status state machine
  (`SyncRecordStatus`) are dependency-free, making the security- and
  correctness-critical decisions exhaustively unit-testable.
- **Persistence via `ObjectService::saveObject`.** Imported records are
  validated against the target schema and persisted through the canonical
  object API (named arguments, real OR API only) — no parallel write path.
- **Scheduling via `TimedJob`.** `SyncDataJob` (hourly, registered in
  `info.xml`) selects due sources via `SyncScheduleService` and runs the
  pipeline with overlap protection (`lastSyncStatus = running`). Mirrors the
  proven `SyncConfigurationsJob` shape.
- **Auth posture.** `syncNow` is admin-gated and organisation-scoped (loads via
  `SourceMapper::find()`); `syncStatus` is an organisation-scoped read.
  Credentials are stored in the encrypted `authConfig` blob (via `ICrypto`) and
  never serialized — `jsonSerialize` exposes only an `authConfigured` boolean.

## Files Affected
- `lib/Db/Source.php` — sync fields + serialization (credentials masked)
- `lib/Db/SourceMapper.php` — `findBySyncEnabled()`
- `lib/Db/SyncRecord.php` — per-record tracking entity (new)
- `lib/Db/SyncRecordMapper.php` — tracking persistence + status transitions (new)
- `lib/Migration/Version1Date20260614100000.php` — Source sync columns (new)
- `lib/Migration/Version1Date20260614110000.php` — sync_records table (new)
- `lib/Service/Sync/SyncRecordStatus.php` — status state machine (new)
- `lib/Service/Sync/SyncConflictResolver.php` — conflict strategies (new)
- `lib/Service/Sync/SyncScheduleService.php` — due-source selection (new)
- `lib/Service/Sync/SourceFetcherInterface.php` — transport contract (new)
- `lib/Service/Sync/SourceFetcherRegistry.php` — fetcher resolution (new)
- `lib/Service/Sync/RestApiSourceFetcher.php` — REST/OR transport (new)
- `lib/Service/Sync/HarvestPipelineService.php` — gather/fetch/import orchestrator (new)
- `lib/Cron/SyncDataJob.php` — scheduled harvest driver (new)
- `lib/Controller/SourcesController.php` — `syncNow` / `syncStatus`
- `lib/AppInfo/Application.php` — register `SourceFetcherRegistry`
- `appinfo/info.xml` — register `SyncDataJob`
- `appinfo/routes.php` — sync routes
- `tests/Unit/Service/Sync/*` — pipeline, conflict, schedule, status tests (new)
