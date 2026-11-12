## ADDED Requirements

### Requirement: The scheduler finds scheduled flows in every app's store (REQ-SCH-003)

OpenRegister SHALL discover scheduled flows by asking the flow resolver
registry, not by reading one hard-coded store. Any app whose flow resolver also
implements `IScheduledFlowSource` SHALL contribute its scheduled flows, and a
flow that app owns SHALL fire on its cron exactly as one in OpenRegister's own
store does. A resolver that does not implement the interface SHALL be skipped,
and a source that throws SHALL be logged and skipped without stopping the rest of
the instance's schedule.

(Regression guard: the scheduler enumerated only the `flow_register`/
`flow_schema` pair. Flows contributed by an app — hermiq's `agentflow` objects,
including `hydra-sequencer`, `hydra-dispatch` and `hydra-lock-reaper` — were
invisible to it and could never fire. Event triggers had gone through the
resolvers since day one, so the same flow store worked for events and was dead
for schedules; the instance recorded zero runs with trigger `schedule` across
52,478 runs.)

#### Scenario: A scheduled flow owned by another app fires

- **GIVEN** an app whose flow resolver is also a scheduled-flow source
- **AND** that app owns an enabled flow with trigger `schedule` and a valid cron
- **WHEN** the schedule worker ticks and the flow is due
- **THEN** a run is queued for it with trigger `schedule`, attributed to the
  flow's owner

#### Scenario: A resolver that owns no scheduled flows is not asked

- **GIVEN** a contributed resolver that does not implement the source interface
- **WHEN** the scheduler enumerates
- **THEN** it is skipped and contributes nothing

#### Scenario: One broken source does not stop the schedule

- **GIVEN** two sources, the first of which throws
- **WHEN** the scheduler enumerates
- **THEN** the failure is logged and the second source's flows are still returned

### Requirement: The run/do-not-run decision stays in the scheduler (REQ-SCH-004)

A scheduled-flow source SHALL report candidates — including disabled ones, with
their `enabled` flag — and SHALL NOT decide which of them may run. OpenRegister
SHALL re-check `enabled`, re-check that the trigger is `schedule`, and re-parse
the cron before firing, so an app that filtered wrongly cannot cause a disabled
flow to run.

The same flow id reported by more than one source SHALL yield exactly one
candidate, first source winning — matching `resolveFlow()`'s precedence, and
preventing a duplicated id from firing the same flow twice in one tick, which the
no-overlap guard (openregister#2218) cannot catch because both fires precede
either run.

#### Scenario: A disabled flow reported by a source is not fired

- **GIVEN** a source that reports a flow with `enabled` false
- **WHEN** the schedule worker ticks
- **THEN** no run is queued for it

#### Scenario: A flow claimed by two sources fires once

- **GIVEN** two sources reporting the same flow id
- **WHEN** the scheduler enumerates
- **THEN** exactly one candidate is produced, from the first source

#### Scenario: The no-overlap guarantee is unchanged

- **GIVEN** a due flow, from any source, whose previous run is still active
- **WHEN** the schedule worker ticks
- **THEN** no run is queued and its last-fire marker is not advanced

@e2e exclude covered by FlowScheduleServiceTest, FlowResolverRegistryScheduleTest
and OpenRegisterFlowResolverTest, plus a live verification on the dev instance
(an `agentflow`-stored flow with a one-minute cron fired via `occ`, producing the
first `trigger='schedule'` run the instance has ever held, while a disabled
sibling produced none)
