# Design: Rapportage en BI Export

## Architectural premise

OpenRegister already ships most of the primitives a reporting / BI
layer needs. What's missing is the **composition surface** — a way for
operators to declare dashboards and reports as first-class objects,
and a thin frontend that renders them via the existing nextcloud-vue
widgets.

The reframe: rather than building a heavy "BI subsystem" (OData v4
parser + ODBC driver + dedicated PDF engine + scheduled-report tables),
we treat **dashboards and reports as operator-defined schemas** —
exactly the architectural pattern AVG uses for verwerker / consent /
DPIA. Operators get the standard schema/object UI for free; can RBAC
their dashboards; can extend them with custom properties; can import
pre-built templates (WOO, jaarverslag, verantwoording).

## What we're building on

| Primitive | What it does | Where |
|---|---|---|
| `AggregationRunner` | `count / sum / avg / min / max / count_distinct + groupBy`, declared via `x-openregister-aggregations`, cached | `lib/Service/Aggregation/` |
| `MagicStatisticsHandler` | register/schema chart data + grouped statistics | `lib/Db/MagicMapper/` |
| `GraphQLService` | auto-generated schema per register, filtering, pagination, SSE subscriptions, RBAC + multi-tenancy | `lib/Service/GraphQL/` |
| `ExportService` | Excel + CSV with FLS-aware columns + relation-name resolution | `lib/Service/ExportService.php` |
| `AggregationCache` | per-aggregation TTL caching | `lib/Service/Aggregation/AggregationCache.php` |
| `ConfigurationService` | bundle import (registers + schemas + objects) | `lib/Service/ConfigurationService.php` |
| `CnDashboardPage` | grid layout shell with widget slots | `@conduction/nextcloud-vue` |
| `CnChartWidget` / `CnTableWidget` / `CnKpiGrid` / `CnStatsBlock` / `CnSparklineWidget` | render-side widgets | `@conduction/nextcloud-vue` |

## Reframe: what "rapportage" means here

A **dashboard** is an operator-defined object in a `reports` register.
It carries:

- `titel`, `beschrijving`
- `layout` (grid layout for `CnDashboardPage`)
- `widgets[]` — each widget object describes:
  - `type` (`kpi` / `chart` / `table` / `sparkline` / `tile` / …)
  - `title`
  - `dataSource: {register, schema, aggregation}` — references an
    `x-openregister-aggregations` declaration on a target schema
  - `options` — widget-specific config (chart kind, axis labels, etc.)

A **report** is the same shape with a `format` field (`pdf`, `xlsx`,
`ods`) and an optional `schedule` (cron-ish) — when scheduled, the
`ReportRenderJob` background job renders the dashboard to the chosen
format and either emails it (existing notification path) or drops it
into Files (existing FileService).

## Why dashboards-as-schemas

1. **No new tables.** The dashboard model is just two schemas
   (`dashboard` + `widget` if we go nested, or one schema with
   `widgets` as a json array — see Decision below) in a `reports`
   register.
2. **Standard CRUD UI.** Operators manage dashboards through the
   normal object UI — no separate "dashboard builder" surface.
3. **RBAC + multi-tenancy for free.** A "finance department only"
   dashboard is just `authorization: read: [{group: finance}]` on the
   schema, same as any other restricted object type.
4. **Importable templates.** WOO transparency / jaarverslag /
   verantwoording dashboards ship as configuration JSON bundles —
   operators import once, customise, then the dashboard appears in
   their `reports` register.
5. **Extensibility.** Dashboard schemas can carry custom properties
   the standard ones don't (e.g. compliance-team-specific fields)
   without requiring a backend change.

## Phased delivery

### Phase 1 — declarative dashboards (MVP)

- `lib/Resources/RapportageSchemas/report-bundle.json` — `reports`
  register + `dashboard` schema (with nested `widgets` json array).
- `src/views/reports/ReportsIndex.vue` — list view of dashboards in
  the `reports` register.
- `src/views/reports/ReportView.vue` — renders one dashboard via
  `CnDashboardPage` + maps each `widget.type` to the corresponding
  `Cn*Widget` component, fed from `AggregationRunner` output.
- `src/store/modules/reports.js` — Pinia store wrapping the
  aggregation API + a `widgetData` cache keyed on
  `(register, schema, aggregation)`.

### Phase 2 — export + scheduling

- Extend `ExportService` with PDF (via `Browsershot` / wkhtmltopdf
  fallback) + ODS (PhpSpreadsheet already supports it).
- New `lib/BackgroundJob/ReportRenderJob.php` — daily TimedJob that
  walks objects in the `reports` register with a `schedule` field,
  computes "is it time to fire?", renders the dashboard to the chosen
  format, and dispatches via existing notification / FileService.
- New `lib/Service/ReportRenderService.php` — composes the rendering
  pipeline (load dashboard → resolve aggregations → format output).

