# Tasks: Integration — Time Tracker

## Backend

- [ ] `TimeLink` entity + mapper + migration (entry linked to object + denormalized object total)
- [ ] `TimeEntryService` with per-backend adapter (default: timemanager)
- [ ] `TimeController` with sub-resource endpoints
- [ ] `TimeProvider` — id='time-tracker', label='Time', icon='Clock', group='workflow', requiredApp=(configurable, default 'timemanager'), storage='link-table'
- [ ] Admin setting `time-tracker.backend`
- [ ] `occ openregister:time:reconcile` command for total recalculation
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnTimeTab.vue` — quick-log form (duration + desc), entry list grouped by user/date, object total
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnTimeCard.vue`:
  - `user-dashboard`: user's hours today across objects
  - `app-dashboard`: scoped
  - `detail-page`: object total + per-user/week breakdown
  - `single-entity`: hours chip (e.g., "4h 30m")
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/time-tracker.js` — register with `referenceType: 'time-tracker'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: log time via tab; verify entry in Time Manager app; totals update on dashboard
- [ ] Reconcile: seed drift, run command, verify total corrected
- [ ] Hide test; reference-property test
