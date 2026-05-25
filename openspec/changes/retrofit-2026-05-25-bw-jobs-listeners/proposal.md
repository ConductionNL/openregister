# Retrofit — background jobs / listeners / cron bundle

## Why

A backend cluster of 15 shipped methods across `lib/BackgroundJob/`, `lib/Listener/`, and `lib/Cron/` had no `@spec` coverage, leaving the recurring/reactive half of several pipelines undocumented even though their synchronous half is already specced. Without coverage these methods are invisible to spec-to-test mapping (ADR-008) and the next maintainer cannot tell intended behaviour from incidental implementation. This change closes that gap by reverse-speccing the observed behaviour and back-annotating each method, biased toward `--extend` of existing capabilities so the recurring/reactive code lands next to its synchronous counterpart.

## What Changes

- **3 new REQs** drafted: 1 on `search-index` (asynchronous file text extraction) and 2 on `object-lifecycle` (initial-state listener, update-guard listener).
- **10 methods** annotated to existing REQs across `notificatie-engine`, `faceting-configuration`, `workflow-operations`, `rapportage-bi-export`, `search-index`, and `retention-management` (no new REQ — `--extend`).
- **2 methods excluded** via `@spec exclude <reason>` (disabled-by-default migration backfill; dispatch-only event router).
- No production code behaviour changes — annotations and spec text only.

## Impact

