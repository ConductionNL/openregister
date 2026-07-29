# Proposal: or-flow-scheduled-trigger

## Summary

Run a flow on a schedule — n8n's Schedule / Cron trigger, and the last of the
native trigger families. A flow with `trigger: schedule` and a `cron` expression
runs when its time comes, driven by a background worker rather than an event.

Includes a fix for a bug this work uncovered: the flow resolver's
`flowsForTrigger` queried objects with the wrong option key, so it silently found
nothing — meaning **no** event-triggered flow was ever firing. The scheduled
trigger, which reuses the same query, is what surfaced it.

## Why

The event triggers cover "when something happens". Parity also needs "at a time":
a nightly export, a Monday-morning digest. There is no event for a clock, so this
is the one trigger that needs a worker.

## What Changes

- **`FlowScheduleService`** — given "now", lists the enabled `schedule` flows in
  the flow store, and for each whose cron occurrence has passed since it last
  fired, queues a run (trigger `schedule`) and records the fire time (in app
  config, keyed by flow uuid, so the flow object is never rewritten). A flow
  never fired is due at once, then follows its cron.
- **`FlowScheduleWorker`** — a `TimedJob` (five-minute interval) that asks the
  service which flows are due each tick. Scheduled runs are then executed by the
  existing `FlowRunWorker`, so a scheduled flow shares the whole run lifecycle.
- **`flow` schema** gains a `cron` property; the descriptor version is bumped so
  fresh installs ship it.
- **Bug fix** — `OpenRegisterFlowResolver::flowsForTrigger` (and the same pattern
  here) used `findAll(config: ['register' => …, 'schema' => …])`, but
  `ObjectService::findAll` scopes via `config['filters']['register'|'schema']`.
  The wrong key returned zero objects, so **every** event trigger silently
  matched no flows. Corrected to `filters`. (`HermiqFlowResolver` has the same
  bug — fixed in its own repo.)

## Out of scope

- Sub-minute schedules (the tick is five minutes) and timezone-per-flow (cron is
  evaluated in the server's zone).
