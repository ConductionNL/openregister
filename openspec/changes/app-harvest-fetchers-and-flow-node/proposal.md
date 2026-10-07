---
kind: code
depends_on: []
---

# Proposal: app-harvest-fetchers-and-flow-node

## Summary

OpenRegister already harvests: `Source`, `SyncRecord`, `HarvestPipelineService` and `SyncConflictResolver` run a gather, fetch and import pipeline with change detection and conflict strategies. Two things keep apps out of it. The fetcher registry is filled in OpenRegister's own `Application.php` with `RestApiSourceFetcher` only, so no app can add a protocol. And `SyncDataJob` schedules harvests as a `TimedJob`, outside the flow engine, so an app that schedules everything through flows (ADR-065, One Engine) cannot use it.

This change opens both. An app registers a fetcher for its own source type through an event. A harvest runs as a node in a flow, started by the flow engine's schedule or manual trigger under a declared `runAs`. The pipeline gains the item states, conflict reasons, resolution verbs, protected fields and tombstones that an app's harvest needs, so the app keeps its feed settings on an OpenRegister `Source` and its item states on `SyncRecord` instead of shipping its own schemas (ADR-022).

## Why

Decision 80 (Ruben, 7 Oct 2026): "OpenRegister change first. Specify in OpenRegister a way for apps to register fetchers and run harvests as flows; opencatalogi then uses it and its harvest specs drop their own schemas."

opencatalogi's `harvest-feed-intake` and `harvest-conflict-policies` (both on opencatalogi `development`) designed around the gap. Their D1 tables name it: the registry is closed and `SyncDataJob` sits outside the flow engine. So they specified three app schemas (`harvest-feed`, `harvested-item`, `harvest-run`) that duplicate `Source`, `SyncRecord` and the run log. Their D7 asked for exactly this change.

## What changes

- `RegisterSourceFetchersEvent`: an app registers a fetcher for a namespaced source type (`opencatalogi.dcat-jsonld`) with a display name and a JSON Schema for its own config. `GET /api/sources/types` lists them for forms.
- A fetcher MAY return whole items from one document (`IBatchSourceFetcher`) and report whether the fetch was complete. A DCAT catalogue is one document, not an id list.
- `Source` gains `application`, `config`, `runAs`, `schedule`, `flowId`, `identityProperty`, `protectedFields`, `provenance` and `conflictRules`.
- Flow node `openregister.harvest-source` runs the pipeline for one source. A source with a schedule gets one flow (schedule trigger and manual trigger into the node), written to the owning app's flow store and keyed by the source uuid. "Sync now" starts the manual trigger. `SyncDataJob` stops picking up any source that has a flow.
- Item states grow from the current set to include `shadowed` and `rejected`, with a `conflictReason` (`local-edited`, `local-deleted`, `pre-existing-claim`) and a `tombstoned` flag. Strategy `reject` joins `source-wins`, `local-wins`, `newest-wins` and `manual`. A source MAY pick its strategy per item through a decision table evaluated by the shared DMN evaluator.
- `POST /api/sources/{id}/sync-records/{recordId}/resolve` (and a bulk form) resolves a conflict: keep local, use harvested, merge per field, discard. It writes under the resolving user's own rights and appends to the record's `resolutions`.
- Harvest HTTP goes through one guarded client: the outbound guard that `WebhookService` already applies, moved to a shared service, plus timeouts, retry with backoff and a body cap.

## Rows covered

- `x-harvest-apps` (added to this repo's `openspec/parity/capabilities.json` in spec round part 2): "Let an app add its own harvest protocol and run its harvests as scheduled flows."
- opencatalogi rows that rest on it: `od-harvest`, `od-harvest-conflict`. opencatalogi links this change as `openregister/app-harvest-fetchers-and-flow-node` and revises `harvest-feed-intake` and `harvest-conflict-policies` to drop their three schemas.
- `x-harvest` stays `decided-no`: it is about OpenRegister's own harvesting screen, which this change does not build.

## Non-goals

- No fetcher for DCAT, OAI-PMH, CKAN or any protocol besides the existing REST fetcher. Protocols belong to the app that needs them.
- No review screen in OpenRegister. The resolve API serves the app's own queue (opencatalogi builds it); OpenRegister's sources page is unchanged apart from the type list.
- No bi-directional or federated sync, no webhook trigger. The existing requirements for those stay as they are.

## Capabilities

### Modified capabilities

- `data-sync-harvesting`: app-registered fetchers, the harvest node and its flow, item states and conflict resolution, protected fields and provenance, tombstones, guarded fetch. The scheduling requirement moves from `TimedJob` to the flow engine.
