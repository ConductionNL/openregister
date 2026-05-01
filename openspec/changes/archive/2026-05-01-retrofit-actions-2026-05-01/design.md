## Context

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

The Actions feature is an already-implemented, standalone workflow trigger system layered on top of OpenRegister's object lifecycle events. It evolved from (and coexists with) the earlier inline schema hooks (`HookListener`/`HookExecutor`). The change `retrofit-actions-2026-05-01` retroactively specifies observed behavior as 5 REQs covering CRUD, event-triggered execution, sync/async modes, retry with backoff, and scheduled execution.

**Files covered:**
- `lib/Controller/ActionsController.php` (CRUD API)
- `lib/Service/ActionService.php` (business logic)
- `lib/Service/ActionExecutor.php` (execution orchestration)
- `lib/BackgroundJob/ActionRetryJob.php` (retry queue)
- `lib/BackgroundJob/ActionScheduleJob.php` (cron scheduler)
- `lib/Listener/ActionListener.php` (event dispatcher bridge)

## Goals / Non-Goals

**Goals:**
- Provide traceability: each method now carries a `@spec` tag pointing at a concrete REQ
- Surface two observed defects without fixing them: (1) retry delay is calculated but not applied, (2) `CronExpression` is optional but not guarded
- Establish the spec as the canonical reference for future Actions extensions

**Non-Goals:**
- No code changes — this is annotations + spec only
- Does not spec the test dry-run endpoint (`testAction`) or hook migration utility (`migrateFromHooks`) — deferred to a follow-up `--extend actions` run
- Does not fix the retry delay bug or guard the cron dependency

## Decisions

**Decision: `--cluster actions` (new spec) rather than `--extend schema-hooks`**

Actions and schema hooks coexist as separate subsystems. Hooks are inline on a schema's `hooks` array and execute synchronously through `HookExecutor`. Actions are standalone entities managed via `ActionsController` with their own persistence, lifecycle management, retry policies, and scheduling. Extending `schema-hooks` would conflate two distinct systems.

**Decision: 5 REQs — test dry-run and hook migration deferred**

`testAction()` and `migrateFromHooks()` are real observable behaviors, but they are utility/tooling methods that operate on top of the core REQs. Including them would push the cluster over the 5-REQ cap. A follow-up `--extend actions` run will spec them.

**Decision: Surface retry delay bug in spec Notes rather than silently correct it**

`ActionRetryJob::calculateDelay()` computes delay values but the result is not used to delay the next job dispatch. The job re-queues immediately. The spec surfaces this in Notes and in REQ-004 so reviewers are aware. Fixing it is a separate PR.

## Risks / Trade-offs

- **CronExpression optional dependency**: `ActionScheduleJob` uses `CronExpression` from `dragonmantank/cron-expression` without a runtime guard. If the package is missing, the background job fails silently (PSR logger catches it). The psalm suppress directive is not a runtime guard. → Mitigation: a follow-up PR should add `class_exists(CronExpression::class)` check before instantiation.

- **Retry delay not applied**: `calculateDelay()` returns values that are never used in `ActionRetryJob::run()`. Failed actions re-queue immediately rather than with backoff delay. This means under load, a failing action could produce rapid re-queue storms. → Mitigation: convert `ActionRetryJob` from `QueuedJob` to a `TimedJob` with the computed delay, or use `$jobList->scheduleAfter()` if available.

- **Execution ordering**: The spec states actions execute in `executionOrder` field order, but the DB-level sort behavior of `ActionMapper::findMatchingActions()` was not fully verified. If ordering is wrong, pre-mutation sync actions may behave non-deterministically. → Verify in integration test.

## Migration Plan

No migration required — this is annotations-only. The ghost change is archived immediately.

`.git-blame-ignore-revs` will be updated with the annotation commit SHA so that `git blame` continues to show original authors for annotated files.
