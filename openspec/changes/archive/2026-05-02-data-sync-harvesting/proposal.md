# Data Sync and Harvesting

## Why

Government registers seldom live in a single system. To deliver on tender requirements for federated case handling and on the Dutch base registration integration story (BAG, BRK, BRP, HR), OpenRegister needs a first-class harvesting pipeline that pulls from external APIs, file feeds, and other OpenRegister instances on a configurable schedule. CKAN's gather/fetch/import three-stage pattern is the proven open-source reference for this kind of work and aligns with our existing `Source` / `Mapping` / `WebhookService` building blocks. Today none of this is wired up: `Source`, `SourceMapper`, `Mapping`, `SyncConfigurationsJob`, `HookRetryJob`, `WebhookService`, and `Configuration/ImportHandler` exist as scaffolding but no end-to-end sync flows ship.

## What Changes

- Add configurable sync source definitions (connection details, authentication, scheduling) on top of the existing `Source` entity / `SourceMapper`.
- Implement the three-stage CKAN-style pipeline (gather, fetch, import) with per-record status tracking and a complete sync audit trail integrated with the existing audit system.
- Add incremental sync via last-modified tracking or change tokens so re-runs only touch changed rows.
- Wire field mapping and transformation through the existing `Mapping` entity, with create/update/delete strategies and conflict resolution policies.
- Add scheduled execution via Nextcloud's `BackgroundJob` infrastructure (build on `lib/Cron/SyncConfigurationsJob.php`) and event/webhook-triggered execution via `WebhookService` and `HookRetryJob`.
- Validate imported data against the target schema before persistence (reuse the existing JSON Schema validator path).
- Store external authentication credentials securely.
- Add bi-directional sync for federated OpenRegister instances.
- Honour multi-tenant organisation isolation throughout the sync pipeline.
- Tune for scale with configurable batch sizes, throttling, concurrency limits, partial failure handling, and automatic retry.

## Problem
Implement a robust, multi-source data synchronization and harvesting pipeline that enables OpenRegister to pull data from external APIs (REST, OData, SOAP), file feeds (CSV, JSON, XML), other OpenRegister instances, and Dutch government base registrations (BAG, BRK, BRP, HR) into register schemas. The sync pipeline MUST follow CKAN's proven three-stage pattern (gather, fetch, import) with per-record status tracking, support both scheduled (cron) and event-triggered execution, and provide incremental sync via last-modified tracking or change tokens.

## Proposed Solution
Implement a robust, multi-source data synchronization and harvesting pipeline that enables OpenRegister to pull data from external APIs (REST, OData, SOAP), file feeds (CSV, JSON, XML), other OpenRegister instances, and Dutch government base registrations (BAG, BRK, BRP, HR) into register schemas. The sync pipeline MUST follow CKAN's proven three-stage pattern (gather, fetch, import) with per-record status tracking, support both scheduled (cron) and event-triggered execution, and provide increme
