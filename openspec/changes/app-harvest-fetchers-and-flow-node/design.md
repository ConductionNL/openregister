# Design: app-harvest-fetchers-and-flow-node

OpenRegister keeps one harvest pipeline. Apps plug protocols into it and run it through the flow engine. This document says what exists on `development` (7 Oct 2026), what each part of the change reuses, and which calls were made.

## D-1. What exists, and what is closed

| Part | Where | State today |
|---|---|---|
| Source settings | `lib/Db/Source.php` (`type`, `databaseUrl`, `authType`, `authConfig`, `mappingId`, `targetRegister`, `targetSchema`, `conflictStrategy`, `deleteStrategy`, `batchSize`, `syncEnabled`, `syncSchedule`, `syncInterval`, `lastSync*`) | Usable. No owning app, no fetcher config, no acting identity. |
| Item tracking | `lib/Db/SyncRecord.php` (`sourceId`, `executionId`, `externalId`, `status`, `objectUuid`, `contentHash`, `rawData`, `attempts`) and `lib/Service/Sync/SyncRecordStatus.php` (pending, fetched, fetch_error, imported, unchanged, import_error, conflict, skipped, permanent_error) | Usable. No conflict reason, no tombstone, no resolution history. |
| Pipeline | `lib/Service/Sync/HarvestPipelineService.php::run()` gather, fetch, import; mapping through `MappingService::executeMapping()`; writes through `ObjectService::saveObject()` | Usable. Per-id fetch only. Deletes are never applied: `deleteStrategy` is stored and not read. |
| Conflicts | `lib/Service/Sync/SyncConflictResolver.php` (source-wins, local-wins, newest-wins, manual → apply_source, keep_local, defer) | Usable. "Local changed" is a hash of the whole local object against the hash of the last mapped payload (`HarvestPipelineService::localChangedSinceSync()`), which reads a conflict whenever the object holds a field the mapping does not write. Its `ObjectService::find()` also writes a `read` audit entry per item. A locally deleted object reads as "not changed". |
| Fetchers | `SourceFetcherInterface` (`supports`, `gather`, `fetch`), `SourceFetcherRegistry`, filled in `lib/AppInfo/Application.php:919-922` with `RestApiSourceFetcher` only | **Closed to apps.** |
| Schedule | `lib/BackgroundJob/SyncDataJob.php`, hourly `TimedJob`, `SyncScheduleService::selectDueSources()` | **Outside the flow engine**, runs with no acting identity. |
| Run now | `POST /api/sources/{id}/sync` → `SourcesController::syncNow()` runs the pipeline inline in the request | Usable, admin only. |
| Flow engine | `RegisterFlowNodesEvent` (`lib/Service/Flow/RegisterFlowNodesEvent.php`), `IFlowNode`, `openregister.trigger-schedule` with required `cron` and `runAs` (`lib/Service/Flow/Nodes/TriggerScheduleNode.php:85`), manual trigger, contributed nodes run inside `ObjectService::runAs()` (`flow-engine-consumer-seams`), `FlowService::save()` keyed by uuid | The model to copy. |
| Decision tables | `lib/Service/Dmn/DecisionTableEvaluator.php`, `DecisionTableValidator.php` (`flow-decision-tables`) | Reused for rule-shaped strategies. |
| Outbound guard | `WebhookService::assertSafeWebhookUri()` (`lib/Service/WebhookService.php:293`) with `isPrivateHost()` and `blockedIpv6Reason()` | Private to webhooks. `RestApiSourceFetcher` sets timeouts (`:117`) but no address guard. |

## D-2. Apps register fetchers through an event

`RegisterSourceFetchersEvent` is dispatched once, when `SourceFetcherRegistry` is first built, in the same shape as `RegisterFlowNodesEvent` and `RegisterMappingFunctionsEvent`. OpenRegister registers `RestApiSourceFetcher` itself through the same event, so there is one path.

A fetcher declares:

| Method | Meaning |
|---|---|
| `getType()` | `<appid>.<protocol>`, for example `opencatalogi.dcat-jsonld`. OpenRegister's own types keep their bare names (`rest-api`). |
| `getDisplayName()` | Shown in the source form's type list. |
| `getConfigSchema()` | JSON Schema for `Source.config`. A save whose `config` fails it is refused with 422 naming the field. |

