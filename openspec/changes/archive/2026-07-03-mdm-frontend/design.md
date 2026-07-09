# Design: mdm-frontend

## Context

ADR-045 makes OpenRegister the owner of the generic MDM surface. The engine is
already merged into `development`:

- **`mdm-surface-api`** (program #1) — read APIs, all `#[NoAdminRequired]`,
  already routed in `appinfo/routes.php`:
  - `GET /api/objects/quality/{register}/{schema}/stats` → `QualityController::stats`
    → `QualityStatisticsService`, envelope
    `{ average, total, buckets: { good, fair, poor }, histogram: [10 ints] }`.
  - `GET /api/objects/quality/{register}/{schema}` → `QualityController::index`,
    envelope `{ items: [ { id, qualityScore, qualityStatus } ], total, limit, offset }`.
  - `GET /api/objects/duplicates/{register}/{schema}` → `DuplicateController::index`,
    envelope `{ items: [ { objectA, objectB, score, matchedOn: [] } ], total, limit, offset }`.
- **`mdm-survivorship-engine`** (program #2) — `SurvivorshipResolver` +
  `SurvivorshipRecomputeListener` materialise a golden record and an
  `attributeProvenance` map (which source won each attribute) onto
  survivorship-enabled objects on save.

The steward has APIs but **no view**. This change is the OpenRegister Vue
frontend that consumes them.

OR's frontend architecture (studied for exact placement):

- `src/manifest.json` — a flat `pages[]` array (`{ id, route, type, title,
  component, _note }`) plus a top-level `menu[]` **tree** (each entry
  `{ id, label, icon, route, section, order }`, groups carry a `children[]`
  array; e.g. the existing `DataGroup` with Registers/Schemas/… children).
  Adding a top-level surface = add `pages[]` entries + a `menu[]` group.
- `src/main.js` — VueRouter hash mode; every nav icon is a
  `vue-material-design-icons/*` import registered as a global component. New nav
  icons must be imported + registered here.
- `src/store/store.js` + `src/store/modules/*.js` — Pinia modules. The canonical
  read-only module to mirror is `reports.js`: Options-API `defineStore`,
  `@nextcloud/axios`, `generateUrl('/apps/openregister/api')`. A new
  `useQualityStore` is registered in `store.js`.
- `src/views/reports/` (`ReportsIndex.vue`, `ReportView.vue`) is the structural
  template for an index-plus-detail view; `AvgIndex.vue` is the template for a
  cross-register aggregate view. New views live under `src/views/quality/`.

## Goals / Non-Goals

**Goals:**
- Ship the four register/schema-scoped steward views (Data Quality dashboard,
  Duplicate Candidates read-only, Master-entity list with golden-record detail,
  Queue / sync-health) consuming only already-merged backends.
- Add them to `src/manifest.json` (pages + one grouped nav section), register
  their icons in `src/main.js`, and back them with one Pinia store module.
- Meet every ADR-004 / hydra frontend gate (modal isolation, NcSelect
  `inputLabel`, `loadState` not DOM, no dashboard-in-dashboard, gate-26 visual
  coverage) and ship visual + e2e coverage per new page.

**Non-Goals:**
- Merge **execution** UI (needs the reversible-merge backend follow-on).
- Trust-configuration editing UI.
- GDPR/AVG DSAR workflow (ADR-047, separate program).
- The pipelinq `mdm-consume-or-surface` migration (program #5).
- Any new backend behaviour or schema. This change is **frontend-only** (see the
  queue-health decision below).

## Decisions

### D1 — Queue / sync-health consumes OR's EXISTING webhook read APIs; no new endpoint here

OR already exposes webhook delivery telemetry, all `#[NoAdminRequired]` and
routed:

- `GET /api/webhooks/{id}/logs/stats` → `WebhooksController::logStats` →
  `WebhookLogMapper::getStatistics(id)`, returns `{ total, success, failed }` and
  the controller augments it with `pendingRetries`.
- `GET /api/webhooks/logs` (`webhooks#allLogs`) → paginated delivery logs with a
  `success` filter and a `total` count (fleet-wide or per `webhook_id`).
- `GET /api/webhooks` (`webhooks#index`) → the webhook list to iterate for
  per-webhook stats.

The queue-health view therefore consumes: `webhooks#index` (list) →
`webhooks#logStats` per webhook (delivered / failed / pendingRetries, the
"dead-letter"-equivalent being failed deliveries past their retry ceiling) +
`webhooks#allLogs?success=false` for the recent-failures table.

**Chosen: consume-existing (frontend-only).** This keeps `depends_on: []` and
zero backend surface, honouring the ADR-045 "no parallel queue subsystem"
constraint by reusing `WebhookService` / `WebhookDeliveryJob` / `HookRetryJob`
telemetry as-is.

- *Alternative A — chain `mdm-webhook-health-api`:* add a single fleet-wide
  aggregate endpoint (`GET /api/objects/mdm/webhook-health` returning summed
  delivered/failed/pending/dead-letter). Cleaner one-call view and cheaper on
  the wire (N per-webhook calls collapse to one), but it balloons this change
  into backend work and adds a dependency. Recorded as an **optional follow-on**,
  not a blocker. If the per-webhook fan-out proves too chatty at fleet scale,
  build it then and swap the store's data source — the view contract is written
  to tolerate either source shape.
- *Alternative B — scope queue-health out entirely:* defer to a later change.
  Rejected: ADR-045 lists sync/queue health as part of the surface and the data
  is already available, so shipping a read-only summary now is low-cost and
  completes the four-view surface.

**Recommendation: consume-existing now; note `mdm-webhook-health-api` as an
optional efficiency follow-on.** (See DEFERRED_QUESTIONS (a).)

### D2 — Master-entity list is a NEW view under `src/views/quality/`, not an extension of the object browser

The generic object browser is register/schema-agnostic and heavily used; bolting
survivorship-only columns (`qualityScore` / `qualityStatus` + golden-record
detail with `attributeProvenance`) onto it would couple a niche MDM concern to
the core browse path and risk regressions there. A dedicated MDM view keeps the
surface cohesive (all four MDM views share one selector + one store) and
isolable for gate-26 visual coverage. It reuses `useObjectStore` for the object
list read (ADR-022) — it does not re-implement object fetching. (DEFERRED
QUESTIONS (c).)

### D3 — Register/schema selector is a shared child component using `NcSelect` with `inputLabel`

All four views begin with the same `(register, schema)` picker. It is a single
shared component (`RegisterSchemaSelector.vue`) emitting the selected pair. Each
`NcSelect` carries an `inputLabel` prop (hydra-gate-nc-input-labels; WCAG 1.3.1 /
4.1.2) — no manual `<label>`. The selected register/schema is held in the
`quality` store so the four views stay in sync when the steward switches tabs.

### D4 — Charts via the nc-vue chart primitive, with a table fallback

The 10-bucket histogram renders through `CnChartWidget` / the apexcharts
re-export from `@conduction/nextcloud-vue` (per MEMORY: apexcharts comes from
nc-vue, never a direct dependency). The KPI cards use `CnStatsBlock` /
`CnStatWidget`-style primitives already used elsewhere in OR. If a chart
primitive is unavailable or the histogram must degrade, the same data renders as
a plain bucket table — the spec requires the histogram *data* to be presented,
not a specific widget. (DEFERRED QUESTIONS (d).)

### D5 — One Pinia store module, mirroring `reports.js`

`src/store/modules/quality.js` exports `useQualityStore` (Options-API
`defineStore` + `@nextcloud/axios` + `generateUrl`), holding the selected
register/schema and read actions: `fetchQualityStats`, `fetchLowQualityObjects`,
`fetchDuplicates`, `fetchMasterEntities` (via `useObjectStore`),
`fetchGoldenRecord`, and `fetchWebhookHealth`. No custom store base class — it
follows the existing OR store pattern (MEMORY: Options API + read-only, mirrors
`reports.js` which itself is a thin axios wrapper). Registered in
`src/store/store.js` alongside `useReportsStore`.

## Frontend gate compliance (ADR-004 + hydra gates)

- **Modal isolation (hydra-gate-modal-isolation):** the golden-record detail is
  a panel, not a modal, so no `NcModal` is introduced. If any confirmation
  dialog becomes necessary, it goes in its own file under `src/modals/` and is
  imported — never inline `<NcModal>` / `<NcDialog>` in a view.
- **NcSelect inputLabel (hydra-gate-nc-input-labels):** every `NcSelect`
  (register + schema pickers, webhook filter) carries an `inputLabel`.
- **loadState not DOM (hydra-gate-initial-state):** all data comes from the read
  APIs via the store; no `document.getElementById(...).dataset.*`. Any bootstrap
  config (e.g. default register) uses `loadState('openregister', ...)` from
  `@nextcloud/initial-state`, never a DOM data-attribute.
- **Admin router (hydra-gate-admin-router):** these are in-app steward views
  registered in `manifest.json` `pages[]` + the app router — they are **not**
  admin settings components, so no admin-settings component leaks into the
  router.
- **Dashboard antipattern (hydra-gate-dashboard-antipattern):** the Data Quality
  view is a `type: "custom"` page rendering a bespoke `QualityIndex` component
  (KPI cards + histogram + table), **not** a `CnDashboardPage`, and it is not
  registered as a widget on another dashboard page. No `CnDashboardPage`-in-
  `CnDashboardPage` nesting.
- **Visual coverage (hydra-gate-visual-coverage, gate-26):** every NEW page/view
  gets a visual-regression spec. The four index views + the golden-record detail
  each get a `@visual`-tagged Playwright spec; there is no `@visual exclude`
  (all four are user-facing surfaces with stable layout, so exclusion is not
  justified).
- **E2e coverage (hydra-gate-e2e-coverage, gate-19):** every ADDED spec Scenario
  is referenced by at least one Playwright e2e test file; UI assertions go
  through real clicks/typing per the "test through the UI" rule (store reads used
  only as assertions).

## Seed Data

**No new schemas, registers, or seed objects are introduced — this is a
frontend-only change.** Testing runs against the objects the already-merged
backends (#1 / #2) score and materialise:

- **Quality / duplicates:** any register+schema carrying `x-openregister-quality`
  / `x-openregister-dedup`. The pipelinq register (register `16`) is the
  canonical scored dataset; its `masterEntity` schema (`1207`) already has
  `qualityScore` / `qualityStatus` materialised on objects by change #1.
- **Master-entity list + golden record:** the survivorship-enabled `masterEntity`
  schema (`1207` in register `16`), whose objects carry the `attributeProvenance`
  map materialised by change #2's `SurvivorshipRecomputeListener`.
- **Queue / sync-health:** existing `oc_openregister_webhook*` rows — any
  configured webhook with delivery logs. No seeding required; if an environment
  has no webhooks, the view renders its empty state.

E2e/visual specs select register `16` + schema `1207` (or discover the first
scored schema at runtime) rather than hardcoding object UUIDs. Any placeholder
UUID used in fixtures is the nil UUID `00000000-0000-0000-0000-000000000000`.

No ADR-031 declarative section is required: this change adds **no** backend
behaviour. (Were Alternative A / `mdm-webhook-health-api` adopted, that endpoint
would be an imperative read API — like the existing quality API — not a
declarative schema extension, so ADR-031 would still not apply.)

## Risks / Trade-offs

- **[Per-webhook fan-out for queue-health is N+1 calls]** → Acceptable at
  current webhook counts; the store batches and caches per session, and the
  `mdm-webhook-health-api` follow-on collapses it to one call if it ever bites.
- **[Chart primitive availability / `@config.*` currency crash class]** → Table
  fallback (D4) guarantees the histogram data renders even if the chart widget
  is unavailable; the histogram is integer counts, not currency, so the known
  `@config.currency` `RangeError` blanking class does not apply.
- **[Duplicate/master views look empty on an unscored schema]** → The
  register/schema selector only offers schemas that declare quality/dedup/
  survivorship where feasible, and every view has an explicit empty state
  ("No scored objects for this schema") so an unscored selection is legible, not
  a blank panel.
- **[Golden-record `attributeProvenance` shape drift vs change #2]** → The detail
  panel reads the `attributeProvenance` map defensively (renders whatever
  provenance keys exist); the e2e spec asserts against the shape produced by the
  merged `SurvivorshipResolver`.

## Migration Plan

Additive frontend-only. Deploy = ship the built JS bundle; the four pages appear
under the new "Data quality" nav group. Rollback = revert the manifest/main.js/
store additions and the `src/views/quality/` directory; no data migration, no
backend change, nothing to un-seed. Cache-bust via an `info.xml` `<version>` bump
(immutable `/custom_apps/*.js` per MEMORY).

## Open Questions

See DEFERRED_QUESTIONS in the change hand-off: (a) queue-health endpoint
strategy, (b) nav group label, (c) master-entity as new view vs object-browser
extension, (d) chart library vs table-only histogram. Provisional decisions are
recorded above (D1 consume-existing, D3/D2 new view, D4 chart-with-fallback);
nav label provisionally "Data quality".