### Phase 3 — templates

- `lib/Resources/RapportageSchemas/templates/woo.json` — pre-built
  WOO transparency dashboard.
- `lib/Resources/RapportageSchemas/templates/jaarverslag.json` —
  annual report dashboard.
- `lib/Resources/RapportageSchemas/templates/audit-trail.json` —
  audit-trail summary dashboard composing the existing
  AuditTrailMapper aggregations.

## What we are NOT building

| Originally proposed | Reason for deferral |
|---|---|
| **OData v4 endpoint + parser** | GraphQL covers the BI-tool use case. Power BI + Tableau + Looker + Metabase all have GraphQL connectors (Apollo or community plugins). OData would be 4–6 weeks of parser + driver work duplicating what GraphQL already does. Operators who insist on OData can run an OpenConnector mapping. |
| **ODBC bridge** | Same reasoning. ODBC drivers for Postgres + GraphQL exist and operators can wire them externally. |
| **Dedicated `reports` / `report_runs` tables** | The `reports` register handles the catalog; audit-trail handles the run history (each `ReportRenderJob` write is just an audit row attributed to a `rapportage` processing-activity). |
| **Server-side widget data caching beyond AggregationCache** | The existing AggregationCache already handles this. Frontend memoises per-(register, schema, aggregation) for a session. |

## Decision matrix

| Decision | Choice | Rationale |
|---|---|---|
| Dashboard model | Single `dashboard` schema with nested `widgets` json array | Avoids cross-table joins; operator edits the whole dashboard atomically; widget add/remove is a single PATCH. |
| Widget data fetch | Frontend calls `/api/objects/aggregations/{register}/{schema}/{name}` per widget | Each widget gets its own RBAC-scoped query; no fan-out server-side; AggregationCache makes repeated calls cheap. |
| Cross-register widgets | GraphQL queries (the dashboard's `dataSource` accepts a `graphql` mode) | The aggregation API is per-(register, schema); for cross-register a GraphQL query is the right shape. |
| Export format engine | PhpSpreadsheet (Excel + ODS already, CSV via existing path) + Browsershot (PDF) | PhpSpreadsheet is already a dep; Browsershot wraps headless Chrome and is the standard NC pattern for PDF. |
| Report scheduling cadence | `intervalSec` (mirrors notifications-v2 deferral) — not cron | Notifications-v2 already deferred cron parsing; same call here. |
| Audit attribution | Each scheduled-render writes an audit row tagged with a `rapportage-rendering` processing-activity (created by Phase 2 migration) | Closes the loop — AVG verantwoording shows "this register was rendered N times for the WOO dashboard." |

## Risks + mitigations

| Risk | Mitigation |
|---|---|
| Widget config drift — schema property `widgets` is a free-form json that operators could fill with garbage | Validate widget shape at save time via `WidgetAnnotationValidator` (mirrors `LifecycleAnnotationValidator` pattern); surface errors in the standard schema-validation error envelope. |
| Widget data fetch waterfalls (10 widgets = 10 round-trips) | Frontend can batch via the existing GraphQL endpoint when widgets share register/schema; AggregationCache handles repeats. |
| PDF rendering pulls a heavy headless-Chrome dep | Make Browsershot optional; fall back to "PDF rendering not available — install Browsershot to enable" when missing. CSV/Excel/ODS are always available. |
| Operator-defined widgets bypass RBAC | They can't — every aggregation request goes through `MagicMapper::find` / `aggregate` which enforces RBAC + multi-tenancy. A widget that the caller can't read returns empty. |

## Test coverage strategy

- **Phase 1:** integration tests for `ReportsService::renderDashboard` (composes aggregation results onto a widget config envelope); browser test that creates a dashboard object and confirms the widgets render with live data.
- **Phase 2:** integration test for `ReportRenderService::renderToPdf/Xlsx/Ods` (renders a fixture dashboard to each format, verifies the file is non-empty + has the expected MIME); integration test for `ReportRenderJob` (creates a scheduled dashboard, advances time past the schedule, asserts the job fires).
- **Phase 3:** import each template via `POST /api/configurations/import` and assert the produced dashboard renders without errors against a freshly-seeded register.

## Open questions

- **Dashboard sharing surface.** Right now dashboards are operator-only (admin-required). Eventually we might want "share this dashboard with the public-relations group" — that's just an authorization rule on the dashboard object, but we'll need a UI control to set it. Out of scope for Phase 1.
- **Drill-down navigation.** A KPI widget showing "234 zaken" should be clickable → list view of those 234 zaken. Out of scope for Phase 1; widget configs already include enough metadata to build this in Phase 2.
- **Real-time refresh.** GraphQL subscriptions exist; widgets could subscribe to live updates. Out of scope for Phase 1; would land alongside the realtime-updates SSE work.
