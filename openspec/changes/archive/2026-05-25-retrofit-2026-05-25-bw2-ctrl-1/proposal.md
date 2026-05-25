---
status: draft
retrofit: true
---

# Retrofit: Reverse-spec controller bundle — Controllers (chunk 1)

## Why

A coverage scan of OpenRegister's controller layer (`lib/Controller/*.php`)
found 148 public/protected methods across 27 controller files missing the
ADR-003 `@spec` traceability tag. The backing services are implemented; what
was missing is the *controller* contract (route shape, status codes, parameter
handling, error envelopes). This ghost change reverse-specs the observed
controller behavior and annotates every method, biasing strongly toward
**extending** existing capabilities and toward **annotation-only** where the
behavior is already owned by a live spec or an archived retrofit change.

This is a documentation/annotation change only. No runtime behavior is modified.

## Counts

| Disposition | Methods |
|---|---|
| Reverse-spec'd (tagged to a REQ) | 115 |
| `@spec exclude` (boilerplate / SPA-mount stub / private helper / debug) | 33 |
| **Total** | **148** |

Of the 115 reverse-spec'd, 96 annotate to existing REQs (registry-CRUD,
calendar, chat-ai, linked-entity, manifest, notification, webhook, workflow) and
19 are net-new coverage under the 3 ADDED REQs in this change. The 33 excludes
are the 21 UiController SPA-mount stubs, 8 private link-controller helpers
(`validateObject`/`mapException` ×… counted once-per-touched-file), the 6
ChatStream SSE-framing helpers, the file-settings cURL helpers, and the
SettingsController DI accessors + debug endpoints.

New REQs added: **3** (well within the ≤6 budget). The dominant cost-saver is
the **one shared REQ** for the eight near-identical object-scoped integration
link controllers — per the "uniform CRUD across many controllers → ONE shared
REQ" guardrail, that single REQ replaces what would otherwise be eight
near-duplicate contracts.

## What changes

### 1. Object-scoped integration link controllers → extend **generic-integrations** (ONE shared REQ)

`AnalyticsLinksController`, `CollectiveLinksController`, `MapLinksController`,
`OpenProjectLinksController`, `PollLinksController`, `TalkLinksController`,
`XwikiLinksController`, and `EmailLinksController` are eight near-identical
Tier-2 REST controllers. Each exposes the same object-scoped link surface
(`GET` list / `POST` link-existing / `POST` create-and-link / `DELETE` unlink)
mounted under `/api/objects/{register}/{schema}/{id}/{provider}`, plus
provider picker-source endpoints under `/api/integrations/{provider}/...`. They
all share the same `501 APP_NOT_AVAILABLE` availability gate, the same
`{results,total}` envelope, the same `validateObject()` 404 path, and the same
`mapException()` HTTP-code mapping. Their provider/frontend behavior is already
owned by the `integration-*` changes (`integration-collectives`,
`integration-openproject`, `integration-xwiki`, `integration-polls`,
`integration-maps`, `integration-talk`, `integration-analytics`,
`integration-email`); what none of those captured is the **shared HTTP REST
contract**. ONE ADDED REQ in `generic-integrations` documents that contract;
all eight controllers' public methods annotate to it. Per-controller
`validateObject()` / `mapException()` are private helpers → `@spec exclude`.

`CalendarEventsController`'s uncovered methods (`index`/`link`/`unlink`/
`listCalendars`/`listCalendarEvents`) are **annotation-only** to the existing
`retrofit-2026-05-24-calendar-integration/tasks.md#task-1` (REST link/unlink
flow) — that change already owns the calendar link REST surface; no new REQ.

### 2. File text extraction + indexing HTTP surface → extend **search-index** (ONE new REQ)

`FileTextController` (extract / bulk-extract / chunk-index / stats / anonymize),
`FileSearchController::semanticSearch` + `hybridSearch` (vector/hybrid file
search), `FileSidebarController` (`getObjectsForFile` / `getExtractionStatus`),
and `FileSettingsController` (file-index admin: settings, collection-fields,
warmup, index/reindex, index/extraction stats, and the Dolphin / Presidio /
OpenAnonymiser connection tests) form the file text-extraction-and-indexing
pipeline's HTTP surface. The `search-index` spec covers backend routing and
collection mirroring but has no REQ for this controller surface. ONE ADDED REQ
documents it; all the routed methods annotate to it. Private helpers
(`performHealthCheck`, `fetchPresidioCapabilities`) → `@spec exclude`.
`FileSearchController::keywordSearch` is already annotated upstream and is not
in this batch.

### 3. Search-trail analytics + audit API → extend **zoeken-filteren** (ONE new REQ)

