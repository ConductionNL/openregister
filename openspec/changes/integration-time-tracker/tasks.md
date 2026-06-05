# Tasks: Integration — Time Tracker

## Backend

- [x] `TimeLink` entity + mapper + migration (entry linked to object + denormalized object total)
- [x] `TimeEntryService` with per-backend adapter (default: timemanager)
- [x] `TimeController` with sub-resource endpoints
- [x] `TimeProvider` — id='time-tracker', label='Time', icon='Clock', group='workflow', requiredApp=(configurable, default 'timemanager'), storage='link-table'
- [x] Admin setting `time-tracker.backend`
- [x] `occ openregister:time:reconcile` command for total recalculation
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [x] `CnTimeTab.vue` — quick-log form (duration + desc), entry list grouped by user/date, object total
- [x] Barrel + tests

## Frontend — Widget

- [x] `CnTimeCard.vue`:
  - `user-dashboard`: user's hours today across objects
  - `app-dashboard`: scoped
  - `detail-page`: object total + per-user/week breakdown
  - `single-entity`: hours chip (e.g., "4h 30m")
- [x] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/time-tracker.js` — register with `referenceType: 'time-tracker'`

## Quality

- [x] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: log time via tab; verify entry in Time Manager app; totals update on dashboard
- [ ] Reconcile: seed drift, run command, verify total corrected
- [ ] Hide test; reference-property test
