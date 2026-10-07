# Tasks: app-harvest-fetchers-and-flow-node

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 20. -->

## 1. Fetcher registration (REQ-HAF-001, REQ-HAF-002, D-2, D-3)

- [ ] 1.1 `lib/Service/Sync/RegisterSourceFetchersEvent.php` (shape of `RegisterFlowNodesEvent`) and `SourceFetcherInterface` gains `getType()`, `getDisplayName()`, `getConfigSchema()`; `SourceFetcherRegistry` dispatches the event once on first build, looks up by type, refuses a duplicate with a logged error naming both classes. Replace the hand-filled registry in `lib/AppInfo/Application.php:919-922`; `RestApiSourceFetcher` registers through the event.
- [ ] 1.2 `IBatchSourceFetcher` and value object `HarvestBatch` (`items`, `complete`, `checkpoint`, `errors`); `HarvestPipelineService` skips the per-id fetch stage for a batch fetcher and enforces `maxItemsPerRun` (items past the cap make the batch incomplete).
- [ ] 1.3 `GET /api/sources/types` in `SourcesController` + `appinfo/routes.php`, admin only.

## 2. Source fields (REQ-HAF-003, D-4)

- [ ] 2.1 Migration adding the ten nullable columns of D-4 to `openregister_sources`; `lib/Db/Source.php` types and `jsonSerialize`; bump `appinfo/info.xml` above every stamp on `development`.
- [ ] 2.2 `lib/Service/Sync/SourceService.php`: create, update, delete with validation (config against the fetcher schema, `runAs` required with `schedule` and resolvable to an enabled account, `conflictRules` through `DecisionTableValidator`, `hard-delete` refused when `application` is set), each refusal a 422 naming the field. `SourcesController` create and update go through it.

## 3. Harvest node and flow (REQ-HAF-004, REQ-HAF-005, D-5, D-6)

- [ ] 3.1 `lib/Service/Flow/Nodes/HarvestSourceNode.php` (`openregister.harvest-source`, config `sourceId`): lock through `ILockingProvider`, run the pipeline, update last-sync fields, emit the D-10 summary; refusals `source-disabled`, `fetcher-missing`, `mapping-missing`, `already-running`. Register it with the engine's own nodes.
- [ ] 3.2 `SourceService` writes, updates, disables and deletes the source's flow through `FlowService::save()`/`delete()` keyed by the source uuid (D-6 graph); sets `flowId`.
- [ ] 3.3 `SourcesController::syncNow()`: start the manual trigger when `flowId` is set and return the run id; keep the inline run otherwise.
- [ ] 3.4 `lib/BackgroundJob/SyncDataJob.php`: skip sources with a `flowId`; log one warning per run naming the sources still on the job.

## 4. Decisions, strategies and states (REQ-HAF-006, REQ-HAF-007, REQ-HAF-008, D-7, D-8)

- [ ] 4.1 Migration on `openregister_sync_records`: `conflict_reason`, `tombstoned`, `tombstoned_at`, `last_applied_at`, `resolutions` (json). `SyncRecord` types; `SyncRecordStatus` gains `shadowed`, `rejected` and the D-8 transitions.
- [ ] 4.2 `HarvestPipelineService::importRecord()`: the D-7 table (pre-existing claim via `identityProperty`, edit time against `lastAppliedAt`, local delete, hash fallback once); local read with `_audit: false`; `lastAppliedAt` on every harvest write; `protectedFields` dropped and `provenance` set after mapping.
- [ ] 4.3 `SyncConflictResolver`: strategy `reject`; `local-deleted` forced to `manual`; `conflictRules` evaluated with `DecisionTableEvaluator`, falling back to `conflictStrategy`.

## 5. Resolution API (REQ-HAF-009, D-9)

- [ ] 5.1 `lib/Controller/SyncRecordsController.php`: `GET /api/sources/{id}/sync-records` (filter `status`; non-admins see only records whose object they may update), `POST .../sync-records/{recordId}/resolve` and bulk `POST .../sync-records/resolve`; `#[NoAdminRequired]` with the per-object update check before every write; writes under the caller's identity; append to `resolutions`.
- [ ] 5.2 `mappedData` on every `conflict` record of the queue response (REQ-HAF-012, D-9): run `rawData` through the source's mapping with the import's `MappingService::executeMapping()` call, remove `protectedFields`, set `provenance`; on a mapping failure `mappedData: null` plus `mappingError`, record kept. Unit test in `tests/Unit/Controller/SyncRecordsControllerTest.php`: mapped field present, protected field absent, failing mapping keeps the record. opencatalogi's `harvest-conflict-policies` task 2.2 waits on this.

## 6. Tombstones and guarded fetch (REQ-HAF-010, REQ-HAF-011, D-10, D-11)

- [ ] 6.1 Tombstone pass after a `complete` run; clear on re-seen; `deleteStrategy` `flag` as the default for new sources; `soft-delete` and `ignore` as today's names promise.
- [ ] 6.2 `lib/Service/Http/OutboundUrlGuard.php` from `WebhookService::assertSafeWebhookUri()`, `isPrivateHost()`, `blockedIpv6Reason()`; `WebhookService` calls it (its tests stay green unchanged). `lib/Service/Sync/HarvestHttpClient.php` with guard per hop, timeouts, retry and backoff, `Retry-After`, 50 MB cap; `RestApiSourceFetcher` moves onto it.

## 7. Sources page (D-14)

- [ ] 7.1 `src/modals/source/` edit modal: type select fed by `GET /api/sources/types` (`NcSelect` with `inputLabel`), `schedule` and `runAs` fields, `runAs` prefilled with the current admin visibly and editable.

## 8. Tests and docs

- [ ] 8.1 PHPUnit: registry (duplicate refused, event dispatched once), batch fetcher (no per-id call, cap makes incomplete), D-7 table row by row, D-8 strategy per row with the real decision-table evaluator, protected fields through the real `MappingService`, tombstone only after complete, guard on a redirect to `169.254.169.254`, node refusals and lock, flow materialisation idempotent by source uuid, resolution 403 and bulk partial failure, `SyncDataJob` skipping flow sources.
- [ ] 8.2 Newman: source types, a source with schedule and without `runAs` (422), sync now returning a run id, conflicts list and a resolve. Every `@e2e exclude` in the delta spec stays reason-bearing.
- [ ] 8.3 `docs/`: "Add a harvest protocol from your app": the event, the fetcher contract, the source fields, the resolution API. Tell opencatalogi the change name so `harvest-feed-intake` and `harvest-conflict-policies` drop `harvest-feed`, `harvested-item` and `harvest-run` (D-12). `@spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md` on every touched method; set row `x-harvest-apps` to built once shipped.