`SearchTrailController` exposes an 11-endpoint analytics/audit surface over the
search-trail log: list/show, aggregate statistics, popular terms, activity
time-series, per-register/schema stats, user-agent stats, CSV/JSON export, and
cleanup/single-delete/clear-all retention operations. The class currently
points at `zoeken-filteren#REQ-014` (view-based search composition), which is
the wrong contract — that REQ is about composing searches, not auditing them.
ONE ADDED REQ in `zoeken-filteren` documents the search-trail analytics/audit
API; the uncovered public methods annotate to it. `extractRequestParameters`,
`paginate`, and `arrayToCsv` are private helpers → `@spec exclude`.

### 4. Annotation-only against existing / archived REQs (no new REQ)

- **UiController** — 21 history-mode SPA-mount route stubs
  (`registers`/`schemas`/`objects`/`tables`/`chat`/`reports`/`auditTrail`/...)
  are trivial `return $this->makeSpaResponse();` passthroughs whose contract is
  already owned by `no-code-app-builder` (the consolidated SPA-mount REQ in
  `retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1`). Per the precedent and the
  ADR-003 thin-passthrough rule they are `@spec exclude` (reason cites the
  owning REQ); the load-bearing `makeSpaResponse()`/`integrationsView()`/`avg()`
  are already annotated upstream and are not in this batch.
- **ApplicationsController** / **ConsumersController** / **SourcesController** —
  `index`/`show`/`create`/`update`/`patch`/`destroy` annotate to the existing
  shared registry-resource-CRUD REQ
  (`retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1`); the classes
  already point there. `ApplicationsController::page` is an SPA-mount stub →
  exclude. Pagination/param private helpers (`extractLimit`/`extractOffset`/
  `extractPage`/`getIntParam`) → exclude.
- **ChatHealthController::health** → existing
  `ai-chat-companion-orchestrator/.../chat-ai/spec.md#health-probe-endpoint-get-apichathealth`.
- **ChatStreamController::stream** → existing
  `ai-chat-companion-orchestrator/.../chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream`;
  the SSE-framing helpers (`emitSseEvent`/`emitSseHeaders`/`emitAndExit`/
  `forwardWithHeartbeat`/`clearOutputBuffers`/`now`) → exclude.
- **LinkedEntityController** `addRegisterLink`/`addSchemaLink` → existing
  generic ad-hoc linking REQ (`retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-6`).
- **ManifestController::index** → existing `manifest-user-context/tasks.md`;
  `loadBundledManifest` private → exclude.
- **NotificationSubscriptionsController** `index`/`create`/`destroy` → existing
  `notificatie-engine/tasks.md`; `resolveUserId`/`coerceNullableInt` private →
  exclude.
- **WebhooksController** `show`/`logStats` → existing registry-views REQs
  (`#task-4` CRUD / `#task-5` delivery-log listing).
- **WorkflowEngineController** `show`/`update`/`destroy`/`testHook` → existing
  `workflow-engine-abstraction` engine-registration/CRUD REQ
  (`retrofit-2026-04-30-annotate-openregister/tasks.md#task-91`).
- **GraphQLController** `render` is the inline `Response` subclass's framework
  `render()` override, not a route handler → exclude.
- **SettingsController** `getObjectService`/`getConfigurationService` are DI
  service accessors (not routed) → exclude; `testSchemaMapping`/
  `debugTypeFiltering` are debug/test scaffolding endpoints → exclude (see
  Notes).

## New requirements summary (target ≤ 6)

| Capability | New REQs | Notes |
|---|---|---|
| generic-integrations | 1 | shared object-scoped integration link REST contract (8 controllers) |
| search-index | 1 | file text extraction + indexing HTTP surface |
| zoeken-filteren | 1 | search-trail analytics + audit API |
| **Total** | **3** | within budget |

## Impact

- Specs extended: `generic-integrations`, `search-index`, `zoeken-filteren`.
- Specs/changes referenced (annotation targets, no delta): `no-code-app-builder`,
  registry-views (`#task-1`/`#task-4`/`#task-5`/`#task-6`), `calendar-integration`
  (retrofit), `chat-ai` (orchestrator), `manifest-user-context`,
  `notificatie-engine`, `workflow-engine-abstraction`.
- 27 controllers touched; docblock `@spec` annotations only.
- No code behavior change.

## Notes (security / authz observations — not fixed here)

- **Debug/test endpoints are routed in production.** `SettingsController::
  debugTypeFiltering()` ("Debug endpoint for type filtering issue") and
  `::testSchemaMapping()` dump organisation/object data and run sample SOLR
  indexing. They are reachable HTTP routes. Excluded as scaffolding here, but
  routed debug endpoints that echo object data are an information-disclosure
  surface worth gating behind admin-only / removing.
- **Link-controller authz is session-user-scoped, no admin gate by design.**
  The integration link controllers rely on `@NoAdminRequired` + the backing
  service's user-scoping (e.g. NC Analytics/Collectives are user-owned). This
  is intentional per their docblocks, but the shared REQ documents it so any
  future provider that is NOT user-scoped must add its own gate rather than
  inherit this assumption.
