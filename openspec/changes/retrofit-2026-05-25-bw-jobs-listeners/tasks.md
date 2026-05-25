# Tasks — background jobs / listeners / cron reverse-spec

Each task back-annotates one method against its target REQ (existing capability or a new REQ drafted in this change). Excluded methods are tagged `@spec exclude <reason>` and are not listed as REQ tasks.

## notificatie-engine (extend, existing REQs)

- [x] task-1: notificatie-engine#Notifications MUST support batching and digest delivery — `BatchNotificationJob::run` flushes the `NotificationDigest` queue per recipient on the configurable `notification_batch_interval` (retroactive annotation)
- [x] task-2: notificatie-engine#The notification engine MUST support event-driven trigger types beyond CRUD — `ScheduledNotificationJob::run` fires `trigger.type === 'scheduled'` notifications, tracking last-fire state in the distributed cache (retroactive annotation)
- [x] task-3: notificatie-engine#The notification engine MUST support event-driven trigger types beyond CRUD — `AggregationThresholdListener::handle` fires `trigger.type === 'threshold'` notifications on rising-edge aggregation crossings (retroactive annotation)

## faceting-configuration (extend, existing REQ)

- [x] task-4: faceting-configuration#Facet label resolution for entity references — `CacheWarmupJob::run` warms the UUID-to-name cache on a configurable interval to keep facet label resolution warm (retroactive annotation)
- [x] task-5: faceting-configuration#Facet label resolution for entity references — `NameCacheWarmupJob::run` runs the nightly UUID-to-name cache warmup for facet label resolution (retroactive annotation)

## workflow-operations (extend, existing REQs)

- [x] task-6: workflow-operations#Execution History Retention — `ExecutionHistoryCleanupJob::run` prunes workflow execution rows older than `workflow_execution_retention_days` (default 90) daily (retroactive annotation)
- [x] task-7: workflow-operations#Scheduled Workflow Triggers — `ScheduledWorkflowJob::run` evaluates enabled scheduled workflows every 60s and executes those whose interval has elapsed via the engine registry (retroactive annotation)

## rapportage-bi-export (extend, existing REQ)

- [x] task-8: rapportage-bi-export#The system MUST support scheduled report generation — `ReportRenderJob::run` renders due dashboards in the `reports` register daily and delivers to the configured Files folder (retroactive annotation)

## search-index (extend existing + new REQ)

- [x] task-9: search-index#Bulk indexing fetches searchable-schema objects from the database in batches — `SolrWarmupJob::run` bulk-indexes all schemas up to a cap after imports to warm the Solr index (retroactive annotation)
- [x] task-10: search-index#Asynchronous file text extraction MUST run as a queued background job — `FileTextExtractionJob::run` extracts text from a single uploaded file via `TextExtractionService` off the request path (new REQ)

## retention-management (extend, existing REQ)

- [x] task-11: retention-management#The system MUST generate destruction lists via a background job — `ArchivalRetentionTask::run` sweeps every `x-openregister-archival` schema hourly and deletes rows past retention via the retention-sweep delete path (retroactive annotation)

## object-lifecycle (new REQs)

- [x] task-12: object-lifecycle#Declared initial lifecycle state applied on create — `LifecycleInitialStateListener::handle` force-sets the schema's declared `initial` lifecycle value on `ObjectCreatingEvent` when the caller left it empty (new REQ)
- [x] task-13: object-lifecycle#Direct lifecycle-field edits guarded on update — `LifecycleValidationListener::handle` rejects lifecycle-field changes with no allowing transition and runs the optional `requires` guard on `ObjectUpdatingEvent`, stopping propagation with a structured error (new REQ)
