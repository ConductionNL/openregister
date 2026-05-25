---
status: draft
retrofit: true
---

# Retrofit: Reverse-spec controller bundle — Controllers (chunk 2)

## Why

A coverage scan of OpenRegister's controller layer surfaced 148 uncovered
public methods across 28 controller files (`/tmp/or-scan/bw2-ctrl-2.json`)
that carried no `@spec` tag, violating ADR-003's "every class and public
method MUST have an `@spec` PHPDoc tag" rule. The underlying services are
implemented; what was missing is the *controller* HTTP-surface contract
(route shape, status codes, parameter handling, error envelopes,
degradation behaviour).

This ghost change reverse-specs the observed controller behaviour and tags
every one of the 148 methods. Per ADR-003's two-tool convention each method
ends either:

- **reverse-spec'd** — load-bearing endpoints get an `@spec` tag pointing at
  the capability that owns their contract (extended by route family, or an
  existing REQ annotated), or
- **`@spec exclude <reason>`** — boilerplate (constructors, SPA-mount stubs,
  thin passthroughs, error-mapping helpers, private resolvers) is excluded
  with a required reason.

The change biases strongly toward **annotation-only**: most of the 148
methods are already owned by a live capability spec or an in-flight retrofit
change (the `2026-05-24-b-ctrl-*` series, `pluggable-integration-registry`,
`urn-resource-addressing`, `object-interactions`, `workflow-operations`,
`data-import-export`, `referential-integrity`, `production-observability`,
`contacts-actions`, `add-time-bucket-aggregation`). Only the genuinely
uncovered HTTP surfaces get new REQs (5 new REQs total, within the ≤6
budget). This is a documentation/annotation change only — no runtime
behaviour is modified.

## What changes

### Counts

| Outcome | Methods |
|---|---|
| Reverse-spec'd (annotated to an `@spec` tag) | 146 |
| `@spec exclude` (boilerplate) | 2 |
| **Total** | **148** |
| New REQs added | 5 |

The 2 excludes are the two SPA-mount template stubs
(`AgentsController::page`, `FilesController::page`) — each returns the Vue
`index` template with client-side routing and carries no HTTP contract beyond
the shell. The batch's 148 are exactly the uncovered **public** methods;
constructors and the per-controller `validateObject` / `mapException` /
`unavailable` / `nullableString` / `nullableInt` / `dropdown` /
`currentUserIsAdmin` / `describe` private helpers are not in the batch
(they are governed by the consolidated REQs / class-level `@spec`) and are
left untouched.

### Per-capability deltas

#### 1. `generic-integrations` — NEW REQ (one shared REQ, uniform CRUD)

The Tier-2 integration "leaf" link controllers
(`ActivityLinksController`, `BookmarkLinksController`,
`CospendLinksController`, `DeckLinksController`, `FlowLinksController`,
`FormLinksController`, `PhotoLinksController`, `TimeTrackerLinksController`,
`ShareLinksController`) and `EmailsController` share one uniform
object-scoped link-CRUD contract:
`index` (list), `link`/`create`/`createNew`/`createAndLink` (link-existing
and create-and-link), `destroy` (unlink), plus a picker-source discovery
surface (`available` / `boards` / `stacks` / `operations` / `types` /
`actors`), with a uniform `501 APP_NOT_AVAILABLE` graceful-degradation
envelope when the backing Nextcloud app is absent. Per the "one shared REQ
for uniform CRUD" guardrail this is captured as **one** consolidated REQ, not
one per controller. → 1 new REQ.

#### 2. `data-import-export` — NEW REQ (config management + Git-remote sync)

`ConfigurationController` and `ConfigurationsController` are the
configuration-management + Git-remote-sync surface: CRUD over configuration
entities, plus version-check / preview / detail-enrichment against a remote,
and GitHub/GitLab repository + file discovery for config import. The existing
`data-import-export` "Configuration import/export MUST support full register
portability" REQ covers the *portability file format* but **not** the
configuration-entity CRUD or the Git-remote-sync HTTP surface. The
`export`/`import` methods on both controllers are annotated to the existing
portability REQ; the CRUD + Git-sync surface gets one new REQ. → 1 new REQ.

#### 3. `openapi-generation` — NEW REQ (schema authoring sub-resources + meta-entity operational endpoints)

`SchemasController` schema-authoring sub-resources (`download`, `upload`,
`uploadUpdate`, `related`, `explore`, `updateFromExploration`) and the
registry-meta-entity *operational* endpoints that the shared resource-CRUD
REQ (registry-views task-1) explicitly defers — `EndpointsController::test`
and `MappingsController::test` (dry-run validation), plus the
`RegistersController` register→schema and register→objects sub-resource
lookups (`schemas`, `objects`) — get one new REQ here. → 1 new REQ.

#### 4. `oas-generation` — NEW REQ (publish / depublish HTTP surface)

