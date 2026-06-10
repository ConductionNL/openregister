# Tasks: Integration — Analytics

## Backend

- [x] `AnalyticsLink` entity + mapper + migration (schema/object → report id) — `lib/Db/AnalyticsLink.php`, `lib/Db/AnalyticsLinkMapper.php`, `lib/Migration/Version1Date20260525180000.php` (table `openregister_analytics_links`)
- [x] `AnalyticsReportService` — fetch report config + latest data — `lib/Service/AnalyticsLinkService.php` (link CRUD + cached report title/type/kpi + `available` picker)
- [x] `AnalyticsController` — `lib/Controller/AnalyticsLinksController.php` (index/link/createAndLink/destroy/available)
- [x] `AnalyticsProvider` — id='analytics', label='Analytics', icon='ChartBar', group='workflow', requiredApp='analytics', storage='link-table' — `lib/Service/Integration/Providers/AnalyticsProvider.php`
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (factory + `IntegrationRegistry::TAG`), `appinfo/routes.php` (`analyticsLinks#*`), `tests/Unit/Service/AnalyticsLinkServiceTest.php` + `LeafProvidersMetadataTest`

## Frontend — Tab

- [~] `CnAnalyticsTab.vue` — linked reports with inline chart previews, link-existing, unlink → cross-repo `@conduction/nextcloud-vue` (per design.md cross-repo note)
- [~] Barrel + tests → cross-repo

## Frontend — Widget

- [~] `CnAnalyticsCard.vue` (4 surfaces) → cross-repo `@conduction/nextcloud-vue`
- [~] Dashboard 5-min auto-refresh, on-demand elsewhere → cross-repo widget logic
- [~] Barrel + surface tests → cross-repo

## Registration

- [~] `src/integrations/builtin/analytics.js` — referenceType='analytics' → cross-repo `@conduction/nextcloud-vue` (`registerBuiltinIntegrations()`)

## Quality

- [x] Parity gate; nl+en; strict; ESLint — l10n includes `Analytics` label (en + nl `Analyses`) backing `AnalyticsProvider::getLabel()`

## Acceptance verification

- [~] E2E: link an Analytics report, verify chart embeds in tab and widget → cross-repo UI e2e; backend covered by `AnalyticsLinkServiceTest`
- [~] Refresh test: dashboard chart updates within 5 min after data change in Analytics → cross-repo widget test
- [~] Hide test; reference-property test → cross-repo UI tests; backend `isEnabled()` gated on `IAppManager::isInstalled('analytics')`
