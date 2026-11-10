---
kind: code
---

## Why

The `optimize-write-path-performance` listener audit (design.md D5) classified
all 16 post-save object-event listeners and concluded that **zero** could move
to background jobs: every heavy candidate resolves the acting user from the
ambient `IUserSession` — translator attribution in
`TranslationProjectionListener`, RBAC-scoped deeplink resolution inside the
notification dispatcher used by `AnnotationNotificationListener` and
`AggregationThresholdListener`. A Nextcloud background job runs without a
session, so naive deferral would misattribute writes or silently change RBAC
scope. The audit explicitly deferred the fix to a follow-up "actor-forwarding
deferral contract" (openregister#408). This change builds that contract and
uses it to take the three blocked listeners off the synchronous write path.

Cost today, per object write: a translation-projection reconciliation
(sidecar SELECT + up to P×L upserts), a full notification-rule evaluation
(which can perform synchronous outbound HTTP and mail per ADR-009 Rule 5
violation), and — on schemas with threshold triggers — aggregation queries.
On bulk saves this multiplies per object.

## What Changes

- **Actor-forwarding deferral contract** (new `lib/Service/Deferral/`):
  - `DeferredListenerContext` — serializable value object carrying the acting
    context captured at dispatch time: `userId`, `organisationUuid` (active
    organisation at capture, for drift detection), and per-object entries
    (uuid, register, schema, version, per-entry payload). Round-trips through
    the JSON-serialized `oc_jobs.argument` column.
  - `ListenerDeferralService` — captures the actor once per request, buffers
    per-job-class entries, and enqueues **chunk-level** jobs (one job per ≤
    chunk-size entries, not per object; flush at chunk boundary and at request
    shutdown via `register_shutdown_function`, precedent:
    `SearchQueryHandler::flushSearchTrails()`). Supports per-entry dedupe keys
    so N writes to one schema coalesce to one threshold evaluation. Honours an
    `openregister/listenerDeferral` app-config kill switch (`background`
    default, `inline` restores pre-change synchronous behaviour).
- **`ActorForwardedJob`** (new `lib/BackgroundJob/ActorForwardedJob.php`,
  abstract, extends `\OCP\BackgroundJob\QueuedJob`): re-establishes the
  captured user via `IUserManager::get()` + `IUserSession::setUser()` before
  running the subclass work, and **always restores the previous session user
  in a `finally` block** so a cron process never leaks identity across jobs
  (precedent: `ScheduledReportService::runOne()`). If the captured user no
  longer resolves, the job logs and no-ops — it never runs under a wrong or
  system identity. Organisation context re-derives from the restored user's
  persistent config (the same source the request session cache was primed
  from); a drift between captured and current active organisation is logged
  and the job proceeds under the user's CURRENT authority (never resurrects
  stale authority).
- **Three listeners deferred** (each keeps a cheap inline schema-config gate
  so schemas without the feature enqueue nothing, and keeps its full inline
  path for the kill switch):
  - `TranslationProjectionListener`: created/updated/transitioned projection
    moves to `TranslationProjectionJob` (chunked uuids; translator attribution
    now correct in the job because the actor is forwarded). Delete-time
    `purge()` stays inline — it is a cheap bounded sidecar DELETE and the
    object row is not re-fetchable after the delete.
  - `AnnotationNotificationListener`: all dispatches move to
    `AnnotationNotificationDispatchJob`. Update events carry the pre-update
    data snapshot in the entry payload (it cannot be re-fetched later); new
    data is re-fetched at run time so the job always evaluates against
    current state.
  - `AggregationThresholdListener`: evaluation logic is extracted verbatim
    into `ThresholdEvaluationService` (shared by the inline and deferred
    paths); created/updated/transitioned evaluations move to
    `AggregationThresholdJob` with entries deduped per (register, schema).
    Delete events stay inline: a hard-deleted object cannot be re-fetched by
    the job, and delete-driven threshold crossings (e.g. `lt` operators)
    would be silently lost.
- **Delivery semantics**: at-least-once. The object-event dispatch sites
  (`SaveObjects::emitChunkSideEffects()`, `MagicMapper`) run after
  persistence with no wrapping DB transaction, so an enqueued job always
  refers to committed state. Jobs are idempotent: they re-fetch the object by
  (uuid, register, schema), no-op when it is gone or soft-deleted, and
  reconcile against CURRENT state (projection is a desired-set reconciliation;
  threshold state has rising-edge dedup in the distributed cache; the
  notification dispatcher has dispatch-log dedup).

## Capabilities

### event-driven-architecture (delta)
Adds the actor-forwarded deferral contract: capture-at-dispatch,
re-establish-in-job, guaranteed restore, chunk-level enqueueing, stale no-op
idempotency, and the classification of which listeners run deferred vs inline.

## Impact

- Affected code: `lib/Service/Deferral/` (new), `lib/BackgroundJob/
  ActorForwardedJob.php` + 3 concrete jobs (new),
  `lib/Service/Aggregation/ThresholdEvaluationService.php` (new, extracted),
  `lib/Listener/TranslationProjectionListener.php`,
  `lib/Listener/AnnotationNotificationListener.php`,
  `lib/Listener/AggregationThresholdListener.php`.
- Request-path effect per write on a schema using these features: projection
  reconciliation, notification-rule evaluation (incl. outbound HTTP/mail) and
  aggregation queries leave the synchronous path; what remains inline is one
  request-cached `SchemaMapper::find()` + a config-array scan + a buffered
  array append (one `oc_jobs` INSERT per chunk).
- Behavioural: side effects become eventually consistent (next cron run).
  Realtime/cache/activity listeners intentionally stay inline (unchanged).
  Admins can restore synchronous behaviour with
  `occ config:app:set openregister listenerDeferral --value inline`.
