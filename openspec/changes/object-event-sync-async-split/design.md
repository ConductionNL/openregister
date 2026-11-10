# Design — object-event-sync-async-split (openregister)

## Context

`actor-forwarded-listener-jobs` (17/17 tasks implemented, not yet archived)
supplies everything this change needs: `ListenerDeferralService` (actor
capture, per-job-class entry buffers, chunk + shutdown flush, per-entry dedupe
keys, `listenerDeferral` kill switch), `DeferredListenerContext`,
`DeferredEntryObjectResolver` (stale-safe re-fetch) and the abstract
`ActorForwardedJob` (impersonate, `finally` restore, skip-on-unresolvable-user).
**This change designs no new mechanism.** Its D4 table is the precedent format
for the verdict table below.

The rule this change enforces is written fleet-wide in
`hydra/openspec/changes/object-event-sync-async-split/` (ADR-078). This
document is the OpenRegister-local application of it.

### Measured today (2026-07-30, development instance)

- `mm:EVENT-DISPATCH` per object write: n=32, min 42 / median 133 / p95 175 /
  max 206 ms (147–248 ms historically), against a ~250 ms write.
- **DI construction of all 21 listener classes: < 1 ms total** (0.31 ms cold,
  worst ordering). Construction is not the cost.
- **Handler bodies, one `ObjectCreatedEvent`, warm best-of-3, total 51.15 ms**:
  FlowTrigger 42.64, ObjectChange 3.82, ObjectMetrics 2.30, WebhookEvent 1.08,
  Action 0.84, TranslationProjection 0.38, GraphQLSubscription 0.07,
  AnnotationNotification 0.01, all others ~0.00.
- Cold first-call-in-process, **each measured in its own process so shared
  one-time costs are counted repeatedly — an upper bound, NOT a sum**:
  SourceRecordChange 299, FlowTrigger 172, WebhookEvent 22.7,
  TranslationProjection 13.9, AnnotationNotification 12.4, ObjectChange 7.0,
  AggregationThreshold 6.5, Hook 5.8, ObjectMetrics 3.5, Action 3.1,
  FlowAction 2.5, GraphQLSubscription 0.6, ContextChatSubmission 0.6,
  AggregationCacheInvalidation 0.4, NotifyPush 0.2 ms.
- The three listeners deferred by `actor-forwarded-listener-jobs` now measure
  **0.0–0.4 ms warm**, confirming the contract works.

### Two structural facts that shape every verdict

1. **Post-events have no mutation or veto surface.** `ObjectCreatedEvent`
   exposes only `getObject()`; `ObjectUpdatedEvent` adds `getNewObject()` /
   `getOldObject()`. Neither implements `StoppableEventInterface`. A
   `stopPropagation()` or `setModifiedData()` call in a post-event listener is
   dead code. A grep for `stopPropagation` / `setErrors` / `throw` across the
   post-event listeners returned **zero** hits — no post-event listener is
   secretly acting as a veto.
2. **`ObjectDeletedEvent` fires after a HARD delete.** `MagicMapper.php:8721`
   dispatches it following `deleteObjectEntity(hardDelete: true)`, so the row is
   gone and **nothing on the delete path can be re-fetched**. Every delete-path
   deferral therefore needs an explicit payload contract, or is blocked.

## Goals / Non-Goals

**Goals**

- Take the six DEFER-SAFE post-event listeners off the synchronous write path
  using the existing contract.
- Remove — not relocate — the dominant `FlowTriggerListener` cost by fixing
  trigger resolution.
- Record the full 21-class classification where it can be audited (tasks.md).
- Make a future undeclared synchronous post-event listener fail a build.

**Non-Goals**

- Redesigning or extending `ListenerDeferralService` / `ActorForwardedJob`.
- Deferring `FlowTriggerService::runInline()` — an explicitly published
  synchronous contract (see D2).
- Converting any leaf app's listeners.
- Changing pre-event (`*ing`) listener behaviour in any way.

## Decisions

### D1. Deferral verdicts come from a named blocker, never from a cost threshold

