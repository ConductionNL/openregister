# Proposal: or-flow-schedule-any-store

## Summary

Let the flow scheduler see the flows other apps own. It asked one hard-coded
store; now it asks the resolver registry, the way every other trigger already
did.

## Why

Every trigger family except the schedule went through `FlowResolverRegistry`.
The schedule alone read the `flow_register`/`flow_schema` pair directly, so a
flow contributed by an app — hermiq's `agentflow` objects — was invisible to it.
Not refused, not logged: invisible. The flow could be enabled, carry a valid
cron, and be walked correctly on a manual run, and still never fire, because
nothing ever asked whether it existed.

Measured on the dev instance before this change: **zero** runs with
`trigger='schedule'`, out of 52,478 runs (`object.created` 52,364, `test` 96,
`verify-harness` 7, `hydra-e2e-harness` 6, `sub-flow` 2, `mcp` 2, `retry` 1).
The three flows in that state — `hydra-sequencer`, `hydra-dispatch`,
`hydra-lock-reaper` — are the hydra pipeline's clock. The pipeline ran correctly
whenever it was triggered and nothing could trigger it.

## What Changes

- **`IScheduledFlowSource`** (new) — an optional capability a flow resolver may
  also implement, listing the flows it owns that declare a schedule. Separate
  from `IFlowResolver` so every existing resolver keeps compiling and an app
  that owns only event-triggered flows answers nothing.
- **`FlowResolverRegistry::scheduledFlows()`** — gathers candidates from every
  contributing app, de-duplicating by flow id (first source wins, matching
  `resolveFlow()`), logging and skipping a source that throws.
- **`OpenRegisterFlowResolver`** — becomes a source for OpenRegister's own flow
  store. This is the enumeration `FlowScheduleService` used to do inline, moved
  so there is one way to find a scheduled flow instead of one for this store and
  none for anybody else's. Behaviour for this store is unchanged.
- **`FlowScheduleService`** — takes the registry instead of `ObjectService`, and
  keeps every decision: `enabled`, trigger, cron validity, due-ness, and the
  no-overlap guard (#2218) are all still applied here, to candidates from every
  source. A source is trusted to say which flows it owns, not which may run.

## Blast radius

The scheduler now enumerates through the resolvers on every instance in the
fleet, so it can see more flows than before. Three things bound that:

1. A source reports only flows whose `trigger` is `schedule`. An event-triggered
   or manual flow never reaches the scheduler.
2. `enabled` is re-checked centrally. A disabled flow is never fired regardless
   of what a source reported — asserted for a source that reports disabled flows
   without filtering them.
3. A flow with no cron, or an invalid one, is skipped.

Cost is one flow-store listing per contributing app per five-minute tick — the
same listing the trigger index already performs, and the scheduler's own listing
is not added on top of it, it is the same one moved.

## Non-goals

The `cron` property missing from hermiq's `agentflow` schema is the second,
independent cause of the same symptom and is fixed in hermiq, not here.
