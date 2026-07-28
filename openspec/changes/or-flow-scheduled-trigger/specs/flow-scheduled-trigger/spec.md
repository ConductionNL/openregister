## ADDED Requirements

### Requirement: A flow can run on a schedule (REQ-SCH-001)

OpenRegister SHALL run a flow whose `trigger` is `schedule` on the cadence of its
`cron` expression, driven by a background worker. A due flow SHALL have a run
queued with trigger `schedule`; the fire time SHALL be remembered so the flow is
not re-fired until its next occurrence. A disabled flow, a non-schedule trigger,
and an invalid or missing cron SHALL NOT fire.

#### Scenario: A due scheduled flow fires

- **GIVEN** an enabled flow with trigger `schedule` and a valid cron
- **WHEN** the schedule worker ticks and the flow is due
- **THEN** a run is queued for it with trigger `schedule`

#### Scenario: A non-schedule flow is not fired by the worker

- **GIVEN** a flow with a cron but a non-schedule trigger
- **WHEN** the schedule worker ticks
- **THEN** no run is queued for it

### Requirement: Trigger resolution scopes to the flow store correctly (REQ-SCH-002)

The flow resolver SHALL scope its object query with the register/schema filter
`ObjectService::findAll` expects, so that flows wired to an event are actually
found. (Regression guard: the wrong option key returned zero flows and silently
disabled every trigger.)

#### Scenario: Flows wired to an event are found

- **GIVEN** an enabled flow in the store wired to an event
- **WHEN** flows for that event are listed
- **THEN** the flow is returned

@e2e exclude covered by tests/e2e/api-direct/flow-schedule.spec.ts (author a
scheduled flow, tick the worker via occ, assert a schedule run) and
FlowScheduleServiceTest; the resolver fix is live-verified (scheduled + event
flows now match)
