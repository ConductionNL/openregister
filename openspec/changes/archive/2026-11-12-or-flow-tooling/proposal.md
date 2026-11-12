# Proposal: or-flow-tooling

## Summary

Expose the run history that persistence already stores, and let a finished run
be retried.

## Why

Runs are persisted with their status, per-step log, items and error — but there
was no way to see them or act on them. "What did my flow do, and can I run it
again" is the single most-asked question of any automation system, and the data
to answer it was already in the table.

## What Changes

- **`FlowRunService::retry()`** — queues a NEW run against the same flow,
  subject and trigger, leaving the original exactly as it ended. It never
  re-executes the old run: that would repeat every side effect it performed.
  Only a terminal run can be retried — a queued or running one is already on its
  way, a suspended one resumes rather than restarts.

- **`FlowRunController`** — `GET /api/flow-runs` (list, newest first, filter by
  `flowId` and `status`, paged), `GET /api/flow-runs/{uuid}` (one run in full),
  `POST /api/flow-runs/{uuid}/retry` (201 with the new run, or 409 when the
  source is not terminal).

## Out of scope (this change)

- **Pin / mock data** — freezing a step's output while authoring. The single
  biggest authoring-productivity feature, and its own change: it needs an
  editor surface and a per-step override the engine reads.
- **Partial execution** — running up to a step against pinned data. Follows
  pin/mock.
- **A dead-letter requeue UI** — the retry endpoint already requeues a
  dead-lettered run (it is terminal); a dedicated dead-letter queue view is
  presentation, deferred.