`supports()` stays for backward compatibility; the registry looks types up by `getType()`. A second fetcher for a type already registered is refused: the registry logs an error naming both classes and keeps the first. Silent replacement would let one app swap another app's protocol.

`GET /api/sources/types` returns `{type, displayName, application, configSchema}` per fetcher, so an app's form and OpenRegister's sources page read the same list.

A source whose type has no registered fetcher (the app was disabled) stays readable and is refused at run time with the reason `fetcher-missing`; it is never deleted.

## D-3. Whole-document fetchers

A DCAT catalogue, a CSV file or an OAI-PMH page carries its items in one response. `IBatchSourceFetcher extends SourceFetcherInterface` adds:

```php
public function gatherItems(Source $source, ?string $since, HarvestHttpClient $http): HarvestBatch;
```

`HarvestBatch` holds `items` (external id → raw payload), `complete` (true when the fetcher read the whole source without a failed page), `checkpoint` (written to `lastSyncToken`) and `errors` (external id or null, message). The pipeline skips its per-id fetch stage for a batch fetcher; this is how the existing requirement "No per-record refetch when the collection carries bodies" is met for app fetchers.

## D-4. Source fields

Added to `openregister_sources` by one migration, all nullable:

| Field | Type | Meaning |
|---|---|---|
| `application` | string | Owning app id. Set by the app's write; shown on the sources page. |
| `config` | json | Fetcher config, validated against the fetcher's schema (D-2). Secrets stay in `authConfig`. |
| `runAs` | string | User id the harvest runs as. Required when `schedule` is set. |
| `schedule` | string | Cron expression. Replaces `syncInterval` for flow-run sources. |
| `flowId` | string | Uuid of the harvest flow (D-6). Read only. |
| `identityProperty` | string | Property on the target object that carries the external id, used to spot a pre-existing local claim (D-7). |
| `protectedFields` | json | Property names the harvest never writes, on create or update. |
| `provenance` | json | `{externalIdProperty, sourceProperty}`: properties the pipeline sets after mapping, so the mapping cannot override them. |
| `conflictRules` | json | Optional decision table that picks a strategy per item (D-8). |
| `maxItemsPerRun` | int | Default 1000, maximum 10000. Items past the cap are left for the next run and the batch counts as incomplete. |

An app writes its sources through `SourceService` (new, wrapping `SourceMapper` with the validation above), never through raw mapper calls. The REST surface stays `api/sources` and is admin only, as today.

opencatalogi's `harvest-feed` maps one to one: `sourceUrl` or `sourceSlug` and `targetCatalog` go in `config`; `mapping` is `mappingId`; `protocol` is `type`.

## D-5. The harvest node

`openregister.harvest-source` is an engine node (`lib/Service/Flow/Nodes/HarvestSourceNode.php`). Config: `sourceId` only. It reads everything else from the source at run time, so editing a source needs no flow rewrite.

One firing:

1. Load the source. Refuse with run status `failed` and reason `source-disabled`, `fetcher-missing` or `mapping-missing`.
2. Take a per-source lock through Nextcloud's `ILockingProvider` (key `openregister/harvest/<source uuid>`). The scheduler already never overlaps a flow with itself (`FlowScheduleService.php:118`), but a manual start can land during a scheduled run. A run that finds the lock held ends `skipped` with reason `already-running`. This replaces `SyncScheduleService`'s running check.
3. Run the pipeline under the run's acting identity (the engine's `runAs` scope), with the guarded client (D-11).
4. Update `lastSyncDate`, `lastSyncStatus`, `lastSyncToken`.
5. Emit one item: the run summary (D-10). The run log records it, so the flow run is the harvest run; there is no separate run table.

## D-6. One flow per scheduled source

- Saving a source with `schedule` and `runAs` writes one flow through `FlowService::save()`, uuid equal to the source uuid, `app` equal to `Source.application` (or `openregister`). A second save updates the same flow.
- The flow: `openregister.trigger-schedule` (`cron` = `schedule`, `runAs` = `runAs`) and `openregister.trigger-manual`, both into `openregister.harvest-source`, into `end`.
- A source with `schedule` and no `runAs` is refused with 422. The form MAY offer the saving administrator as the value, visibly; nothing fills it in silently. This is the same rule the schedule trigger already enforces (`flow-engine`, "A schedule trigger MUST declare a resolvable acting identity or fail to save").
- `syncEnabled: false` disables the flow. Deleting the source deletes the flow; its sync records stay as history.
- `POST /api/sources/{id}/sync` starts the flow's manual trigger when the source has a flow, and returns the run id. Without a flow it keeps today's inline run.

