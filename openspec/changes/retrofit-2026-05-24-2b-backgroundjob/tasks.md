# Tasks

- [x] task-1: faceting-configuration#REQ-NEW — UUID-to-name distributed cache MUST be pre-warmed via background jobs (covers `CacheWarmupJob::run` configurable hourly + `NameCacheWarmupJob::run` nightly) (retroactive specification)
- [x] task-2: workflow-operations#REQ-006 — `ExecutionHistoryCleanupJob::run` already fully covered by existing "Execution History Retention" requirement (retroactive annotation)
- [x] task-3: rapportage-bi-export#REQ-NEW — Scheduled-render job MUST iterate dashboard objects, gate on `schedule.intervalSec`/`lastRenderedAt`, refuse delivery on missing owner, and reject path-traversal in `delivery.filesFolder` (covers `ReportRenderJob` __construct/run/shouldRender/renderAndDeliver/writeToFiles/loadReportsRegister/loadDashboards/slugify) (retroactive specification)
- [x] task-4: zoeken-filteren#REQ-NEW — Post-import Solr warmup MUST run as a `QueuedJob` with configurable `maxObjects`/`mode`/`collectErrors`/`triggeredBy`, MUST skip silently when Solr is unavailable, and MUST record `objects_per_second` throughput (covers `SolrWarmupJob::run` + `isSolrAvailable` + `calculateObjectsPerSecond`) (retroactive specification)
