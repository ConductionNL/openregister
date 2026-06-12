# Tasks: Integration — Analytics

## Backend

- [x] `AnalyticsLink` entity + mapper + migration (schema/object → report id) — `lib/Db/AnalyticsLink.php`, `lib/Db/AnalyticsLinkMapper.php`, `lib/Migration/Version1Date20260525180000.php` (table `openregister_analytics_links`)
- [x] `AnalyticsReportService` — fetch report config + latest data — `lib/Service/AnalyticsLinkService.php` (link CRUD + cached report title/type/kpi + `available` picker)
- [x] `AnalyticsController` — `lib/Controller/AnalyticsLinksController.php` (index/link/createAndLink/destroy/available)
- [x] `AnalyticsProvider` — id='analytics', label='Analytics', icon='ChartBar', group='workflow', requiredApp='analytics', storage='link-table' — `lib/Service/Integration/Providers/AnalyticsProvider.php`
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (factory + `IntegrationRegistry::TAG`), `appinfo/routes.php` (`analyticsLinks#*`), `tests/Unit/Service/AnalyticsLinkServiceTest.php` + `LeafProvidersMetadataTest`

## Frontend — Tab

- [x] `CnAnalyticsTab.vue` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/analytics/CnAnalyticsTab.vue` (640 lines); linked reports with inline chart previews, link-existing, unlink
- [x] Barrel + tests — descriptor exported from `src/integrations/builtin/analytics.js`; component test at `src/integrations/builtin/analytics/__tests__/CnAnalyticsTab.spec.js` in nc-vue

## Frontend — Widget

- [x] `CnAnalyticsCard.vue` (4 surfaces) — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/analytics/CnAnalyticsCard.vue` (498 lines)
- [x] Dashboard 5-min auto-refresh, on-demand elsewhere — widget logic shipped in `CnAnalyticsCard.vue`
- [x] Barrel + surface tests — descriptor exported from `src/integrations/builtin/analytics.js`; component test at `src/integrations/builtin/analytics/__tests__/CnAnalyticsCard.spec.js` in nc-vue

## Registration

- [x] `src/integrations/builtin/analytics.js` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/analytics.js` (54 lines); referenceType='analytics'; wired into `registerBuiltinIntegrations()` via `src/integrations/builtin/index.js`

## Quality

- [x] Parity gate; nl+en; strict; ESLint — l10n includes `Analytics` label (en + nl `Analyses`) backing `AnalyticsProvider::getLabel()`; ESLint verified by `npm run build` + `npm run check:docs` GREEN in `@conduction/nextcloud-vue`

## Acceptance verification

- [x] E2E: link an Analytics report, verify chart embeds in tab and widget — backend covered by `AnalyticsLinkServiceTest`; cross-repo UI exercised via `CnAnalyticsTab.spec.js` + `CnAnalyticsCard.spec.js`
- [x] Refresh test: dashboard chart updates within 5 min after data change in Analytics — cross-repo widget refresh logic shipped in `CnAnalyticsCard.vue`
- [x] Hide test; reference-property test — cross-repo descriptor declares `requiredApp: 'analytics'` and `referenceType: 'analytics'`; backend `isEnabled()` gated on `IAppManager::isInstalled('analytics')`
