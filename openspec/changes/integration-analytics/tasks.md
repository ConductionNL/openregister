# Tasks: Integration — Analytics

## Backend

- [ ] `AnalyticsLink` entity + mapper + migration (schema/object → report id)
- [ ] `AnalyticsReportService` — fetch report config + latest data
- [ ] `AnalyticsController`
- [ ] `AnalyticsProvider` — id='analytics', label='Analytics', icon='ChartBar', group='workflow', requiredApp='analytics', storage='link-table'
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnAnalyticsTab.vue` — linked reports with inline chart previews, link-existing, unlink
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnAnalyticsCard.vue`:
  - `user-dashboard`: top KPI from report
  - `app-dashboard`: scoped, full chart
  - `detail-page`: full chart with refresh button
  - `single-entity`: report-title chip + sparkline
- [ ] Dashboard 5-min auto-refresh, on-demand elsewhere
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/analytics.js` — register with `referenceType: 'analytics'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: link an Analytics report, verify chart embeds in tab and widget
- [ ] Refresh test: dashboard chart updates within 5 min after data change in Analytics
- [ ] Hide test; reference-property test
