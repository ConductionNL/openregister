# data-sync-harvesting

## ADDED Requirements

### Requirement: An app registers a fetcher for its own source type (REQ-HAF-001)

OpenRegister SHALL dispatch `RegisterSourceFetchersEvent` once, when the fetcher registry is first built, and SHALL register its own `RestApiSourceFetcher` through the same event. A fetcher MUST declare a type, a display name and a JSON Schema for its source config. An app's type MUST be namespaced `<appid>.<protocol>`. A second fetcher for a type already registered SHALL be refused: the registry logs an error naming both classes and keeps the first. `GET /api/sources/types` SHALL list every registered type with its display name, owning app and config schema. Saving a source whose `config` fails its fetcher's schema SHALL be refused with HTTP 422 naming the field.

#### Scenario: an app's fetcher appears in the type list

- **GIVEN** opencatalogi listens for `RegisterSourceFetchersEvent` and registers a fetcher of type `opencatalogi.dcat-jsonld`
- **WHEN** an administrator calls `GET /api/sources/types`
- **THEN** the list MUST hold `rest-api` and `opencatalogi.dcat-jsonld`, each with its display name, owning app and config schema
- @e2e exclude {API-only; covered by a unit test on the registry and the Newman collection}

#### Scenario: a duplicate type is refused, not swapped

- **GIVEN** a fetcher of type `opencatalogi.dcat-jsonld` is registered
- **WHEN** another class registers the same type
- **THEN** the registry MUST keep the first fetcher
- **AND** an error naming both classes MUST be logged
- @e2e exclude {registry-internal; covered by a unit test}

#### Scenario: a source whose fetcher is gone is kept and refused at run time

- **GIVEN** a source of type `opencatalogi.dcat-jsonld` and opencatalogi is disabled
- **WHEN** the source's harvest runs
- **THEN** the run MUST end `failed` with reason `fetcher-missing`
- **AND** the source and its sync records MUST remain
- @e2e exclude {needs an app disabled mid-test; covered by a unit test on the harvest node}

### Requirement: A fetcher may return whole items from one document (REQ-HAF-002)

A fetcher that implements `IBatchSourceFetcher` SHALL return a batch of external id to raw payload, a `complete` flag, a checkpoint and per-item errors in one call. The pipeline SHALL NOT issue a per-id fetch for items a batch carries. A batch is `complete` only when the fetcher read the whole source without a failed page and without hitting `maxItemsPerRun`.

#### Scenario: a catalogue document is harvested in one fetch

- **GIVEN** a source of a batch fetcher type whose document holds three items
- **WHEN** the harvest runs
- **THEN** the pipeline MUST make no per-item request
- **AND** three sync records MUST be written for this run
- @e2e exclude {backend pipeline; covered by a unit test with a stub batch fetcher}

### Requirement: A source carries its app, its config and its acting identity (REQ-HAF-003)

A source SHALL carry `application`, `config`, `runAs`, `schedule`, `flowId` (read only), `identityProperty`, `protectedFields`, `provenance`, `conflictRules` and `maxItemsPerRun` (default 1000, maximum 10000). A source with a `schedule` and no `runAs` SHALL be refused with HTTP 422 naming `runAs`; no identity SHALL be filled in on the caller's behalf. Every new column SHALL be nullable, so an existing source behaves as before until one of them is set.

#### Scenario: a schedule without an acting identity is refused

- **GIVEN** an administrator edits a source
- **WHEN** they save it with `schedule: "0 3 * * *"` and no `runAs`
- **THEN** the save MUST fail with HTTP 422 naming `runAs`
- **AND** no flow MUST be written
- @e2e exclude {API validation; covered by a controller test and the Newman collection}

### Requirement: A harvest runs as a node in a flow (REQ-HAF-004)

OpenRegister SHALL provide the flow node `openregister.harvest-source` with one config field, `sourceId`. One firing SHALL load the source, take a per-source lock, run gather, fetch and import under the run's acting identity, update the source's last-sync fields, and emit one item: the run summary (status `success`, `partial`, `failed` or `skipped`; counts for created, updated, unchanged, conflict, shadowed, rejected, tombstoned and errors; `complete`; at most 100 errors with external id and message). A firing that finds the source disabled, its fetcher missing or its mapping missing SHALL end `failed` with that reason. A firing that finds the lock held SHALL end `skipped` with reason `already-running`.

#### Scenario: a manual run records its summary in the run log

- **GIVEN** a source of type `rest-api` with a harvest flow
- **WHEN** an administrator starts the flow's manual trigger
- **THEN** the run log MUST hold the harvest node's output with the counts per outcome
- **AND** the source's `lastSyncDate` and `lastSyncStatus` MUST be updated
- @e2e exclude {flow-engine run; covered by a node unit test and an api-direct flow run test}

