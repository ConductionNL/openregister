# Tasks: calendar-change-recomputes-timers

## 1. Data

- [ ] 1.1 Migration: `organisation` on `openregister_flow_timers`, filled at arm time; index on `(calendar_slug, state)` and `(organisation, state)`.
- [ ] 1.2 `supersede()` accepts reason `calendar-changed` with the calendar version in the ledger event.

## 2. Observation and job

- [ ] 2.1 `WorkingCalendarChangedListener` on `ObjectUpdatedEvent` for `flow-timers` / `working-calendar`, queueing `RecomputeTimersForCalendarJob` with slug and version.
- [ ] 2.2 The job: three dependency sets (D-3), batches of 500 with a cursor, idempotency on (slug, version), counts logged.
- [ ] 2.3 Register the job in `appinfo/info.xml`.

## 3. Tests

- [ ] 3.1 Unit tests: the three sets, unchanged timers untouched, idempotency, fired rungs not repeated, resume after a killed pass.
- [ ] 3.2 `tests/e2e/ci/calendar-recompute.spec.ts`: add an exception on the admin page, run the job, read the superseded timer's history.
