# Tasks: Rapportage en BI Export

> **Status (planning):** Architectural reframe — instead of building a heavy "BI subsystem" (OData v4, ODBC bridge, dedicated PDF engine, scheduled-report tables), we treat **dashboards and reports as operator-defined schemas in a `reports` register** and compose existing primitives (`AggregationRunner`, `GraphQL`, `ExportService`, `CnDashboardPage` + nextcloud-vue widgets). 9 of 15 spec requirements already covered by existing primitives; 4 land in Phase 1; 2 land in Phase 2; 1 (OData) is intentionally out of scope. See `design.md` for the full architecture.

## Already covered by existing primitives

- [x] **General-purpose aggregation API.** `AggregationRunner::run` (`lib/Service/Aggregation/AggregationRunner.php`) supports `count / sum / avg / min / max / count_distinct + groupBy`; declared via the `x-openregister-aggregations` schema annotation; HTTP entry point `GET /api/objects/aggregations/{register}/{schema}/{name}`.

- [x] **Chart data API endpoints for frontend visualization.** Same path — `AggregationRunner` output is already shaped for chart consumption (`{count, sum, avg, min, max, groups: [...]}`). `MagicStatisticsHandler` provides register/schema-level chart data (`getStatistics`, `getActionChartData`) for the dashboard view.

- [x] **Cross-register reporting.** The `GraphQL` API (`lib/Service/GraphQL/`) auto-generates a schema spanning every register and supports cross-register queries with filters + pagination + RBAC + multi-tenancy.

- [x] **Date range filtering and period-over-period comparison.** GraphQL filter operators (`gte`, `lte`, `eq`, `in`) cover date ranges natively; period-over-period is two queries with shifted ranges.

- [x] **Custom calculated metrics.** Schema properties support the `computed` annotation (computed-fields spec) — operators define metrics as Twig expressions on the schema.

- [x] **Reports and exports MUST enforce RBAC permissions.** Every aggregation / GraphQL / export call routes through `MagicMapper::find` / `aggregate` which enforces RBAC via `MagicRbacHandler` + property-level via `PropertyRbacHandler`. A widget for which the caller has no read permission returns empty rather than 403.

- [x] **Report caching for performance.** `AggregationCache` (`lib/Service/Aggregation/AggregationCache.php`) provides per-aggregation TTL caching; eviction on object writes via `AggregationCacheInvalidationListener`.

- [x] **Multi-tenant reporting isolation.** All aggregation + GraphQL paths apply `MultiTenancyTrait::applyOrganisationFilter`; dashboards in tenant X see only X's data.

- [x] **API access for external BI tools beyond OData.** GraphQL serves this purpose. Power BI + Tableau + Looker + Metabase all have GraphQL connectors (Apollo for Power BI, community plugins for Tableau/Looker/Metabase). Operators who need OData specifically can run an OpenConnector mapping.

## Phase 1 — declarative dashboards (MVP)

- [ ] **Configurable report templates.** Ship a `report-bundle.json` configuration import (`lib/Resources/RapportageSchemas/report-bundle.json`) containing the `reports` register + `dashboard` schema with the documented widget shape (`{type, title, dataSource: {register, schema, aggregation}, options}`). Operators import once via `POST /api/configurations/import`, then create dashboards as standard objects.

- [ ] **Frontend dashboard renderer.** `src/views/reports/ReportView.vue` renders a single dashboard via `CnDashboardPage` + maps each widget's `type` to the matching `Cn*Widget` (`CnChartWidget` / `CnTableWidget` / `CnKpiGrid` / `CnSparklineWidget` / `CnTileWidget`), feeding it from the AggregationRunner output. `src/views/reports/ReportsIndex.vue` lists dashboards in the `reports` register; `src/store/modules/reports.js` Pinia store wraps the aggregation API + caches widget responses per session.

- [ ] **Widget annotation validator.** `lib/Service/Aggregation/WidgetAnnotationValidator.php` validates the `widgets[]` array shape at schema-save time (mirrors `LifecycleAnnotationValidator` pattern); surfaces shape errors via the standard schema-validation error envelope.

## Phase 2 — export + scheduling

- [ ] **PDF and ODS export formats.** Extend `ExportService::exportTo*` with PDF (via `Browsershot` headless-Chrome wrapper; falls back to "PDF unavailable" envelope when the dep is missing) + ODS (PhpSpreadsheet already supports this writer; trivial add). CSV + XLSX already shipped.

- [ ] **Scheduled report generation.** `lib/BackgroundJob/ReportRenderJob.php` daily TimedJob walks objects in the `reports` register with a `schedule` field (intervalSec format, mirroring notifications-v2 deferral), computes "is it time to fire?", calls `ReportRenderService::render(dashboard, format)`, and dispatches via the existing notification path or `FileService` based on the dashboard's `delivery` field. Each render writes an audit row tagged with a dedicated `rapportage-rendering` processing-activity (Phase 2 seeds this activity in the migration).

## Phase 3 — pre-built templates

- [ ] **WOO transparency reporting template.** `lib/Resources/RapportageSchemas/templates/woo.json` ships a dashboard pre-configured with the widgets a Woo (Wet open overheid) transparency report needs: published-objects-per-month, active-publication-categories breakdown, time-since-last-publication SLA tracker.

- [ ] **Audit report template.** `lib/Resources/RapportageSchemas/templates/audit-trail.json` ships the audit-trail summary dashboard composing the existing `AuditTrailMapper::getStatistics` / `getActionChartData` aggregations. The AVG verantwoording report (already shipped under avg-verwerkingsregister) is the template for this; this one widens the scope from per-processing-activity to per-register / per-schema audit summaries.

## Out of scope (architectural decision)

- [ ] ~~OData v4 endpoint for external BI tool integration~~ — intentionally deferred. GraphQL covers the BI-tool use case (Power BI + Tableau + Looker + Metabase all speak it). OData would be 4–6 weeks of parser + driver work duplicating GraphQL's surface. Operators who specifically need OData can run an OpenConnector mapping. Documented in `design.md` § "What we are NOT building".

## Test coverage strategy (per phase)

- **Phase 1**: integration test for the dashboard-renderer composition; browser test creating a dashboard object and asserting widgets render with live aggregation data.
- **Phase 2**: integration test for `ReportRenderService::renderTo{Pdf,Xlsx,Ods}` (each format yields a non-empty file with the expected MIME); integration test for `ReportRenderJob` (creates a scheduled dashboard, advances time past the schedule, asserts the job fires + the audit row is correctly attributed).
- **Phase 3**: import each template via `POST /api/configurations/import`; assert the dashboard renders without errors against a freshly-seeded register; smoke-test in browser.

## Architecture (planned decisions)

| Decision | Choice |
|---|---|
| Reports modeled as | Operator-defined schemas in a `reports` register (per architectural pointer; same pattern as AVG verwerker/consent/dpia bundle). |
| Dashboard structure | Single `dashboard` schema with nested `widgets` json array — atomic edits, no cross-table joins. |
| Widget data fetch | Frontend calls aggregation API per widget; AggregationCache makes repeats cheap. Cross-register widgets fall back to GraphQL. |
| Export format engine | PhpSpreadsheet (Excel/CSV/ODS) + Browsershot (PDF, optional dep). |
| Schedule cadence | `intervalSec` (mirrors notifications-v2 deferral) — no cron parser. |
| OData / ODBC | Out of scope; GraphQL is the BI-tool entry point. |
| Audit attribution | Scheduled renders are tagged with a `rapportage-rendering` processing-activity so AVG verantwoording shows the rendering history alongside other write events. |
