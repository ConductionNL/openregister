---
kind: code
depends_on: [api-as-a-versioned-surface]
---

# Proposal: schema-breaking-change-notice

## Summary

When an administrator acknowledges a breaking change to a schema, the people
who build on that schema hear about it: everyone whose account called the
schema's objects in the last 90 days, and everyone who chose to follow the
schema's changes. Before acknowledging, the administrator sees how many callers
will be affected. The notice names the schema, the new version, what broke and
where the changelog is, and can carry a short note from the administrator.
Clients that call without a person behind them see the change in a response
header on that schema's object routes for 30 days.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| opencatalogi | od-change-alert | Warn the people who build on a dataset when a change could break their apps, such as a changed table structure. | partial |

Row `od-change-alert` in opencatalogi's matrix, owned here because
`built.owner` is ConductionNL/openregister: a dataset in opencatalogi is an
Open Register schema, and the breaking-change gate is Open Register's.

Demand rows:

- featureRequest, https://github.com/ckan/ckan/discussions/9535

Competitor yes cells: none recorded in the packet.

## Why

Open Register decides when a schema change is breaking and records it, and
tells nobody who depends on it:

- `SchemaVersioningService::classify()` and `enforceGate()` classify an update
  and refuse a breaking one without `acknowledgeBreaking`
  (`lib/Service/Schema/SchemaVersioningService.php:90-121`), answered as 409 by
  `SchemasController` (`lib/Controller/SchemasController.php:1127-1150`).
- `recordChangelog()` writes the entry with the acknowledging actor
  (`SchemaVersioningService.php:156-187`), called once the update is applied
  (`SchemasController.php:1182-1188`), readable at `GET /api/schemas/{id}/changelog`
  (`appinfo/routes.php:1637`).
- The caller record exists: one row per principal, route, method and contract
  version, with counts and `last_seen` (`lib/Service/ApiCaller/ApiCallRecorder.php:125-175`,
  table `openregister_api_calls`, `lib/Migration/Version1Date20260916070000.php:95-103`),
  read by administrators at `GET /api/callers` (`appinfo/routes.php:1697`).
  Nothing reads it when a schema changes.
- The Deprecation and Sunset headers cover Open Register's own API versions
  (`lib/Middleware/ApiVersionMiddleware.php:217-235`), not a change to one
  schema's shape.

opencatalogi's matrix: "Missing half: nobody who builds on the data is told".

## What changes

- The 409 that stops an unacknowledged breaking change also reports
  `affectedCallers`: how many accounts called this schema's object routes in
  the last 90 days, and the ten busiest.
- An acknowledged breaking change queues a notice to those accounts and to the
  schema's followers, as a Nextcloud notification with a link to the changelog.
  The administrator may add `changeNotice`, a short note that the notice
  carries.
- Any user who may read a schema can follow its breaking changes, and stop
  following, at `/api/schemas/{id}/change-followers`.
- For 30 days after a breaking change, responses on that schema's object routes
  carry a header naming the new version, the moment and the changelog.
- The changelog entry records how many were told.

## Consumers

- opencatalogi (od-change-alert): a "Follow changes" action on a dataset page,
  calling the follow endpoint for a signed-in reader. That page is
  opencatalogi's.
- Every app whose integrators call Open Register's object API directly, for
  example the suppliers on a gemeente's dossiq or pipelinq registers.

## ADRs

- hydra ADR-005 (security): a follower must be able to read the schema; the
  caller list is shown only to administrators.
- hydra ADR-007 (i18n): the notice is rendered in the recipient's language.
- hydra ADR-069: the notice is a queued job, never inline in the schema save.
- hydra ADR-031: see the declarative-vs-imperative decision.
- openregister ADR-002 (organisation tenancy): only callers and followers who
  can read the schema are told.

## Impact

- Extends `schema-migration`.
- Depends on `api-as-a-versioned-surface` for the caller record requirement
  (REQ-AVS-003). The record is already built at this sha; the dependency is on
  that requirement being the one this change reads.
- Affected code: `SchemaVersioningService`, `BreakingSchemaChangeException`,
  `SchemasController::update()`, `ApiCallRecordMapper` (a finder by route
  prefix), a new `lib/Service/Schema/SchemaChangeNoticeService.php`, a
  `SchemaChangeNoticeJob`, a follower table and mapper, two routes,
  `lib/Notification/Notifier.php` (subject `schema_breaking_change`), a response
  listener for the header.
- Backwards compatible: the 409 body gains a key; the header is new.
- Size: M.

## Out of scope

- opencatalogi's public `/api/{catalogSlug}` callers. They are not in Open
  Register's caller record; opencatalogi records its own or adopts the recorder.
- Breaking changes applied through a configuration import. Only
  `SchemasController::update()` calls the versioning service today, so an
  app upgrade that changes a schema is not classified at all. That gap is its
  own change.
- Holding a breaking change for a notice period before it applies. The gate
  stays a single acknowledgement.
- Webhook owners (from `webhooks-for-owners`) as recipients; a
  later change can add them once owners exist.