`SyncDataJob` skips every source with a `flowId`. Sources without one keep the old path until an administrator sets `schedule` and `runAs`. The job is not removed in this change, because removing it would stop every existing scheduled source on upgrade with no acting identity to move it to. The job logs one warning per run naming the sources still on it. Removing it is a follow-up once that list is empty.

## D-7. Item decisions

The import stage decides per item. "Edited locally" means the object's `@self.updated` is later than the record's new `lastAppliedAt`. The harvest's own write sets both, so a harvest never conflicts with itself. This replaces the whole-object hash, and the local read uses `_audit: false` so a harvest writes no read entries.

| Situation | Outcome |
|---|---|
| No record, no local claim | `imported` (created) |
| Record, hash equal | `unchanged` |
| Record, hash differs, not edited locally | `imported` (updated) |
| No record, a local object in the target schema already has this external id in `identityProperty` | collision, reason `pre-existing-claim` |
| Record, edited locally since `lastAppliedAt` | collision, reason `local-edited` |
| Record, linked object deleted locally | collision, reason `local-deleted` |

A collision goes to the strategy (D-8), with one override: `local-deleted` is always `conflict`, whatever the strategy. Recreating something a person removed is a decision for a person.

## D-8. Strategies and states

| Strategy | On a collision | Record status | `rawData` |
|---|---|---|---|
| `manual` | Local untouched, record queued | `conflict` | kept |
| `source-wins` | Local updated, previous state kept as a version | `imported` | cleared |
| `local-wins` | Local untouched | `shadowed` (new) | kept |
| `reject` (new) | Local untouched, never linked | `rejected` (new) | cleared |
| `newest-wins` | As today | `imported` or `shadowed` | as above |

`conflictRules` is a decision table in the `flow-decision-tables` shape. Inputs: `conflictReason`, `externalId` and the mapped payload's top-level fields. One output: `strategy`. Hit policy `FIRST` or `UNIQUE`. No match falls back to `conflictStrategy`, the explicit default that `flow-decision-tables` requires. `DecisionTableValidator` checks it when the source is saved; `DecisionTableEvaluator` runs it per collision.

Transitions added to `SyncRecordStatus::TRANSITIONS`: `conflict` → `imported`, `shadowed`, `rejected` (by resolution); `shadowed` and `rejected` → a fresh evaluation when the upstream hash changes; `conflict` with a changed hash stays `conflict` with `rawData` refreshed.

opencatalogi's item states map onto these: `new` and `updated` are `imported` (create or update), `unchanged`, `conflict`, `shadowed` and `rejected` are the same names.

## D-9. Resolution

`POST /api/sources/{id}/sync-records/{recordId}/resolve` with `action` one of `keep-local`, `use-harvested`, `merge` (with `fields`: property → `local` or `harvested`), `discard`. `POST /api/sources/{id}/sync-records/resolve` takes a list for bulk; each item is resolved on its own and reports its own outcome, and one failure does not roll back the others.

