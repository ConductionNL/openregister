# Tasks: Integration — Analytics

## Backend

- [~] `AnalyticsLink` entity + mapper + migration (schema/object → report id) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `AnalyticsReportService` — fetch report config + latest data — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `AnalyticsController` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `AnalyticsProvider` — id='analytics', label='Analytics', icon='ChartBar', group='workflow', requiredApp='analytics', storage='link-table'
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnAnalyticsTab.vue` — linked reports with inline chart previews, link-existing, unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnAnalyticsCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: top KPI from report
  - `app-dashboard`: scoped, full chart
  - `detail-page`: full chart with refresh button
  - `single-entity`: report-title chip + sparkline
- [~] Dashboard 5-min auto-refresh, on-demand elsewhere — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/analytics.js` — register with `referenceType: 'analytics'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: link an Analytics report, verify chart embeds in tab and widget — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Refresh test: dashboard chart updates within 5 min after data change in Analytics — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
