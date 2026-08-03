# Proposal: or-flow-queue-fairness

## Summary

Share the flow-run queue between the flows that are waiting, and abandon runs
that waited too long to still be worth running.

## Why

`FlowRunMapper::findQueued()` was a single global FIFO: `WHERE status='queued'
ORDER BY id ASC LIMIT 25`. Queue position was therefore a function of arrival
order and nothing else, which means one flow that queues in bulk owns the whole
queue until it drains.

Measured on the dev instance on 2026-08-02:

| status    | count  |
|-----------|--------|
| completed | 43,218 |
| queued    |  9,644 |
| failed    |    144 |
| stopped   |     60 |

Every one of the 9,644 queued runs belonged to **one flow**. The worker drains
exactly `BATCH` runs per cron pass and the system cron ticks every five minutes,
so the observed drain was a flat 25 per 5 minutes — 300/hour — and a run queued
behind that backlog waited about **thirty-two hours** to start.

Nothing distinguishes a schedule tick, a user pressing "run", or a sub-flow from
the burst. They all waited the same thirty-two hours. Everything upstream now
works — save-time preflight, deterministic slug resolution, `IScheduledFlowSource`
so the scheduler can see every flow store — and a scheduled flow now genuinely
fires. It then joins the back of this queue, and that is where the pipeline
stops being autonomous.

This is not a backlog that needs clearing once. A queue with no fairness
property re-creates the same outage the next time anything queues in bulk.

There is a second, quieter failure. `FlowRunMapper::hasActiveRun()` counts
`queued`, and `FlowScheduleService` uses it as the singleton guard that stops a
flow overlapping itself (openregister#2218). A starved queued run therefore makes
that guard refuse **every subsequent tick of its own flow** — one stuck run
silently stops an entire schedule, for as long as it sits there. Fairness alone
does not fix that: a flow whose only run is stuck never gets a second run to be
fair to. Expiry does.

## What Changes

- `FlowRunMapper::findQueued()` divides the batch between the flows that have
  queued work, `ceil(limit / flowCount)` each, oldest-first within a flow, with
  flows served oldest-waiting-first.
- `FlowRunMapper::expireQueuedBefore()` fails queued runs older than a cut-off,
  in capped batches, with an error that says what happened.
- `FlowRunWorker::expireStaleQueued()` runs before the queue is drained.
- `flow_run_queued_ttl_hours` app config, default 24, `0` to disable.

## Design decisions

**Fairness, not priority.** "Let scheduled runs jump the queue" was considered
and rejected: it privileges one trigger and leaves every other caller — manual
runs, sub-flows, webhooks, object events — starved by exactly the same
mechanism, so it would have to be re-fixed per trigger. Fairness is the general
statement of the same fix, and once it holds, priority is unnecessary: a
scheduled flow's tick is the only queued run of its flow, so it is served on the
very next pass regardless of how deep anyone else's backlog is.

**Per-flow share, not per-flow concurrency limits.** A concurrency cap needs
state — how many runs of this flow are in flight — and a run executes
synchronously inside one pass, so there is nothing to count. Dividing the batch
achieves the same isolation with a query and no bookkeeping.

**Rotation without a cursor.** Flows are ordered by their oldest queued run.
Serving a flow advances its oldest queued id, which moves it behind the flows it
just went ahead of, so round-robin falls out of the ordering. A stored cursor
would be one more piece of state to get wrong on a shared queue.

**Identical for one flow.** With a single waiting flow, `ceil(limit/1) = limit`
and the query is oldest-first — byte-for-byte the old behaviour. The common case
does not change.

**Batch size is not the fix.** Raising `BATCH` divides the wait by a constant
and removes no starvation: the flow that queues in bulk still owns every slot.
It is left alone.

**Expired, not deleted; failed, not requeued.** An expired run keeps its row,
its attribution and its place on every surface that lists runs, and the existing
retry endpoint turns it back into work if a person decides the tick still
mattered. This is the same call `reapStale()` already makes for abandoned
`running` rows: a cron job may say "this did not happen", never "this should
happen anyway".

**Twenty-four hours, and configurable.** A queued run says "do this now"; a day
later that is no longer what it says. An instance whose cron is deliberately
intermittent, and which wants every tick eventually run, sets `0`.

**The existing backlog is resolved by this mechanism, not by SQL.** No migration
and no one-off `DELETE`. The worker expires the backlog on its own passes, which
is the same outcome, is auditable row by row, and means the next bulk burst
self-heals too.