- The write runs under the caller's own rights, not the source's `runAs`, so the audit trail names the person who decided. A caller who may not update the local object gets 403 and the record stays `conflict`.
- Outcomes: keep local → `shadowed`; use harvested and merge → `imported` with `lastAppliedAt` set; discard → `rejected`.
- `protectedFields` and the provenance properties are never taken from the harvested side, also on merge.
- Each resolution appends `{resolvedBy, resolvedAt, action, fields, objectUuid, bulk}` to the record's new `resolutions` column (append only).
- `GET /api/sources/{id}/sync-records?status=conflict` lists the queue with `rawData`, `mappedData` and the local object's uuid. `mappedData` is the harvested item after the source's mapping, with `protectedFields` and the provenance properties applied: exactly the payload `use-harvested` would write. It is computed when the queue is read, through the same `MappingService::executeMapping()` call the import uses, so it follows a mapping edited after the item was queued. A record whose mapping now fails carries `mappedData: null` and `mappingError` with the message; it stays in the queue. A field-by-field review screen (opencatalogi's review modal) compares `mappedData` with the local object property by property; `rawData` is in the source's own shape and cannot be compared that way. A non-admin caller sees only records whose local object they may update. These two routes are `NoAdminRequired` with that per-object check, so an app can show a queue to the people who own the records.

This is the API opencatalogi's review page needs. OpenRegister ships no review screen in this change.

## D-10. Tombstones and the run summary

- After a run whose batch is `complete`, every record of the source not seen in this run gets `tombstoned: true` and `tombstonedAt`. A record seen again clears both. After an incomplete run nothing is tombstoned. This is the rule integriq's `source-owned-records` set (REQ-SOR-004).
- Seen means returned in `items` or named in `errors`. A fetcher that refuses one item (opencatalogi's DCAT fetchers refuse a dataset that fails SHACL validation, `harvest-observability` D1) still read the whole source, so the batch stays `complete` and the refused item is not tombstoned. Its record keeps the last good `rawData`, hash and object link, and takes status `fetch_error` with the message in `errorMessage`, so the last good object stays published while the error is visible in the queue.
- `deleteStrategy` gains `flag` (the default for new sources): tombstone only, the local object is untouched. `soft-delete` additionally soft-deletes the object; `ignore` does nothing. `hard-delete` is not offered for app sources.
- The summary: `status` (`success`, `partial`, `failed`, `skipped`), counts for created, updated, unchanged, conflict, shadowed, rejected, tombstoned and errors, `complete`, and at most 100 errors as `{externalId, message}`.

## D-11. Guarded fetch

`OutboundUrlGuard` (new, `lib/Service/Http/OutboundUrlGuard.php`) takes the rules out of `WebhookService::assertSafeWebhookUri()`: http or https only, no loopback, private, link-local or metadata address, checked on every redirect hop. `WebhookService` calls the shared guard, so the webhook behaviour does not change.

`HarvestHttpClient` wraps Nextcloud's `IClientService` with the guard, 5 s connect and 30 s read timeouts, at most 3 attempts with backoff 2 s, 4 s, 8 s on a 5xx, a 429 (honouring `Retry-After`) or a timeout, and a 50 MB body cap. It is handed to every fetcher; `RestApiSourceFetcher` moves onto it. A fetcher MAY call through integriq's source abstraction instead; that is the app's choice and needs no OpenRegister code.

## D-12. What opencatalogi drops

| opencatalogi (current spec) | Becomes |
|---|---|
| schema `harvest-feed` | `Source` with `application: opencatalogi`, `type: opencatalogi.dcat-jsonld` |
| schema `harvested-item` | `SyncRecord` (+ `conflictReason`, `tombstoned`, `lastAppliedAt`, `resolutions`) |
| schema `harvest-run` | the flow run of the source's flow, summary as node output |
| node `opencatalogi.harvest-feed` | fetcher `opencatalogi.dcat-jsonld` registered through `RegisterSourceFetchersEvent`; the node is OpenRegister's |
| draft-only rule | `protectedFields: [publicationDate, depublicationDate, status]`, plus `unlisted` once opencatalogi's `publication-lifecycle-on-or` adds that property to the publication schema |
| `dct:source`, `prov:wasDerivedFrom` | `provenance: {externalIdProperty: "dct:source", sourceProperty: "prov:wasDerivedFrom"}` |
| policies `manual-review`, `overlay`, `shadow-local`, `reject-on-conflict` | strategies `manual`, `source-wins`, `local-wins`, `reject` |
| review queue and modal | app page on D-9's routes |

## D-13. Risks

- **Existing sources.** The new columns are nullable and `deleteStrategy` keeps its stored value, so an upgrade changes no behaviour until a source is given a schedule.
- **Status values.** `shadowed` and `rejected` are new strings in `status`. Code that switches on status (`deriveStatus()`, the sync-status route) gets a default branch; unknown values are counted, never dropped.
- **The local-edit rule changes.** Moving from hash comparison to `updated` against `lastAppliedAt` changes which items read as conflicts. Records without `lastAppliedAt` (written before the upgrade) fall back to the old hash check once, then carry the new field.

## D-14. Screen

OpenRegister is not a canvas app. The sources page (`src/views/source/`) gets only the type list from `GET /api/sources/types` and the new `schedule` and `runAs` fields in its edit modal. The review queue is an app screen.
