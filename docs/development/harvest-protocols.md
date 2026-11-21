---
title: Add a harvest protocol from your app
description: Register a source fetcher, describe its source fields, and resolve its conflicts through OpenRegister's one harvest pipeline
keywords:
  - harvest
  - source
  - fetcher
  - sync records
  - conflicts
---

# Add a harvest protocol from your app

OpenRegister keeps one harvest pipeline: gather, fetch, map, decide, write. An
app does not build its own. It contributes a **fetcher** for its protocol, and
OpenRegister does the rest: mapping, conflict handling, tombstones, scheduling
through the flow engine, and the resolution API.

Change: `openspec/changes/app-harvest-fetchers-and-flow-node`.

## 1. Register a fetcher

Listen for `OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent`. It is
dispatched once, when OpenRegister first builds its fetcher registry.

```php
$context->registerEventListener(RegisterSourceFetchersEvent::class, MyFetcherListener::class);

// in MyFetcherListener::handle()
$event->registerFetcher(fetcher: $this->dcatFetcher, appId: 'myapp');
```

A second fetcher for a type that is already registered is refused: the
registry logs an error naming both classes and keeps the first, so one app can
never replace another app's protocol.

## 2. The fetcher contract

Implement `SourceFetcherInterface`:

| Method | Meaning |
| --- | --- |
| `getType()` | `<appid>.<protocol>`, for example `myapp.dcat-jsonld`. |
| `getDisplayName()` | Shown in the source form's type list. |
| `getConfigSchema()` | JSON Schema for the source's `config`. A save whose `config` fails it is refused with 422 naming the field (`config.url`). |
| `gather()` / `fetch()` | The per-id protocol: list external ids, then read each. |

A protocol that carries every item in one response (a DCAT catalogue, a CSV
file, an OAI-PMH page) implements `IBatchSourceFetcher` instead and returns a
`HarvestBatch` from `gatherItems()`:

- `items`: external id → raw payload;
- `complete`: true when the whole source was read without a failed page;
- `checkpoint`: written to the source's `lastSyncToken` and passed back as `since`;
- `errors`: `{externalId, message}`; an item you refuse is still *seen*, so it
  is not tombstoned and keeps its last good data under status `fetch_error`.

Read the network only through the `HarvestHttpClient` you are handed: it
checks every redirect hop against private, loopback, link-local and metadata
addresses, sets timeouts, retries 5xx/429/timeouts with backoff (honouring
`Retry-After`) and caps a body at 50 MB.

`GET /api/sources/types` lists every registered type with its display name,
owning app and config schema, so your form and OpenRegister's sources page
read the same list.

## 3. Source fields

Write your sources through `OCA\OpenRegister\Service\Sync\SourceService`
(`create`, `update`, `delete`), never through the mapper. It validates and
refuses with a `SourceRefused` naming the field:

| Field | Meaning |
| --- | --- |
| `application` | Your app id. |
| `type` | Your fetcher's type. |
| `config` | Fetcher config, checked against `getConfigSchema()`. Secrets stay in `authConfig`. |
| `schedule` | Cron expression. With `runAs`, the source gets one flow (see below). |
| `runAs` | The account the harvest runs as. Required with `schedule`; must exist and be enabled. |
| `identityProperty` | Property on the target object carrying the external id, used to spot a local object that already claims it. |
| `protectedFields` | Properties the harvest never writes. |
| `provenance` | `{externalIdProperty, sourceProperty}`, set after mapping so a mapping cannot override them. |
| `conflictStrategy` | `manual`, `source-wins`, `local-wins`, `newest-wins` or `reject`. |
| `conflictRules` | Optional decision table picking a strategy per item; checked when the source is saved. |
| `deleteStrategy` | `flag` (default: tombstone only), `soft-delete`, `ignore`; `hard-delete` is refused for an app's source. |
| `maxItemsPerRun` | Default 1000, at most 10000; items past the cap make the run incomplete. |

A source with `schedule` and `runAs` gets one flow, keyed by the source's uuid:
`openregister.trigger-schedule` and `openregister.trigger-manual` into
`openregister.harvest-source`, into `end`. Saving the source again updates that
flow, `syncEnabled: false` disables it, deleting the source deletes it (its
sync records stay). `POST /api/sources/{id}/sync` then starts the manual
trigger and answers `202 {runId, flowId}`.

The `openregister.harvest-source` step takes a per-source lock, refuses
`source-disabled`, `fetcher-missing` and `mapping-missing` (run failed) and
`already-running` (run skipped), and emits the run summary as its one item.

## 4. The resolution API

A collision with a local change goes to the source's strategy; under `manual`
the record waits as a `conflict`.

- `GET /api/sources/{id}/sync-records?status=conflict` lists the latest record
  per external id. Each conflict carries `rawData` and `mappedData`: the item
  through the source's current mapping with protected fields removed and
  provenance set, exactly what `use-harvested` would write. A mapping that now
  fails gives `mappedData: null` and `mappingError`; the record stays queued.
- `POST /api/sources/{id}/sync-records/{recordId}/resolve` with `action`
  `keep-local` (→ `shadowed`), `use-harvested` or `merge` with
  `fields: {property: local|harvested}` (→ `imported`), or `discard`
  (→ `rejected`).
- `POST /api/sources/{id}/sync-records/resolve` with
  `resolutions: [{recordId, action, fields?}]`; each item reports its own
  status and one failure rolls back none of the others.

These routes are open to non-admins of the source's organisation: a non-admin
sees only records whose object they may update, and resolving an object the
caller may not update answers 403 and leaves the record a conflict. A
resolution writes under the caller's own rights, so the audit trail names the
person who decided, and appends `{resolvedBy, resolvedAt, action, fields,
objectUuid, bulk}` to the record's `resolutions`.