`RegistersController` (`publish`, `depublish`, `publishToGitHub`) and
`SchemasController` (`publish`, `depublish`) expose the register/schema
publication lifecycle and OAS-to-GitHub publishing surface. `oas-generation`
owns OAS document generation but has no REQ for the publish/depublish
controller verbs. → 1 new REQ.

#### 5. `production-observability` — NEW REQ (per-entity statistics + endpoint delivery logs)

`RegistersController::stats`, `SchemasController::stats`, and the
`EndpointsController` delivery-log surface (`logs`, `logStats`, `allLogs`)
are operational/observability read endpoints. `production-observability`
covers the Prometheus + health endpoints but has no REQ for per-entity
statistics or the custom-endpoint delivery-log API. → 1 new REQ.

### Annotate-only (no new REQ) — referenced existing specs

- **openapi-generation** (registry-views task-1) — meta-entity resource CRUD
  (`index`/`show`/`create`/`update`/`patch`/`destroy`) for
  `RegistersController`, `SchemasController`, `EndpointsController`,
  `MappingsController`. `ConfigurationsController` CRUD is annotated to the
  new `data-import-export` config-management REQ instead (it is a config
  entity, not in the task-1 meta-entity set).
- **faceting-configuration** (registry-views task-2/3) —
  `ViewsController` (`index`/`show`/`create`/`update`/`patch`/`destroy`).
- **urn-resource-addressing** (registry-views task-7) —
  `UrnController` (`resolve`/`lookup`/`bulk`).
- **data-import-export** (existing REQs) — `RegistersController`
  (`import`/`export`/`importTemplate`/`rollbackImport`).
- **pluggable-integration-registry** (task-18/19) —
  `IntegrationsController` (`index`/`show`) and
  `ObjectIntegrationsController` (`index`/`show`/`create`/`update`/`destroy`)
  — class already annotated; method-level tags added.
- **object-interactions** ("File Attachments on Objects" / "Tags for Object
  Categorization") — `FilesController`
  (`show`/`save`/`createMultipart`/`update`/`depublish`/`downloadById`/`batch`/`updateLabels`).
- **object CRUD** (annotate-openregister) — `ObjectsController::postPatch`
  (multipart PATCH workaround).
- **referential-integrity** — `ObjectsController::canDelete` (pre-flight
  deletion analysis; the REQ scenarios already reference `canDelete()` and
  `DeletionAnalysis`).
- **add-time-bucket-aggregation** (task 2.1) —
  `AggregationController::timeseries`.
- **contacts-actions** (task-1) — `ContactsController::createNew`.
- **workflow-operations** ("Scheduled Workflow Triggers") —
  `ScheduledWorkflowController` (`index`/`show`/`create`/`update`/`destroy`).
- **production-observability** ("Prometheus Metrics Endpoint" / "Health Check
  Endpoint") — `MetricsController::index`, `HealthController::index`.
- **chat-ai** (existing) — `AgentsController` is covered at class level; its
  uncovered `page` is an SPA-mount stub (excluded).

## Impact

- Specs extended (new REQs): `generic-integrations`, `data-import-export`,
  `openapi-generation`, `oas-generation`, `production-observability`.
- Specs referenced (annotation targets, no delta): `faceting-configuration`,
  `urn-resource-addressing`, `pluggable-integration-registry`,
  `object-interactions`, `referential-integrity`, `add-time-bucket-aggregation`,
  `contacts-actions`, `workflow-operations`.
- Controllers annotated: all 28 in the batch.
- No code behaviour change; docblock `@spec` / `@spec exclude` annotations only.

## Security / authz notes (surfaced, not fixed here)

These were observed during the reverse-spec and are recorded as Notes for a
follow-up; this change does not modify behaviour:

- **`ScheduledWorkflowController`** — all five CRUD verbs are
  `@NoAdminRequired`, but the `workflow-operations` "Scheduled Workflow
  Triggers" REQ scenarios assume an authenticated **admin**. A non-admin can
  currently create/update/delete scheduled workflows that run server-side
  TimedJobs. The controller also calls `ScheduledWorkflowMapper` directly,
  violating ADR-003's Controller→Service→Mapper rule (no service-layer RBAC
  gate between the HTTP surface and the mapper).
- **`ObjectsController::postPatch`** — annotated `@PublicPage` +
  `@NoAdminRequired`, exposing an object create/update (with file upload) path
  to unauthenticated callers. RBAC is enforced downstream in `ObjectService`,
  but the public-page annotation widens the attack surface relative to the
  PUT/PATCH siblings.
- **`UrnController::bulk`** — the 1000-URN cap is the only DoS guard on an
  endpoint reachable by every authenticated user (~4 DB round-trips per URN);
  the in-code comment documents this. No per-user rate limit upstream.
- **`RegistersController::rollbackImport`** — wipes every object created by an
  import job; the in-code guard requires the caller to be the original
  importer or an admin, but the audit lookup does not filter by organisation
  (cross-tenant exposure if the importer-UID check is bypassed).