#### Scenario: a manual start during a scheduled run is skipped

- **GIVEN** a scheduled harvest of a source is running
- **WHEN** an administrator starts the same source's manual trigger
- **THEN** the second run MUST end `skipped` with reason `already-running`
- **AND** the first run MUST be unaffected
- @e2e exclude {timing-dependent; covered by a unit test holding the lock}

#### Scenario: the harvest writes as the run's identity

- **GIVEN** a source with `runAs: "harvest-bot"` and a target schema `harvest-bot` may write
- **WHEN** the scheduled run imports a new item
- **THEN** the created object's audit entry MUST name `harvest-bot`
- @e2e exclude {acting identity is asserted on the audit trail by an integration test}

### Requirement: A scheduled source has exactly one harvest flow (REQ-HAF-005)

Saving a source with `schedule` and `runAs` SHALL write one flow whose uuid is the source uuid and whose `app` is the source's `application` (or `openregister`). The flow SHALL hold `openregister.trigger-schedule` (cron from `schedule`, `runAs` from `runAs`) and `openregister.trigger-manual`, both into `openregister.harvest-source`, into `end`. A second save SHALL update the same flow. `syncEnabled: false` SHALL disable the flow; deleting the source SHALL delete the flow and keep its sync records. `POST /api/sources/{id}/sync` SHALL start the manual trigger when the source has a flow and return the run id.

#### Scenario: saving twice keeps one flow

- **GIVEN** a source saved with a schedule and `runAs`
- **WHEN** the administrator changes the schedule and saves again
- **THEN** exactly one flow with the source's uuid MUST exist
- **AND** its schedule trigger MUST carry the new cron expression
- @e2e exclude {flow materialisation; covered by a SourceService unit test and the Newman collection}

#### Scenario: sync now starts the flow

- **GIVEN** a source with a harvest flow
- **WHEN** an administrator calls `POST /api/sources/{id}/sync`
- **THEN** the response MUST carry the id of a run of that flow
- **AND** no harvest MUST run inline in the request
- @e2e exclude {API-only; covered by the Newman collection}

### Requirement: Collisions are detected by edit time and carry a reason (REQ-HAF-006)

The import stage SHALL treat an item as a collision when no sync record exists and a local object in the target schema already holds the external id in `identityProperty` (reason `pre-existing-claim`), when the linked object's `updated` is later than the record's `lastAppliedAt` (reason `local-edited`), or when the linked object was deleted locally (reason `local-deleted`). Each write the harvest itself makes SHALL set `lastAppliedAt`, so a harvest never collides with itself. The local read SHALL write no audit entry. A record without `lastAppliedAt` SHALL fall back to the content hash comparison once.

#### Scenario: a local edit after the last harvest is a collision

- **GIVEN** an item imported on 1 October and the local object edited on 3 October
- **WHEN** the harvest runs on 4 October with a changed upstream payload
- **THEN** the item MUST be a collision with reason `local-edited`
- @e2e exclude {pipeline decision; covered by a table-driven unit test}

#### Scenario: the harvest's own update is not a collision

- **GIVEN** an item the harvest updated on 2 October and no local edit since
- **WHEN** the harvest runs on 4 October with a changed upstream payload
- **THEN** the local object MUST be updated and the record MUST be `imported`
- @e2e exclude {pipeline decision; covered by a table-driven unit test}

### Requirement: A collision follows the source's strategy, or a decision table (REQ-HAF-007)

A collision SHALL follow `conflictStrategy`: `manual` leaves the local object untouched, keeps the harvested payload and sets status `conflict`; `source-wins` updates the local object (the previous state stays as a version) and sets `imported`; `local-wins` leaves it untouched, keeps the payload and sets `shadowed`; `reject` leaves it untouched, clears the payload, never links and sets `rejected`; `newest-wins` behaves as today. A collision with reason `local-deleted` SHALL be `conflict` whatever the strategy. When the source carries `conflictRules`, the shared decision-table evaluator SHALL pick the strategy per item from `conflictReason`, `externalId` and the mapped payload's top-level fields, and SHALL fall back to `conflictStrategy` when no row matches. A `conflictRules` table that the decision-table validator refuses SHALL fail the source save with HTTP 422.

#### Scenario: a locally deleted object is never recreated

- **GIVEN** a source with `conflictStrategy: "source-wins"` and an item whose local object was deleted
- **WHEN** the harvest runs with a changed upstream payload
- **THEN** the object MUST NOT be recreated
- **AND** the record MUST be `conflict` with reason `local-deleted`
- @e2e exclude {pipeline decision; covered by a unit test}

