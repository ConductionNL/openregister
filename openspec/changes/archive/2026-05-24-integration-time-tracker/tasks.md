# Tasks: Integration — Time Tracker

## Backend

- [ ] Create `TimeLink` entity + mapper + migration for `openregister_time_links` with denormalized per-object hour total (deferred — Tier-2 follow-up; bespoke link-table stays out of the Bucket-A stub-completion scope per ADR-019)
- [ ] `TimeEntryService` with per-backend adapter (default: timemanager) (deferred — same Tier-2 follow-up)
- [ ] `TimeController` with sub-resource endpoints (deferred — same Tier-2 follow-up)
- [x] Replace the 137-line MarkerLookupTrait stub of `TimeProvider` with a real `OCA\TimeManager\Db\ClientMapper` + `TaskMapper` integration — id='time-tracker', label='Time', icon='Clock', group='workflow', requiredApp='timemanager', storage='link-table'; lazy-resolves the Time Manager mappers via the server container so the file loads when the Time Manager app is not installed
- [x] DI-tag in `Application.php` (already present via the greenfield-providers registration block; constructor signature preserved as `(IDBConnection, IAppManager, IL10N)` with an optional `ContainerInterface` override for tests)
- [ ] Admin setting `time-tracker.backend` (deferred — Tier-2; provider is hard-coded to `timemanager` until the configurable backend lands alongside the link-table)
- [ ] `occ openregister:time:reconcile` command for total recalculation (deferred — depends on the link-table + denormalized total)
- [ ] Add routes to `appinfo/routes.php` (deferred — depends on TimeController)
- [x] Unit tests for provider — `tests/Unit/Service/Integration/Providers/TimeProviderTest.php` covers metadata, happy-path (client + tasks surfaced via `note` marker), absent-app (graceful empty + missingApp health), empty-result, container-error fallback, task-mapper-unavailable fallback, health-ok / health-unavailable

## Frontend — Tab

- [ ] `CnTimeTab.vue` — quick-log form (duration + desc), entry list grouped by user/date, object total (deferred — bespoke Tab + Widget components are out of this change's scope per the refreshed proposal acceptance criteria; the generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` continues to serve)
- [ ] Barrel + tests (deferred — same)

## Frontend — Widget

- [ ] `CnTimeCard.vue` (deferred — same)
  - `user-dashboard`: user's hours today across objects
  - `app-dashboard`: scoped
  - `detail-page`: object total + per-user/week breakdown
  - `single-entity`: hours chip (e.g., "4h 30m")
- [ ] Barrel + surface tests (deferred — same)

## Registration

- [x] Generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` (already shipped — bespoke registration / `referenceType: 'time-tracker'` chip rendering tracked separately as a Tier-2 follow-up)

## Quality

- [x] Parity gate passes (`nextcloud-vue/scripts/check-integration-parity.js` green); PHPStan + Psalm clean on the new backend files; PHPMD parity vs. baseline (no new violations introduced — same `UnusedFormalParameter` shape every leaf provider ships with for the interface-contract `$register/$schema/$filters` triple); PHPCS introduces the same inline-IF debt every wave-1 provider carries (10 of the same pattern as `FormsProvider`); nl+en translations not introduced (provider label routes through `IL10N::t` — existing translation infrastructure)

## Acceptance verification

- [ ] E2E: install Time Manager, link a client/task via `note` marker, verify display in the registry tab (deferred — depends on the Tier-2 frontend)
- [ ] Reconcile: seed drift, run command, verify total corrected (deferred — depends on `occ openregister:time:reconcile`)
- [ ] Hide test; reference-property test (deferred — depends on the Tier-2 frontend)
