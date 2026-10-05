# Tasks: or-flow-schedule-any-store

## 1. The capability

- [x] 1.1 Add `IScheduledFlowSource` — an optional capability alongside
      `IFlowResolver`, so existing resolvers are untouched.
- [x] 1.2 Add `FlowResolverRegistry::scheduledFlows()` — gather from every
      contributing app, de-duplicate by id (first source wins), log and skip a
      source that throws.

## 2. OpenRegister's own store becomes a source

- [x] 2.1 `OpenRegisterFlowResolver` implements `IScheduledFlowSource`,
      reporting the flows in `flow_register`/`flow_schema` whose trigger is
      `schedule`, with cron, enabled flag and owner.
- [x] 2.2 Report disabled schedules honestly rather than filtering them, so the
      run/do-not-run decision has one home.

## 3. The scheduler enumerates through the registry

- [x] 3.1 `FlowScheduleService` takes `FlowResolverRegistry` instead of
      `ObjectService`; drop the store-naming config it no longer needs.
- [x] 3.2 Keep every gate in the scheduler: enabled, trigger, cron validity,
      due-ness, and the no-overlap guard (#2218).

## 4. Tests

- [x] 4.1 A flow contributed by another app fires, attributed to its owner.
- [x] 4.2 A disabled candidate from a source that did not filter is not fired.
- [x] 4.3 A resolver without the capability is skipped; a throwing source does
      not stop the rest.
- [x] 4.4 A duplicated flow id yields one candidate.
- [x] 4.5 The existing no-overlap and last-fire-marker guarantees still hold.

## 5. Verify

- [x] 5.1 Positive control: count `trigger='schedule'` runs before the change.
- [x] 5.2 Live: an `agentflow`-stored flow with a short cron fires; a disabled
      one does not; the count returns to baseline after the probe is removed.