#### Scenario: a decision table picks reject for one reason

- **GIVEN** a source with `conflictStrategy: "manual"` and `conflictRules` mapping `pre-existing-claim` to `reject`
- **WHEN** an item collides with reason `pre-existing-claim`
- **THEN** the record MUST be `rejected` with no payload kept
- **AND** an item colliding with reason `local-edited` MUST be `conflict`
- @e2e exclude {decision-table evaluation; covered by a unit test with the real evaluator}

### Requirement: Protected fields and provenance are never taken from the source (REQ-HAF-008)

After mapping, the pipeline SHALL drop every property named in `protectedFields` from the payload it writes, on create and on update, and SHALL set the properties named in `provenance` (`externalIdProperty` to the external id, `sourceProperty` to the source uuid) so the mapping cannot override them. A resolution SHALL apply the same rule.

#### Scenario: a harvest never publishes

- **GIVEN** a source with `protectedFields: ["publicationDate", "depublicationDate", "status"]` and a mapping that writes `status: "published"`
- **WHEN** the harvest creates an object
- **THEN** the object MUST carry no `status` from the harvest
- **AND** its `dct:source` MUST be the item's external id when `provenance.externalIdProperty` is `dct:source`
- @e2e exclude {pipeline write; covered by a unit test through the real MappingService}

### Requirement: A conflict is resolved through the API by the person who may edit the record (REQ-HAF-009)

`GET /api/sources/{id}/sync-records?status=conflict` SHALL list conflicts with the harvested payload (`rawData`), the payload after mapping (`mappedData`, see REQ-HAF-012) and the local object uuid, limited for a non-administrator to records whose local object the caller may update. `POST /api/sources/{id}/sync-records/{recordId}/resolve` SHALL take `keep-local` (status `shadowed`), `use-harvested` or `merge` with a per-property choice (status `imported`, `lastAppliedAt` set), or `discard` (status `rejected`). The write SHALL run under the caller's own rights; a caller who may not update the local object SHALL get HTTP 403 and the record SHALL stay `conflict`. Each resolution SHALL append `resolvedBy`, `resolvedAt`, `action`, `fields`, `objectUuid` and `bulk` to the record's `resolutions`. The bulk route SHALL resolve each record on its own and report each outcome; one failure SHALL NOT roll back the others.

#### Scenario: a merge takes one field from each side

- **GIVEN** a `conflict` record whose harvested `title` differs from the local one and whose `description` differs too
- **WHEN** an editor who may update the object resolves it with `merge`, `title: harvested`, `description: local`
- **THEN** the object MUST carry the harvested title and the local description
- **AND** the record MUST be `imported` with a resolution entry naming the editor
- @e2e exclude {API-only in OpenRegister; the review screen and its e2e belong to the consuming app}

#### Scenario: a reader without update rights is refused

- **GIVEN** a `conflict` record and a user who may read but not update its object
- **WHEN** that user resolves it with `use-harvested`
- **THEN** the response MUST be HTTP 403
- **AND** the record MUST stay `conflict`
- @e2e exclude {authorisation check; covered by a controller test with a real RBAC fixture}

### Requirement: The conflict queue carries the payload after mapping (REQ-HAF-012)

Each record in the response of `GET /api/sources/{id}/sync-records` with status `conflict` SHALL carry `mappedData`: the record's `rawData` run through the source's mapping with the same `MappingService::executeMapping()` call the import uses, with every `protectedFields` property removed and the `provenance` properties set, so `mappedData` is exactly the payload `use-harvested` would write. `mappedData` SHALL be computed when the queue is read, so it follows the source's current mapping. When the mapping fails for a record, that record SHALL carry `mappedData: null` and `mappingError` with the failure message, and SHALL stay in the response. `rawData` SHALL stay in the response unchanged.

#### Scenario: a review screen compares mapped fields with the local object

- **GIVEN** a source whose mapping turns `dct:title` into `title` and whose `protectedFields` holds `status`
- **AND** a `conflict` record whose `rawData` carries `dct:title: "Nieuwe titel"` and `status: "published"`
- **WHEN** an editor who may update the local object reads `GET /api/sources/{id}/sync-records?status=conflict`
- **THEN** that record's `mappedData.title` MUST be `"Nieuwe titel"`
- **AND** `mappedData` MUST carry no `status`
- **AND** its `rawData` MUST still carry `dct:title`
- @e2e exclude {API-only in OpenRegister; the review modal and its e2e belong to opencatalogi}

