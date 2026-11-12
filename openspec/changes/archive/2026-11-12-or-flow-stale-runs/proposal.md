# Proposal: or-flow-stale-runs

## Summary

Fail runs abandoned in `running`, so a live-runs surface tells the truth.

## Why

`FlowRunService::execute()` sets `running` before the walk and clears it when
the walk returns. A pass that dies instead — a fatal, a PHP timeout, an OOM, a
container restart — never clears it, and the `catch (Throwable)` in the worker
cannot help, because the process is gone.

The row is then not merely stale, it is **unreachable**: the worker reads only
`queued` runs and due `suspended` ones, so nothing ever looks at it again.

This cost nothing while nothing read `running`. It stops being free the moment a
dashboard widget shows live runs (`or-flow-active-runs`): 68 such rows existed on
one dev instance, the oldest two days old, and every one of them would have read
as "running right now" — forever.

## What Changes

- `FlowRunMapper::findStale()` — runs in `running` whose `updated` predates a
  cut-off, oldest first.
- `FlowRunWorker::reapStale()` — runs first in each pass, before any new work,
  and marks each abandoned run `failed` with an error that says what happened
  and what to do about it.
- `flow_run_stale_minutes` app config, default 15, `0` to disable.

## Design decisions

**Failed, not requeued.** A run that died mid-walk may already have written an
object, sent a mail, or called a webhook. Restarting it would repeat those
silently. The retry endpoint already turns a terminal run back into a fresh run
when a person decides that is right — which is exactly the kind of decision a
cron job should not make.

**Fifteen minutes, and configurable.** A run executes synchronously inside ONE
pass, so a pass that is still going has touched its row far more recently than
that; the window exists to be unambiguous, not tight. An operator running very
long single steps can set `0` and opt out rather than have the reaper fail work
that is still in flight.

**Reaped before new work.** A pass that starts by cleaning up cannot mistake its
own fresh `running` rows for abandoned ones.