`GraphQLSubscriptionListener` costs 0.07 ms warm and is **undeferrable**;
`ObjectCleanupListener` is heavy and is the easiest deferral in the set. Cost
does not predict deferrability. Each verdict below therefore names either the
mechanism that makes deferral safe, or the specific fact that blocks it.

### D2. `FlowTriggerListener`: fix resolution, do not defer execution

The listener is a thin shim, and `FlowRunService::queue()` already defers flow
*execution* to `FlowRunWorker`. The 42.64 ms is trigger **resolution**:
`OpenRegisterFlowResolver::flowsForTrigger()`
(`lib/Service/Flow/OpenRegisterFlowResolver.php:181`) does an unfiltered,
unlimited, fully rendered `ObjectService::findAll()` over every flow object on
every write, then filters in PHP — 144 ms cold / 8–9 ms warm, returning 1 flow.

Deferring the listener would move that into cron, where it would still run once
per write. The fix is at the query: constrain by trigger, register and schema,
apply a bound, disable rendering, and memoize the resolved set per (trigger,
register, schema) for the request.

`FlowTriggerService::runInline()` (`FlowTriggerService.php:114`) must stay
inline. It deliberately executes flows declaring `executionMode: sync` inside
the triggering request — the guarantee that "its effects are done before the
caller's save returns" is a published contract. Pushing it into a job would
break every consumer relying on read-after-write. This is a `realtime`-category
exception under ADR-078 and is annotated as such.

Alternatives considered: (a) defer resolution and execution wholesale —
rejected, breaks the `executionMode: sync` contract; (b) cache the full flow
list instance-wide — rejected, invalidation on flow edits is a new correctness
problem for no additional benefit over a filtered query.

### D3. `SourceRecordChangeListener`: deferrable only under a resolved-master payload contract

Heaviest inline work in the whole set: a reverse-FK index build via `findAll`
over every schema, then per-master `ObjectService::find()` and a **full re-save
(`saveObject`) — a recursive write cascade**.

Two values are not re-fetchable at job time:

1. **Updated path** needs `$event->getOldObject()`. If a source record was
   re-parented to a different master, **both** masters must recompute. The old
   parent is gone from current state.
2. **Deleted path** reads `$data[$referenceField]` off a row that
   `MagicMapper.php:8721` has already hard-deleted.

Therefore the deferred entry carries the **resolved master UUIDs (new and
old)**, not the source uuid — resolution happens inline, where the data still
exists, and only the recompute is deferred. Dedupe key = `masterUuid`, so a
bulk edit of 500 source records collapses to one recompute per master.

