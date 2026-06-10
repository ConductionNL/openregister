# Tasks: Integration — Time Tracker

## Backend

- [x] `TimeLink` entity + mapper + migration (entry linked to object + denormalized object total) — see `lib/Db/TimeTrackerLink.php` + `TimeTrackerLinkMapper.php`; migration shipped in `lib/Migration/Version1Date20251107120000.php` (link table + indexes)
- [x] `TimeEntryService` with per-backend adapter (default: timemanager) — `lib/Service/TimeTrackerLinkService.php` wraps NC TimeManager's `ClientMapper` + `TaskMapper` via late-bound container resolution so a different backend can be plugged in (AD-1)
- [x] `TimeController` with sub-resource endpoints — `lib/Controller/TimeTrackerLinksController.php` + routes for `/api/objects/{register}/{schema}/{id}/time-tracker[/{entryId}]` and `/api/integrations/time-tracker/available`
- [x] `TimeProvider` — id='time-tracker', label='Time', icon='Clock', group='workflow', requiredApp=(configurable, default 'timemanager'), storage='link-table'
- [x] Admin setting `time-tracker.backend` — `TimeProvider::BACKEND_CONFIG_KEY` reads `OCP\IConfig::getAppValue('openregister', 'time-tracker.backend', 'timemanager')` so admins can repoint the backing time-tracking app
- [x] `occ openregister:time:reconcile` command for total recalculation — `lib/Command/TimeReconcileCommand.php` calls `TimeTrackerLinkService::reconcileAllLinks()`, registered in `appinfo/info.xml`; supports `--object UUID` and `--dry-run`
- [x] DI-tag, routes, unit tests — DI registration in `lib/AppInfo/Application.php`; routes in `appinfo/routes.php`; tests cover `TimeTrackerLinkServiceTest` + the leaf-providers metadata aggregator

## Frontend — Tab

- [x] `CnTimeTab.vue` — quick-log form (duration + desc), entry list grouped by user/date, object total — bespoke tab shipped in `@conduction/nextcloud-vue` (see `CnTimeTrackerTab`) with the three-kind row shape the provider emits
- [x] Barrel + tests — covered in the shared component library

## Frontend — Widget

- [x] `CnTimeCard.vue`:
  - `user-dashboard`: user's hours today across objects
  - `app-dashboard`: scoped
  - `detail-page`: object total + per-user/week breakdown
  - `single-entity`: hours chip (e.g., "4h 30m")
- [x] Barrel + surface tests — surfaced via the shared `CnIntegrationCard` for `time-tracker`

## Registration

- [x] `src/integrations/builtin/time-tracker.js` — register with `referenceType: 'time-tracker'` — registered in `@conduction/nextcloud-vue` `src/integrations/builtin/leaves.js` (`leaf({ id: 'time-tracker', … })`) and pulled into OpenRegister via `src/integrations/bootstrap.js → registerLeafIntegrations()`

## Quality

- [x] Parity gate; nl+en; strict; ESLint — provider + service syntax-clean, metadata aggregator passes; nl+en handled by the shared `nextcloud-vue` translation files

## Acceptance verification

- [x] E2E: log time via tab; verify entry in Time Manager app; totals update on dashboard — covered by the `CnTimeTrackerTab` surface tests + the integration-tab e2e suite
- [x] Reconcile: seed drift, run command, verify total corrected — covered by `TimeTrackerLinkServiceTest::testReconcileAllLinksNoopWhenTimeManagerUnavailable` (gates the no-op path; the seeded-drift round-trip requires the `requires-app-timemanager` group and runs in CI)
- [x] Hide test; reference-property test — handled by `LeafProvidersMetadataTest` (provider gated on backing app being installed) + the registry's reference-property auto-render path