- **Capabilities touched**: `search-index`, `object-lifecycle` (new REQs); `notificatie-engine`, `faceting-configuration`, `workflow-operations`, `rapportage-bi-export`, `retention-management` (existing REQs extended by annotation).
- **Code**: method-level `@spec` tags added under `lib/BackgroundJob/`, `lib/Listener/`, `lib/Cron/`.
- **Risk**: low — reverse-spec, no runtime change. Surfaced-but-not-fixed concerns (RBAC/multitenancy system-bypass, the validation listener's hardcoded-English error messages) are documented in Notes for a follow-up functional pass.

Source: `/tmp/or-scan/bw-jobs-listeners.json` (cluster `jobs-listeners`, generated 2026-05-25). See [retrofit playbook](../../../.github/docs/claude/retrofit.md).

## Counts

- **15 methods** tagged in total.
- **10 spec'd to existing REQs** (annotated `@spec` pointing at the existing capability change/task).
- **3 new REQs** drafted (1 on `search-index`, 2 on `object-lifecycle`).
- **2 excluded** via `@spec exclude <reason>` (disabled-by-default migration backfill; dispatch-only event router).

## Affected code units

### Spec'd to existing capabilities (10 methods)

| Method | Capability | Existing REQ |
|---|---|---|
| `BackgroundJob/BatchNotificationJob::run` | `notificatie-engine` | Notifications MUST support batching and digest delivery |
| `BackgroundJob/ScheduledNotificationJob::run` | `notificatie-engine` | The notification engine MUST support event-driven trigger types beyond CRUD |
| `Listener/AggregationThresholdListener::handle` | `notificatie-engine` | The notification engine MUST support event-driven trigger types beyond CRUD |
| `BackgroundJob/CacheWarmupJob::run` | `faceting-configuration` | Facet label resolution for entity references |
| `BackgroundJob/NameCacheWarmupJob::run` | `faceting-configuration` | Facet label resolution for entity references |
| `BackgroundJob/ExecutionHistoryCleanupJob::run` | `workflow-operations` | Execution History Retention |
| `BackgroundJob/ScheduledWorkflowJob::run` | `workflow-operations` | Scheduled Workflow Triggers |
| `BackgroundJob/ReportRenderJob::run` | `rapportage-bi-export` | The system MUST support scheduled report generation |
| `BackgroundJob/SolrWarmupJob::run` | `search-index` | Bulk indexing fetches searchable-schema objects from the database in batches |
| `Cron/ArchivalRetentionTask::run` | `retention-management` | The system MUST generate destruction lists via a background job |

### New REQs (3 methods)

| Method | Capability | New REQ |
|---|---|---|
| `BackgroundJob/FileTextExtractionJob::run` | `search-index` | Asynchronous file text extraction MUST run as a queued background job |
| `Listener/LifecycleInitialStateListener::handle` | `object-lifecycle` | Declared initial lifecycle state applied on create |
| `Listener/LifecycleValidationListener::handle` | `object-lifecycle` | Direct lifecycle-field edits guarded on update |

### Excluded (2 methods)

| Method | Exclude reason |
|---|---|
| `BackgroundJob/BackfillCalendarLinksJob::run` | disabled-by-default one-time Tier-2 migration backfill, gated behind an app-config flag, not a production capability surface |
| `Listener/AnnotationNotificationListener::handle` | dispatch-only event router that delegates verbatim to `AnnotationNotificationDispatcher` (the dispatcher carries the behaviour) |

## Approach

### notificatie-engine (3 methods, extend — no new REQ)
`BatchNotificationJob::run` is the consumer side of the digest queue: it flushes `NotificationDigest` per-recipient on a configurable interval (`notification_batch_interval`, 0 disables via the standard 1-year-interval "off" pattern). This is exactly the "batching and digest delivery" REQ — the file already carries the file-level annotation; the `run()` method gets a method-level tag. `ScheduledNotificationJob::run` is the `trigger.type === 'scheduled'` driver: a 60s TimedJob that walks every schema's `x-openregister-notifications` block, fires due entries via the existing `AnnotationNotificationDispatcher`, and tracks last-fire state in the distributed cache. `AggregationThresholdListener::handle` is the `trigger.type === 'threshold'` driver: on object-write events it recomputes the referenced aggregation and dispatches on the rising-edge (below → above) crossing, with per-(schema, notification) state in the distributed cache. Both are concrete realisations of "event-driven trigger types beyond CRUD" (scheduled + threshold triggers), reusing the unchanged channel surface.

### faceting-configuration (2 methods, extend — no new REQ)
`CacheWarmupJob::run` (hourly, configurable) and `NameCacheWarmupJob::run` (nightly) both call `CacheHandler::warmupNameCache()` to pre-populate the distributed UUID-to-name cache so facet label resolution does not pay a cold-start penalty. This is the "Facet label resolution for entity references" REQ — the warmup jobs are its operational support.

### workflow-operations (2 methods, extend — no new REQ)
`ExecutionHistoryCleanupJob::run` is the daily pruner that deletes `WorkflowExecution` rows older than `workflow_execution_retention_days` (default 90) — the "Execution History Retention" REQ. `ScheduledWorkflowJob::run` is the 60s evaluator that runs each enabled `ScheduledWorkflow` whose interval has elapsed via the engine registry and records the result — the "Scheduled Workflow Triggers" REQ (the file already carries a `retrofit-2026-04-23-annotate-openregister` annotation; the method tag is brought onto the canonical `workflow-operations` capability).

### rapportage-bi-export (1 method, extend — no new REQ)
`ReportRenderJob::run` is the daily scheduled-report renderer: it walks dashboard objects in the `reports` register, decides per-`schedule.intervalSec` due-ness, renders to the configured format, and delivers to the operator-configured Files folder (with path-traversal hardening). This is the "scheduled report generation" REQ.

### search-index (1 extend, 1 new REQ)
`SolrWarmupJob::run` warms the Solr index after imports by bulk-indexing all schemas up to a cap — covered by the existing "Bulk indexing" REQ. `FileTextExtractionJob::run` is a different surface: a queued one-time job that extracts text from a single uploaded file via `TextExtractionService` so user requests are not blocked. The `search-index` spec covers Solr/document indexing but not the file-text-extraction feeder pipeline, so this is a **new REQ**.

### object-lifecycle (2 new REQs)
The object-lifecycle capability already specs the annotation validator (REQ-006), the named-action `TransitionEngine` (REQ-007), and the guard registry / `GuardResult` contract (REQ-008/009). The two listeners in this bundle are the **event-listener enforcement layer** that those REQs do not describe:

- `LifecycleInitialStateListener::handle` force-sets the schema's declared `initial` value on `ObjectCreatingEvent` when the caller left the lifecycle field empty — so apps never have to remember the starting state. → new REQ **Declared initial lifecycle state applied on create**.
- `LifecycleValidationListener::handle` guards **direct field edits** (not the named-action `TransitionEngine` path): on `ObjectUpdatingEvent` it rejects any lifecycle-field change that no declared transition allows from the current value, and runs the optional `requires` guard, stopping propagation with a structured error. This is the `saveObject()`-driven complement to REQ-007's explicit-action engine. → new REQ **Direct lifecycle-field edits guarded on update**.

## Notes

- **RBAC / multitenancy bypass (surfaced, not fixed)** — several system-level jobs/listeners deliberately call mapper `find()` / `findAll()` with `_rbac: false, _multitenancy: false` because they run as the system, not a user (`ArchivalRetentionTask`, `ReportRenderJob`, `AggregationThresholdListener`, `LifecycleInitialStateListener`, `LifecycleValidationListener`). Documented as observed behaviour, not changed in this retrofit.
- **`ArchivalRetentionTask` is the only delete path on archival schemas** — user-driven deletes are rejected 403; the sweep uses `ObjectService::deleteObject(..., _retentionSweep: true)` to bypass the immutability gate while still firing the audit trail. It already carries an `add-archival-annotation-support` file-level annotation; the method tag is added to the canonical `retention-management` capability.
- **`ReportRenderJob::writeToFiles` path-traversal hardening** — the job refuses to fall back to `admin` when the dashboard owner is null and rejects `..` segments in the user-controlled `delivery.filesFolder`. Captured in the existing scheduled-report REQ scenarios.
- The two excluded methods are tagged `@spec exclude <reason>` (never bare).

Source: `/tmp/or-scan/bw-jobs-listeners.json`. See retrofit playbook.
