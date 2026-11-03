# Design — actor-forwarded-listener-jobs

Precedent: ADR-009 Rule 5 ("Outbound I/O is asynchronous to the write" —
webhook delivery already runs first-attempt-in-job). This change extends the
same principle to session-entangled listeners by making the missing piece —
the acting context — an explicit, forwarded parameter instead of ambient
session state.

## D1. What "session-entangled" actually meant, per listener

The `optimize-write-path-performance` D5 audit blocked three deferrals on
`IUserSession`. Re-audit at HEAD confirms the exact entanglement points:

- `TranslationProjectionListener` → `TranslationProjectionService::project()`
  line ~123: `$translator = $this->userSession->getUser()?->getUID()` —
  attribution only. Everything else in the projection is a function of the
  object + schema + register.
- `AnnotationNotificationListener` → `AnnotationNotificationDispatcher`:
  the dispatcher never reads `IUserSession` directly, but
  `resolveRelationDeeplink()` re-resolves relation targets through
  `ObjectService::find()` **with RBAC enabled** — deliberately, so a deeplink
  is only built for an object the current context may read. In a session-less
  job that read fails closed (Anonymous), silently dropping deeplinks.
- `AggregationThresholdListener`: the aggregation itself already runs
  `bypassRbac: true` (system reaction to a write); its entanglement is only
  transitive, through the same dispatcher.

Conclusion: forwarding the acting user and re-establishing it in the job
resolves all three. No listener needs `SystemOperationContext` elevation —
running as the real actor is strictly more faithful and strictly less
privileged.

## D2. The contract

**Capture (request, dispatch time).** `ListenerDeferralService` captures once
per request: `userId` from `IUserSession`, `organisationUuid` from
`OrganisationService::getActiveOrganisation()` (fail-soft null — capture must
never break a save). Both may legitimately be null (occ/cron-originated
writes had no session inline either; the job then simply runs without
impersonation, which is behaviour-identical).

**Payload.** Job arguments go through `IJobList::add(class, arguments)` and
are JSON-serialized into `oc_jobs.argument`. Entries carry ids only — uuid,
register, schema, version — plus the minimal per-entry payload the listener
semantically requires and that cannot be re-fetched later:
- translation: nothing (projection is a pure function of current state);
- threshold: nothing (aggregation recomputed fresh);
- annotation `transition`: `{action, from, to}` (event-only data);
- annotation `updated`: the pre-update object data snapshot (`oldData`). The
  OLD state is gone from the primary row the moment the job runs, and
  field-change / calculatedChange conditions need it. New data is NOT
  snapshotted — the job re-fetches current state.

**Chunking.** One job per ≤ chunk-size entries per (job class): the service
buffers appends, flushes a full chunk immediately, and flushes the remainder
via `register_shutdown_function` (runs after the response; DB still
available; precedent `SearchQueryHandler`). Bulk saves dispatch per-object
events, so without buffering a 1 000-object import would enqueue 1 000 jobs;
with it: ⌈1000/chunk⌉ for translation, and — because threshold entries carry
a (register, schema) dedupe key — exactly 1 threshold job per touched schema.

**Re-establishment (cron, run time).** `ActorForwardedJob::run()`:

```
previous = userSession->getUser()
user     = userId !== null ? userManager->get(userId) : null
if (userId !== null && user === null): log + return   // never misattribute
if (user !== null): userSession->setUser(user)
try:
    warn-if-drifted(capturedOrgUuid vs current active org)
    static::runDeferred(context)                        // subclass work
                                                        // (QueuedJob::execute() is final in OCP)
finally:
    userSession->setUser(previous)                      // ALWAYS restore
```

Cleanup guarantees: the `finally` restore runs on any exception, so the cron
worker can never carry one job's identity into the next job
(`ScheduledReportService::runOne()` precedent). `OrganisationService`'s
session/static caches are keyed per userId, so no cross-user cache bleed.

**Organisation context.** The active organisation re-derives at run time from
the restored user's persistent user-config (`fetchActiveOrganisationFromDatabase`)
— the same source of truth the request's session cache was primed from. It is
NOT force-restored to the captured value: `setActiveOrganisation()` would
mutate the user's persistent preference, and resurrecting a since-switched
organisation would mean running under authority the user no longer exercises.
Captured `organisationUuid` is used for drift *detection* only (logged); the
job proceeds under the user's CURRENT authority. Deliberate security posture:
re-establish identity, never re-establish stale authority.

## D3. Idempotency / delivery semantics

