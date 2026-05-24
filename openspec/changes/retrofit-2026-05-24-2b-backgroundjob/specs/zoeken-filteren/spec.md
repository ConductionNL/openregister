---
retrofit_extensions: [REQ-solr-warmup-queued]
---

### Requirement: Post-import Solr index warmup MUST be a queued one-shot job with configurable scope and availability gating

The system MUST expose a one-shot `QueuedJob` (`SolrWarmupJob`) that runs Solr index warmup after import operations complete, decoupling import latency from warmup latency. This requirement complements the existing nightly-warmup scenario by specifying the **post-import queued shape** observed in the codebase.

The job MUST extend `OCP\BackgroundJob\QueuedJob` (one-shot, not recurring) so it executes once when the cron worker picks it up and is then discarded. The job MUST accept the following per-invocation arguments (all optional with defaults):

- `maxObjects` (int, default `5000`) — upper bound on objects indexed during this warmup pass; prevents excessive resource use and overlong jobs.
- `mode` (string, default `'serial'`) — one of `serial`, `parallel`, `hyper`. Selects the indexing strategy passed to `IndexService::warmupIndex`.
- `collectErrors` (bool, default `false`) — whether to accumulate detailed per-object errors in the result.
- `triggeredBy` (string, default `'unknown'`) — provenance label included in every log line (e.g. `import_completed`, `manual_admin`, `bulk_legal_hold`); used to trace warmup activity back to its initiator.

Before invoking warmup, the job MUST gate on `IndexService::isAvailable()`. When Solr is not configured or unreachable, the job MUST log `[SolrWarmupJob] SOLR Warmup Job skipped - SOLR not available` (with `triggered_by` context) and return without raising. Skipping MUST NOT mark the job as failed — a missing Solr is an expected configuration state, not an error.

When Solr is available, the job MUST enumerate all schemas via `SchemaMapper::findAll()` and pass them together with the bounds to `IndexService::warmupIndex(schemas, maxObjects, mode, collectErrors)`. The job MUST log a comprehensive summary on completion, including `execution_time_seconds`, `objects_indexed`, `schemas_processed`, `fields_created`, `triggered_by`, and a `performance_metrics` block containing:

- `total_time_ms` — taken from the `IndexService` result envelope.
- `objects_per_second` — computed locally as `objects_indexed / execution_time_seconds`, rounded to 2 decimal places. When either input is non-positive, the value MUST be `0.0` (no division-by-zero, no negative throughput).

When `IndexService::warmupIndex` returns `success=false`, the job MUST log `[SolrWarmupJob] ❌ SOLR Warmup Job Failed` with the result's `error` field but MUST NOT re-throw (the result is the canonical failure surface).

When the warmup body raises a `\Exception`, the job MUST log `[SolrWarmupJob] 🚨 SOLR Warmup Job Exception` with `exception`, `exception_file`, `exception_line`, `triggered_by`, and `trace` context, and MUST re-throw so the job is marked failed.

#### Scenario: Post-import trigger queues warmup with provenance label

- **GIVEN** an import workflow finishes and schedules `SolrWarmupJob` with `triggeredBy='import_completed'`, `maxObjects=10000`, `mode='parallel'`
- **WHEN** the Nextcloud cron worker picks up the queued job
- **THEN** the job MUST log `[SolrWarmupJob] 🔥 SOLR Warmup Job Started` with `triggered_by: import_completed`, `max_objects: 10000`, `mode: parallel`
- **AND** the job MUST call `IndexService::warmupIndex(schemas, 10000, 'parallel', false)`

#### Scenario: Defaults are applied when arguments are omitted

- **GIVEN** `SolrWarmupJob` is queued with an empty `$argument` array
- **WHEN** the job runs
- **THEN** `maxObjects` MUST default to `5000`
- **AND** `mode` MUST default to `'serial'`
- **AND** `collectErrors` MUST default to `false`
- **AND** `triggeredBy` MUST default to `'unknown'`

#### Scenario: Job skips silently when Solr is not configured

- **GIVEN** `IndexService::isAvailable()` returns `false` (Solr not configured)
- **WHEN** `SolrWarmupJob::run` executes
- **THEN** the job MUST log `[SolrWarmupJob] SOLR Warmup Job skipped - SOLR not available`
- **AND** MUST return cleanly (job marked success, not failure)
- **AND** MUST NOT call `SchemaMapper::findAll` or `IndexService::warmupIndex`

#### Scenario: Throughput metric handles zero-objects-indexed gracefully

- **GIVEN** a warmup result with `operations.objects_indexed = 0` and a positive `execution_time`
- **WHEN** `calculateObjectsPerSecond` is invoked
- **THEN** the return value MUST be exactly `0.0`
- **AND** no division-by-zero or negative throughput MUST be logged

#### Scenario: Throughput metric handles zero execution time gracefully

- **GIVEN** a warmup result with `objects_indexed > 0` but `execution_time = 0.0` (instantaneous)
- **WHEN** `calculateObjectsPerSecond` is invoked
- **THEN** the return value MUST be `0.0`

#### Scenario: Result envelope failure is logged but not re-thrown

- **GIVEN** `IndexService::warmupIndex` returns `['success' => false, 'error' => 'collection unreachable']`
- **WHEN** `SolrWarmupJob::run` evaluates the result
- **THEN** the job MUST log `[SolrWarmupJob] ❌ SOLR Warmup Job Failed` with `error: collection unreachable`
- **AND** MUST NOT re-throw (the result envelope is the failure surface)

#### Scenario: Unhandled exception re-throws to mark job failed

- **GIVEN** `IndexService::warmupIndex` raises `\RuntimeException('connection refused')`
- **WHEN** `SolrWarmupJob::run` catches the exception
- **THEN** the job MUST log `[SolrWarmupJob] 🚨 SOLR Warmup Job Exception` with full `trace` and `triggered_by` context
- **AND** MUST re-throw so the Nextcloud job framework marks the job failed
