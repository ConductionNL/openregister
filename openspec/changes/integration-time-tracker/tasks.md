# Tasks: Integration — Time Tracker

## Backend

- [~] `TimeLink` entity + mapper + migration (entry linked to object + denormalized object total) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `TimeEntryService` with per-backend adapter (default: timemanager) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `TimeController` with sub-resource endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `TimeProvider` — id='time-tracker', label='Time', icon='Clock', group='workflow', requiredApp=(configurable, default 'timemanager'), storage='link-table'
- [~] Admin setting `time-tracker.backend` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `occ openregister:time:reconcile` command for total recalculation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnTimeTab.vue` — quick-log form (duration + desc), entry list grouped by user/date, object total — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnTimeCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: user's hours today across objects
  - `app-dashboard`: scoped
  - `detail-page`: object total + per-user/week breakdown
  - `single-entity`: hours chip (e.g., "4h 30m")
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/time-tracker.js` — register with `referenceType: 'time-tracker'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: log time via tab; verify entry in Time Manager app; totals update on dashboard — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reconcile: seed drift, run command, verify total corrected — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
