# Tasks: Data Sync and Harvesting

> **Status (2026-05-02): closed by decision — moved to OpenConnector.** The user's explicit decision is that sync orchestration is OpenConnector's domain; OpenRegister exposes the hooks (event-driven save pipeline, `CustomScopeEvaluatingEvent`, `softDeleteByImportJobId` rollback contract, `saveObjectsStreaming` primitive, the abstract tab system tracked under a separate change) and OpenConnector subscribes to them.
>
> All 16 requirements below are inherited by OpenConnector. They are checked off here as "out-of-OR-scope" to close this change in the "all specs completed" sweep, with the understanding that the work itself lives at https://github.com/ConductionNL/openconnector and is tracked via the hand-off ticket [ConductionNL/openconnector#737](https://github.com/ConductionNL/openconnector/issues/737).

## Hooks OpenRegister exposes for OpenConnector to subscribe against

These are the integration points OpenConnector should use rather than reaching into OR internals:

- **Event-driven save pipeline** — `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `ObjectRevertedEvent` dispatched via `IEventDispatcher` on every write.
- **`CustomScopeEvaluatingEvent`** — for sync-pipeline custom action verbs (e.g. `sync_pull`, `sync_push`).
- **`softDeleteByImportJobId(string)`** — rollback contract; sync runs that fail mid-batch can hand back the import-job UUID for unit rollback.
- **`SaveObject::saveObjectsStreaming()`** — streaming bulk-upsert primitive that engages the request-scoped reference-validation cache for O(1) per-row checks.
- **Abstract tab system** (separate change) — registers a "Sync" / "Contracts" tab on object/register/schema detail pages so OpenConnector renders sync configuration in the OpenRegister UI without forking the layout.

## Inherited requirements (now OpenConnector's responsibility)

- [x] Implement: The system MUST support configurable sync source definitions with connection details, authentication, and scheduling
- [x] Implement: The sync pipeline MUST follow a three-stage pattern (gather, fetch, import) with per-record status tracking
- [x] Implement: The system MUST support incremental sync using last-modified tracking or change tokens
- [x] Implement: The system MUST support field mapping and transformation via the existing Mapping entity
- [x] Implement: Sync MUST support create, update, and delete operations with configurable strategies
- [x] Implement: Sync MUST support conflict resolution with configurable strategies
- [x] Implement: Sync executions MUST produce detailed monitoring reports and maintain execution history
- [x] Implement: The system MUST handle errors gracefully with partial failure support and automatic retry
- [x] Implement: Authentication credentials for external sources MUST be stored securely
- [x] Implement: Imported data MUST be validated against the target schema before persistence
- [x] Implement: The system MUST maintain a complete sync audit trail integrated with the existing audit system
- [x] Implement: The system MUST support bi-directional sync for federated OpenRegister instances
- [x] Implement: The system MUST support webhook-triggered and event-triggered sync in addition to scheduled sync
- [x] Implement: Sync performance MUST be optimized with configurable batch sizes, throttling, and concurrency limits
- [x] Implement: Sync MUST respect multi-tenant organisation isolation
- [x] Implement: Scheduled sync MUST use Nextcloud's BackgroundJob infrastructure with configurable intervals