**Security posture, called out rather than hidden:** the recompute calls
`find(_rbac: true, _multitenancy: true)`. Per the `ActorForwardedJob` contract,
the job re-derives organisation from the restored user's *current* persistent
config and never resurrects captured authority. For a cross-organisation master
this can resolve differently than the inline run did. That is the deliberate
posture inherited from `actor-forwarded-listener-jobs` ("re-establish identity,
never re-establish stale authority"); the drift is logged and the job proceeds
under current authority. If a recompute resolves nothing under current
authority, it no-ops rather than partially writing.

### D4. Per-listener classification — 21 classes, 51 registrations

**PRE-EVENT — MUST STAY SYNCHRONOUS.** All five mutate via `setObject()` or can
veto. None can ever be deferred.

| Listener | Events | Why it must stay inline |
|---|---|---|
| `LifecycleInitialStateListener` | Creating | `setObject()` seeds lifecycle state before persistence |
| `CalculationOnSaveListener` | Creating, Updating | `setObject()` materialises computed fields; **consumes a sequence number on create**, so it must not run twice |
| `QualityScoreOnSaveListener` | Creating, Updating | `setObject()` patches the quality score pre-persist |
| `SurvivorshipRecomputeListener` | Creating, Updating | `setObject()` patches `goldenRecordField` / `provenanceField` |
| `HookListener` | Creating, Updating, Deleting (`Application.php:2624-2626`) | `HookExecutor::applyFailureMode` can **veto the save** here |

**POST-EVENT — deferred by this change.**

| Listener | Events | Mechanism / payload contract |
|---|---|---|
| `ObjectCleanupListener` | Deleted | Best candidate. 6 cleanups incl. a full CalDAV calendar scan (`TaskService::getTasksForObject` walks the entire calendar), calendar unlink, vCard rewrite, deck/email/comment deletes. **Every one of the six private cleanups takes only `$objectUuid`** (`handle()` L160) → the hard-delete blocker does not apply. All six read `userSession->getUser()` → solved by actor forwarding. |
| `HookListener` | Created, Updated, Deleted (`Application.php:2627-2629`) | Outbound HTTP: `HookExecutor::executeHooks()` → `adapter->executeWorkflow(timeout: 30)`, sync by default. `getObjectFromEvent()` already handles both halves → registration-level split, not a rewrite. Deleted entry carries the serialized object. |
| `FlowActionListener` | C/U/D | Mail (`IMailer`), CalDAV event create, federation share, agent dispatch. `CalendarEventService` uses `IUserSession->getUser()` → actor forwarding. Deleted entry carries payload. |
| `ContextChatSubmissionListener` | C/U/D | `allSeenUserIds()` walks every seen user as a broadcast fallback. **Caveat:** at-least-once means a deferred `deleteContent` for uuid `00000000-0000-0000-0000-000000000000` can land after that uuid was re-created → the job MUST check existence before removing. |
| `ActionListener` | C/U/D | Outbound HTTP with per-action timeout; today only *failure* retries via `ActionRetryJob`, so the first attempt is synchronous. Deleted entry carries payload. |
| `SourceRecordChangeListener` | C/U/D | Deferrable **only** under D3's resolved-master payload contract (new + old master UUIDs resolved inline). Dedupe key = masterUuid. RBAC-authority caveat per D3. |

**POST-EVENT — blocked, with the blocking reason.**

| Listener | Events | Verdict | Blocking reason |
|---|---|---|---|
| `FlowTriggerListener` | C/U/D | **fix, don't defer** | Execution already deferred to `FlowRunWorker`; the cost is trigger resolution (D2). `runInline()` is a published synchronous contract. ADR-078 category `realtime`. |
| `NotifyPushListener` | C/U/D | keep inline — latency | It **is** the realtime channel. Also holds per-request static state (`self::$seen`, `$batchMode`, `$batchedCollections`) whose semantics assume one request lifetime. Category `realtime`. |
| `GraphQLSubscriptionListener` | C/U/D | keep inline — **hard technical blocker** | `SubscriptionService::pushEvent` stores via `apcu_store` (`SubscriptionService.php:122`). APCu is **per-SAPI**: a cron/CLI worker writes a different segment, so SSE readers on the web SAPI would never observe the event. Deferring does not delay this listener, it **silently breaks** it. Category `sapi-memory`. This is the fleet's cautionary example. |
| `AggregationCacheInvalidationListener` | C/U/D | keep inline — cheap + latency | 1 cache get + 1 set (version bump). A read later in the same request would see stale aggregations; deferring turns a bounded 60 s staleness into cron-interval staleness. Category `cheap-bounded`. |
| `ObjectMetricsListener` | C/U/D | keep inline — cheap | A single fail-soft INSERT. Enqueuing costs a job-list INSERT plus a cron round trip to save one INSERT — strictly worse — and would blur the metric timestamp. Category `cheap-bounded`. |
| `NotificationDedupePruneListener` | Deleted | keep inline — **correctness hazard** | The prune exists so a re-created object with the same UUID re-arms cleanly. A prune landing after a same-uuid re-create would wipe freshly-armed state. Category `correctness`. |
| `WebhookEventListener` | C/U/D | already async | Enqueues `WebhookDeliveryJob` per webhook, per ADR-009 Rule 5. **One micro-inefficiency to fix:** `extractPayload()` runs and `jsonSerialize()`s the entity **before** `dispatchEvent`'s zero-webhook guard (`WebhookService.php:660`), so every write pays serialization even with no webhooks configured. |
| `ObjectChangeListener` | C/U | already async | Enqueues `ObjectTextExtractionJob`. Residual sync path is the admin opt-in `extractionMode === 'immediate'` — left as-is, it is an explicit admin choice. |
| `TranslationProjectionListener` | C/U/Transitioned | already deferred | `actor-forwarded-listener-jobs`. Deleted branch stays inline by design. |
| `AnnotationNotificationListener` | C/U/Transitioned | already deferred | `actor-forwarded-listener-jobs`. |
| `AggregationThresholdListener` | C/U/Transitioned | already deferred | `actor-forwarded-listener-jobs`. Deleted branch stays inline by design. |

### D5. Enforcement — annotations plus a build-failing gate

Hydra gate 61 (`listener-work-placement`, added by the hydra sibling change)
fails a PR that registers a listener on a `*ed` post-event whose handler reaches
outbound I/O, a write, or an unbounded query without either routing through
`ListenerDeferralService` or carrying:

```php
/** @listener-placement inline sapi-memory — apcu_store is per-SAPI; a cron worker writes a different segment than the SSE readers */
```

OpenRegister's obligation in this change is to annotate every post-event
handler that stays inline with one of the four ADR-078 categories
(`realtime` / `sapi-memory` / `cheap-bounded` / `correctness`) and free text,
so the gate passes green on OpenRegister with **justified, readable** exclusions
rather than a blanket suppression. Verification is by exit code: a deliberately
violating listener must make `run-hydra-gates.sh` exit non-zero. A FAIL line on
stdout is not evidence.

### D6. Filtered subscription is declared at the registration site

This resolves **Open Question 2 of the hydra sibling change**
(`object-event-sync-async-split`, design.md), which asked where a listener's
register/schema interest is declared and deferred the answer to OpenRegister,
which owns the dispatcher.

**Decision: at the registration site, as named arguments on a helper that
replaces `$context->registerEventListener()`.**

```php
\OCA\OpenRegister\Event\ObjectEventSubscription::register(
    context:   $context,
    event:     ObjectCreatedEvent::class,
    listener:  BezwaarLifecycleListener::class,
    registers: ['procest'],
    schemas:   ['bezwaar', 'objection', 'hearingSession'],
);
```

The three options were the registration-site argument, a manifest block, and a
PHP attribute on the listener class. The registration site wins on four
grounds, in order of weight:

1. **The declaration must be readable without loading the class.** The whole
   point is to avoid constructing an uninterested listener. A class attribute
   is only readable via reflection, and reflecting a class requires autoloading
   it — which pulls in exactly the file the mechanism exists to avoid touching.
   Reflection on 84 listeners per request would also cost more than the ~1.4 ms
   of handler bodies it saves. The attribute option is not merely less tidy; it
   is self-defeating.
2. **Interest is per registration, not per class.** A listener is routinely
   registered on several events, and its interest can differ between them —
   `HookListener` is the in-tree example, registered on both the `*ing` and
   `*ed` halves with different obligations. A class-level attribute can only
   state one interest for all of them.
3. **It keeps one source of truth next to the thing it modifies.** A manifest
   block would put the event name in `Application.php` and the filter in JSON,
   so a listener could be re-registered on a new event and silently keep a
   stale filter. Gate 61 already reads the registration site to find the event
   name; the filter being on the same line is what makes it mechanically
   checkable. The fleet has been burned by exactly this split before — a
   manifest `widgetKey` that referred to nothing.
4. **It degrades to today's behaviour by omission.** `registers`/`schemas`
   default to `null`, meaning "all". A leaf that adopts the helper without
   declaring anything behaves precisely as its global registration did, so the
   migration is opt-in per listener and reversible per line.

Rejected specifically: **a manifest block**, because the leaf apps that would
carry it (`procest`, `pipelinq`, …) declare listeners in PHP and would gain a
second, non-co-located place to get out of sync; and **a class attribute**, for
reason 1 above.

**Dispatcher shape.** One shared `ObjectEventProxyListener` is registered per
event class, idempotently, by the first `register()` call in the process. It
resolves the written object's register/schema ids off the `ObjectEntity` once
per dispatch and invokes only the matching subscriptions. It resolves listeners
from the **server** container, which is not a compromise but an exact match for
what Nextcloud already does — `EventDispatcher::addServiceListener()` hands its
own container to `ServiceEventListener` regardless of which app registered the
listener (`ServiceEventListener.php` even carries a TODO saying so).

**Slug↔id is the load-bearing cost.** Apps declare slugs; `ObjectEntity`
carries ids. Neither `oc_openregister_registers` nor `oc_openregister_schemas`
has an index on `slug`, so each resolution is a sequential scan — measured
1,137 us for the two of them on an instance with 1,931 schemas. Resolving per
dispatch would make the filter cost more than the handlers it skips, i.e. a net
loss. It is therefore resolved **once per request** for every slug declared by
every app in one bounded `IN` query per table, and that result is additionally
held in the local (APCu) cache for 60 s. Warm, the whole filter costs ~40–52 us
of map read plus ~49–80 us per dispatch. The 60 s TTL is the price: a schema
created in the last minute is matched one cache generation late. Registers and
schemas are configuration, not traffic, so that trade is deliberate.

**What changes about dispatch semantics, stated rather than hidden.**
Subscriptions run in declaration order among themselves but occupy the proxy's
single slot in the dispatcher rather than their original positions, so relative
order against *other* apps' listeners changes. That is safe for the `*ed`
post-events this mechanism targets, which expose neither `setObject()` nor a
veto, so no downstream listener can observe the reordering — and it is why the
helper deliberately exposes no `priority` parameter. Exceptions thrown by a
handler propagate, exactly as Symfony's dispatcher already lets them; an
unresolvable listener is logged and skipped, exactly as
`ServiceEventListener`'s `QueryException` branch does.

**Kill switch.** `occ config:app:set openregister objectEventFilter --value
off` makes the proxy invoke every subscription unconditionally, which is
byte-for-byte today's behaviour and is also the A/B knob. Note that the value
is read through NC's app config, whose local cache is APCu and therefore
**per-SAPI**: a value written by `occ` is not visible to the web workers
immediately. Any measurement that toggles it must poll for the flag to actually
have flipped rather than assume it.

## Risks / Trade-offs

- **Deferred delete-path work vs re-created UUIDs.** → Every deferred delete
  handler re-checks existence via `DeferredEntryObjectResolver` before acting;
  `ContextChatSubmissionListener`'s `deleteContent` is the named instance.
- **`HookListener` split across two registration groups is easy to get wrong.**
  A future edit could re-add a post-event to the inline group and silently undo
  this change. → The split lives in `Application.php:2624-2629` with a comment
  naming ADR-078, and gate 61 catches the re-add.
- **`SourceRecordChangeListener` recompute is a recursive write cascade.**
  Deferring it means the cascade runs under cron with at-least-once delivery. →
  masterUuid dedupe collapses repeats; the recompute is a reconciliation against
  current state, so re-running converges.
- **Cross-organisation authority drift on the recompute (D3).** → Logged,
  proceeds under current authority, no-ops rather than partially writing.
- **Fixing `flowsForTrigger()` could change which flows fire** if the PHP-side
  filter and the new query-side filter disagree. → Land the filtered query
  behind a parity assertion in tests: for a fixture set, the filtered result
  must equal the previous PHP-filtered result.
- **Cron unhealthy → six side effects stall.** → `occ config:app:set
  openregister listenerDeferral --value inline` restores synchronous behaviour,
  as established by `actor-forwarded-listener-jobs`.

## Migration Plan

1. Filtered `flowsForTrigger()` + the `WebhookService` guard reorder first —
   pure wins, no behavioural deferral, immediately measurable.
2. `ObjectCleanupListener` next — highest benefit, no payload contract needed.
3. `HookListener` registration split, then `FlowActionListener`, `ActionListener`,
   `ContextChatSubmissionListener`.
4. `SourceRecordChangeListener` last — it carries the payload contract and the
   authority caveat.
5. Annotate the six inline-by-exception listeners; run gate 61.

Rollback: `listenerDeferral=inline` restores synchronous behaviour for every
deferred listener without a deploy. The `flowsForTrigger()` fix is not covered
by that switch and is reverted by code if the parity assertion ever fails in
production.

## Open Questions

- Whether the `@listener-placement` annotation belongs on the handler method or
  on the registration site in `Application.php`. Handler method is proposed
  (it is where the evidence lives); the registration site is where the gate
  finds the event name.
- Whether `ObjectChangeListener`'s `extractionMode === 'immediate'` admin
  opt-in should be removed outright rather than left as a documented sync path.