NC `QueuedJob`s are at-least-once (a worker dying mid-run leaves the row for
the next cron pass). Every job is therefore a reconciliation against current
state:

- Re-fetch by `(uuid, register, schema)` via `ObjectService::find(_rbac:
  false, _multitenancy: false, _render: false)` — register+schema avoids the
  bare-UUID cross-table scan; RBAC false because the inline listener received
  the entity directly (the actor context is needed for attribution and for
  the dispatcher's own RBAC-scoped deeplink reads, not for the re-fetch).
- Object gone or soft-deleted (`getDeleted()` non-empty) → skip that entry
  (stale no-op). This also closes the update-then-delete race: a projection
  job that lands after the delete's inline purge must not resurrect sidecar
  rows.
- Translation projection reconciles a desired set (upsert + prune) — running
  twice, or running against newer state than the event, converges.
- Threshold evaluation recomputes the aggregation fresh; rising-edge dedup
  lives in the distributed state cache exactly as inline.
- Annotation dispatch re-reads current data for `_newData`; duplicate
  delivery on retry is suppressed by the dispatcher's dispatch-log dedup.
  If the object changed again before the job ran, conditions evaluate
  old-snapshot → current (the later change's own job covers the rest);
  documented as at-least-once with converging effects.

**Ordering vs the DB transaction:** verified — neither `SaveObject`, nor
`SaveObjects`, nor the `MagicMapper` dispatch sites wrap event dispatch in an
explicit transaction (`beginTransaction` absent from the save path). Events
fire after rows are persisted, so an enqueued job always references committed
state; there is no torn-read window to protect against. Should a transaction
ever be introduced around the save, the enqueue (an INSERT on the same
connection) would commit or roll back atomically with it — the outbox
property holds in both worlds.

## D4. Per-listener verdicts (this change)

| Listener | Deferred | How / why not |
|---|---|---|
| TranslationProjectionListener (C/U/Transitioned) | ✅ `TranslationProjectionJob` | Heavy reconciliation off the write path; translator attribution correct via forwarded actor. Inline gate: schema declares ≥1 `translatable` property. |
| TranslationProjectionListener (Deleted → `purge()`) | ❌ stays inline | Bounded sidecar DELETE (cheap, not worth a job) AND the entity is not re-fetchable post-delete; inline purge also guarantees no deferred `project()` can outlive the object unnoticed (jobs skip deleted objects). |
| AnnotationNotificationListener (created/updated/transition/calculatedChange) | ✅ `AnnotationNotificationDispatchJob` | Rule evaluation + outbound HTTP/mail off the write path (ADR-009 Rule 5). Deeplink RBAC reads now run under the forwarded actor. Inline gate: schema declares `x-openregister-notifications`. Old-data snapshot travels in the entry. |
| AggregationThresholdListener (C/U/Transitioned) | ✅ `AggregationThresholdJob` | Aggregation queries off the write path; (register, schema) dedupe collapses bulk saves to one evaluation. Inline gate: schema declares a threshold-typed notification. |
| AggregationThresholdListener (Deleted) | ❌ stays inline | Hard-deleted objects cannot be re-fetched by the job, and the dispatcher needs an object; deferring would silently drop delete-driven crossings (`lt`/`lte` rules). Evaluation logic is shared (`ThresholdEvaluationService`) so the inline path costs no duplication. |
| Everything else in D5's table (NotifyPush, Realtime, cache invalidation, Activity, pre-save `-ing` listeners, SourceRecordChange, ObjectCleanup, Hook, FlowAction, Metrics, GraphQL, Webhook/Action/TextExtraction) | unchanged | Cheap, latency-sensitive, already-deferred, or out of scope per the brief (pre-save listeners can never defer; SourceRecordChange/ObjectCleanup remain session-entangled beyond actor identity — RBAC-scoped recompute reads and per-user calendar/addressbook resolution need their own analysis). |

## D5. Failure modes

- Capture fails (org lookup throws) → context captured with null org; save
  unaffected.
- Enqueue fails (`IJobList` throws) → logged error, side effect lost for that
  chunk. Same blast radius as today's swallowed listener exceptions; the
  kill switch restores inline behaviour if an instance's job system is
  unhealthy.
- Captured user deleted before run → job logs + skips (never runs as wrong
  identity; matches "user gone" semantics elsewhere, e.g. ScheduledReport).
- Cron disabled/stalled → effects delayed indefinitely; mitigations: the
  `listenerDeferral=inline` kill switch and standard NC cron monitoring.
- Job crash mid-chunk → whole chunk re-runs next pass (at-least-once);
  per-entry effects idempotent per D3.