#### Scenario: a mapping that now fails does not hide the record

- **GIVEN** a `conflict` record and a source mapping edited since so that it fails on that record
- **WHEN** the queue is read
- **THEN** the record MUST be in the response with `mappedData: null` and a `mappingError` message
- @e2e exclude {API-only; covered by a controller test with a failing mapping fixture}

### Requirement: Items missing from a complete fetch are tombstoned, never deleted by default (REQ-HAF-010)

After a run whose fetch was `complete`, every sync record of the source not seen in that run SHALL get `tombstoned: true` and `tombstonedAt`; a record seen again SHALL have both cleared. After an incomplete run no record SHALL be tombstoned. `deleteStrategy` SHALL accept `flag` (tombstone only, the default for a source created after this change), `soft-delete` and `ignore`; `hard-delete` SHALL be refused for a source with an `application`.

#### Scenario: an incomplete fetch tombstones nothing

- **GIVEN** a source with ten records and a fetch that fails on its second page
- **WHEN** the run ends
- **THEN** the run MUST be `partial` with `complete: false`
- **AND** no record MUST be tombstoned
- @e2e exclude {pipeline rule; covered by a unit test with a stub batch fetcher}

### Requirement: Harvest HTTP calls pass the outbound guard (REQ-HAF-011)

Every harvest request SHALL go through `HarvestHttpClient`, which applies the shared outbound URL guard (http or https only; no loopback, private, link-local or metadata address, checked on every redirect hop), a 5 s connect and 30 s read timeout, at most 3 attempts with backoff on a 5xx, a 429 (honouring `Retry-After`) or a timeout, and a 50 MB body cap. The guard SHALL be the one `WebhookService` uses, moved to a shared service, with no change to webhook behaviour.

#### Scenario: a redirect to the metadata address is refused

- **GIVEN** a source URL that redirects to `http://169.254.169.254/latest/meta-data`
- **WHEN** the fetcher requests it through `HarvestHttpClient`
- **THEN** the request MUST be refused before the redirect is followed
- **AND** the run MUST record the error for that source
- @e2e exclude {network guard; covered by a unit test with a mocked resolver}

## MODIFIED Requirements

### Requirement: Scheduled sync MUST use Nextcloud's BackgroundJob infrastructure with configurable intervals
A source WITHOUT a harvest flow MUST keep being scheduled by the `SyncDataJob` TimedJob (following the pattern of `SyncConfigurationsJob`, which runs hourly and checks each configuration's `syncInterval`), so an upgrade stops no existing harvest. `SyncDataJob` MUST skip every source that has a `flowId`; such a source is scheduled only by its flow's schedule trigger (REQ-HAF-005). The job MUST log one warning per run naming the sources still on it. The scheduler MUST handle overlapping executions by skipping a run if the previous execution is still in progress.

#### Scenario: Cron-based scheduling with interval check
- **GIVEN** sync source `BAG Adressen` configured with `syncInterval: 24` (hours), `syncEnabled: true` and no `flowId`
- **AND** `lastSyncDate` is `2026-03-18T02:00:00Z`
- **WHEN** the `SyncDataJob` TimedJob runs at `2026-03-19T02:00:00Z` (24 hours later)
- **THEN** the system MUST determine the source is due for sync (`hoursPassed >= syncInterval`)
- **AND** queue a sync execution for this source

#### Scenario: Skip execution if previous sync still running
- **GIVEN** sync source `BAG Adressen` has a running sync execution (status: `running`)
- **WHEN** the scheduler checks if a new sync should start
- **THEN** the system MUST skip this source with log: `"Skipping BAG Adressen: previous sync still running (started 2026-03-19T02:00:00Z)"`
- **AND** NOT queue a new execution

#### Scenario: Multiple sources with independent schedules
- **GIVEN** three sync sources:
  - `BAG Adressen`: every 24 hours
  - `KvK Bedrijven`: every 6 hours
  - `Productenlijst CSV`: every 1 hour
- **WHEN** the master `SyncDataJob` runs hourly
- **THEN** each source MUST be independently evaluated against its own `syncInterval` and `lastSyncDate`
- **AND** only due sources MUST be queued for execution

#### Scenario: A source with a flow is left to the flow engine
- **GIVEN** sync source `DCAT feed` has a `flowId` and `syncInterval: 1`
- **WHEN** the `SyncDataJob` TimedJob runs
- **THEN** the job MUST NOT harvest `DCAT feed`
- **AND** the source's harvest MUST run only when its flow's schedule or manual trigger fires
- @e2e exclude {background job selection; covered by a SyncDataJob unit test}
