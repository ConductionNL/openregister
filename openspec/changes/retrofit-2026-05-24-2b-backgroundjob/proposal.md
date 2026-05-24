# Retrofit — Bucket 2b BackgroundJob cluster

Reverse-engineers specifications for 14 methods across 5 `lib/BackgroundJob/` classes that scanner couldn't cluster behaviorally (the namespace word `backgroundjob` is the only thing they share). Each class implements a **distinct task** that extends an existing capability — we describe what each one actually does and back-annotate.

No new capability is minted. A generic `background-jobs` umbrella would be a namespace-word anti-pattern: TimedJob/QueuedJob is a Nextcloud framework hook, not a behavior. Splitting by what each job does keeps capabilities behavioral.

## Affected code units (by class)

- `lib/BackgroundJob/CacheWarmupJob.php::run` → faceting-configuration (REQ-NEW)
- `lib/BackgroundJob/NameCacheWarmupJob.php::run` → faceting-configuration (REQ-NEW)
- `lib/BackgroundJob/ExecutionHistoryCleanupJob.php::run` → workflow-operations (existing REQ — back-annotation only)
- `lib/BackgroundJob/ReportRenderJob.php::__construct,run,shouldRender,renderAndDeliver,writeToFiles,loadReportsRegister,loadDashboards,slugify` → rapportage-bi-export (REQ-NEW for security hardening + dashboard-driven schedule)
- `lib/BackgroundJob/SolrWarmupJob.php::run,isSolrAvailable,calculateObjectsPerSecond` → zoeken-filteren (REQ-NEW for post-import queued semantics + availability gate + throughput metrics)

## Approach (per job)

### CacheWarmupJob (configurable hourly) + NameCacheWarmupJob (nightly)
Both classes call `CacheHandler::warmupNameCache()` to pre-populate the distributed UUID-to-name cache used by facet label resolution. `CacheWarmupJob` reads `cache_warmup_interval` from IAppConfig (default 3600s; `0` disables); writes `cache_warmup_last_run` timestamp. `NameCacheWarmupJob` is fixed-interval (24h). Both log execution time and number of names loaded.

The `faceting-configuration` spec already mandates a 24-hour distributed label cache (`MagicFacetHandler::uuidLabelCache`, `openregister_facet_labels`) and an invalidation flow on schema change — but **does not specify the pre-warm lifecycle**. A facet-label cache that has to be warmed lazily on first request causes cold-start delays for the first facet query of the day. Both warmup jobs **extend** faceting-configuration with an explicit warm-up requirement.

### ExecutionHistoryCleanupJob (daily)
Reads `workflow_execution_retention_days` from IAppConfig (default 90), calls `WorkflowExecutionMapper::deleteOlderThan($cutoff)`, logs deleted count. **Already fully specified** at `workflow-operations` "Execution History Retention" (REQ-006). Back-annotation only — no new REQ.

### ReportRenderJob (daily)
Walks every dashboard object in the `reports` register, computes whether each is due based on `schedule.intervalSec` vs `lastRenderedAt`, renders via `ReportRenderService`, and writes the result to the dashboard owner's Files folder (default `Reports/<dashboard-slug>/`). The `rapportage-bi-export` spec already mentions scheduled rendering at REQ-002 ("scheduled report generation"), but the existing scenarios describe a notional `ScheduledReportJob` class with a different shape (per-report cron schedules, n8n SMTP delivery). The **actual** implementation uses dashboard-object iteration with `intervalSec` polling and includes two **security-relevant** behaviors not currently scenarized:

- **Path traversal hardening**: `writeToFiles()` strips leading slashes and rejects any `..` segment in the user-controlled `delivery.filesFolder`.
- **Owner-fallback refusal**: When the dashboard owner is null/empty, the job **skips delivery** rather than falling back to admin's Files share (which would be a phishing/redirect persistence vector — attacker-controlled bytes landing in admin's home on next login).
- **Kill switch**: `rapportage_scheduled_renders_enabled` app-config flag (default `true`).

These are real, observable security guards. They warrant their own REQ as **extension** to rapportage-bi-export so the next implementer cannot regress them.

### SolrWarmupJob (queued, post-import)
QueuedJob (not TimedJob) — scheduled imperatively after import operations to avoid blocking import completion. Reads `maxObjects` (default 5000), `mode` (`serial`/`parallel`/`hyper`), `collectErrors`, `triggeredBy` from job arguments. Calls `IndexService::isAvailable()` first and **skips** with a warning when Solr is not configured (not throwing). Records `objects_per_second` throughput metric. The `zoeken-filteren` spec already mentions index warmup at "Index warmup via background jobs", but the scenario doesn't cover the **post-import queued shape** or the availability gate or the mode-selection contract. New REQ extends zoeken-filteren with the queued/availability-gate/mode contract.

## Observed gaps (advisory)

- `ReportRenderJob` calls `RegisterMapper::find()` and `MagicMapper::findAll()` with `_rbac: false, _multitenancy: false` — the job runs as a system context and bypasses RBAC. The `rapportage-bi-export` spec REQ-13 ("Cross-register reporting respects RBAC boundaries") suggests RBAC should always apply; the job's bypass is justified for a cron context but isn't currently called out anywhere. Surfacing for reviewer attention.
- `SolrWarmupJob` uses `\OC::$server->get(...)` service location instead of constructor injection for `IndexService` and `SchemaMapper`. Pre-existing pattern; not changed.
- `CacheWarmupJob` and `NameCacheWarmupJob` both call `CacheHandler::warmupNameCache()` but use different invocation patterns (one via constructor-injected services, one via `\OC::$server->get()`). Consolidation candidate — surfacing as observation.

Source: `/tmp/or-scan/rspec-2b-backgroundjob.json` (Bucket 2b BackgroundJob cluster, 14 methods / 5 files).
