---
kind: code
---

## Why

`actor-forwarded-listener-jobs` built the deferral contract
(`ListenerDeferralService`, `DeferredListenerContext`,
`DeferredEntryObjectResolver`, `ActorForwardedJob`) and used it on three
listeners. It works: those three now measure **0.0–0.4 ms warm**. But adoption
stopped there. OpenRegister registers **51 of the fleet's 149 object lifecycle
listener registrations** across **21 distinct listener classes**, and 18 of
those classes still run their full handler body inside the caller's request.

`mm:EVENT-DISPATCH` is now the largest single remaining cost in an object
write — 147–248 ms historically, re-measured 2026-07-30 at n=32 (host load
3.2): min 42, median 133, p95 175, max 206 ms — against a write that was
already brought from 13,688 ms to ~250 ms.

Two measurements taken 2026-07-30 tell us exactly where to aim:

- **Constructing all 21 listener classes costs < 1 ms in total** (0.31 ms cold,
  worst ordering). The cost is not "too many listeners registered". It is
  handler *bodies*.
- **Handler bodies on one `ObjectCreatedEvent`, warm best-of-3, total 51.15 ms**,
  of which `FlowTriggerListener` alone is **42.64 ms** — 83 % of it. Next:
  `ObjectChangeListener` 3.82, `ObjectMetricsListener` 2.30,
  `WebhookEventListener` 1.08, `ActionListener` 0.84,
  `TranslationProjectionListener` 0.38, `GraphQLSubscriptionListener` 0.07,
  `AnnotationNotificationListener` 0.01; the remaining 13 are ~0.00.

The `FlowTriggerListener` number is not the listener. Root cause:
`FlowTriggerService::fire()` → `FlowResolverRegistry::flowsForTrigger()` →
`OpenRegisterFlowResolver::flowsForTrigger()`
(`lib/Service/Flow/OpenRegisterFlowResolver.php:181`) performs an
**unfiltered, unlimited, fully rendered `ObjectService::findAll()` of every
flow object on every object write**, then filters in PHP by trigger, register
and schema. 144 ms cold / 8–9 ms warm — **to return one flow**. Deferring the
listener would move that cost to cron rather than remove it; the correct fix is
filtered resolution.

And nothing enforces any of this. The `*ing` / `*ed` suffix already encodes the
rule — pre-events implement `StoppableEventInterface` and mutate via
`setObject()`, post-events expose no mutation and no veto API at all — so a
`stopPropagation()` inside a post-event listener is dead code. The next
listener anyone adds can register a synchronous outbound HTTP call on
`ObjectCreatedEvent` and every gate stays green.

## What Changes

- **Six DEFER-SAFE post-event listeners move onto the existing contract.** No
  new mechanism: each gets an `ActorForwardedJob` subclass, a cheap inline
  interest gate, and the `listenerDeferral=inline` kill-switch fallback that
  `actor-forwarded-listener-jobs` already established.
  - `ObjectCleanupListener` (Deleted) — the best candidate. Six cleanups
    including a **full CalDAV calendar scan** (`TaskService::getTasksForObject`
    walks the entire calendar), calendar unlink, vCard rewrite, deck/email/
    comment deletes. Critically, every one of the six private cleanups takes
    **only `$objectUuid`** (`handle()` L160), so the "hard-deleted row is gone"
    blocker that stops other delete-path deferrals does not apply here. All six
    services read `userSession->getUser()` — already solved by actor forwarding.
  - `HookListener` — **only** its Created/Updated/Deleted registrations
    (`Application.php:2627-2629`). Outbound HTTP via
    `HookExecutor::executeHooks()` → `adapter->executeWorkflow(timeout: 30)`,
    synchronous by default. `getObjectFromEvent()` already handles both halves,
    so this is a registration-level split, not a rewrite. The Deleted case
    carries the serialized object in the entry payload.
  - `FlowActionListener` (C/U/D) — mail (`IMailer`), CalDAV event creation,
    federation share, agent dispatch. `CalendarEventService` uses
    `IUserSession->getUser()`; actor forwarding covers it. Delete needs payload.
  - `ContextChatSubmissionListener` (C/U/D) — with a caveat:
    `allSeenUserIds()` walks every seen user on the instance as a broadcast
    audience fallback. Under at-least-once delivery a deferred `deleteContent`
    for uuid X can land **after** X was re-created, so the job MUST check
    existence before removing.
  - `ActionListener` (C/U/D) — outbound HTTP with a per-action timeout; today
    only *failure* retries (`ActionRetryJob`), so the first attempt is
    synchronous. Delete needs payload.
  - `SourceRecordChangeListener` (C/U/D) — deferrable **only under a strict
    payload contract**, see design D3. Two things are not re-fetchable: the
    Updated path needs `$event->getOldObject()` so a source re-parented to a
    different master refreshes **both** masters, and the Deleted path reads
    `$data[$referenceField]` off a hard-deleted row. The entry therefore carries
    the **resolved master UUIDs (new + old)**, not the source uuid. Dedupe key =
    masterUuid, collapsing a bulk source edit into one recompute.
- **`OpenRegisterFlowResolver::flowsForTrigger()` is fixed at the query, not
  deferred.** `FlowTriggerService::runInline()`
  (`FlowTriggerService.php:114`) deliberately executes flows declaring
  `executionMode: sync` inside the triggering request — "its effects are done
  before the caller's save returns" is a **published contract**. That must not
  move into a job. What must change is resolution: filter by trigger, register
  and schema at query time, apply a bound, skip rendering, and cache the
  resolved set per (trigger, register, schema) for the request.
- **`WebhookService::dispatchEvent()` stops paying serialization when there are
  no webhooks.** `extractPayload()` runs and `jsonSerialize()`s the entity
  *before* the zero-webhook guard (`WebhookService.php:660`). Move the guard
  first.
- **The classification is recorded in `tasks.md`** — all 21 classes, pre vs
  post, deferred vs blocked **with the blocking reason** — so the decision is
  auditable rather than folklore, following the `design.md` D4 table format of
  `actor-forwarded-listener-jobs`.
- **`@listener-placement` annotations** on every post-event handler that stays
  inline, naming one of the four ADR-078 categories, so hydra gate 61
  (`listener-work-placement`) passes green on OpenRegister with justified,
  readable exclusions rather than a blanket suppression.

Explicitly NOT changed: the five pre-event listeners
(`LifecycleInitialStateListener`, `CalculationOnSaveListener`,
`QualityScoreOnSaveListener`, `SurvivorshipRecomputeListener`, and
`HookListener`'s Creating/Updating/Deleting registrations). They mutate via
`setObject()` or can veto, and `CalculationOnSaveListener` **consumes a
sequence number on create**, so it must not run twice. Also unchanged:
`NotifyPushListener`, `GraphQLSubscriptionListener`,
`AggregationCacheInvalidationListener`, `ObjectMetricsListener`,
`NotificationDedupePruneListener` — each blocked for a named reason in design
D4.

## Capabilities

### Modified Capabilities
- `event-driven-architecture`: adds the normative sync/async placement rule for
  OpenRegister's own object lifecycle listeners — the closed synchronous
  exception categories, the payload contract for delete-path and old-state
  deferral, filtered trigger resolution, and the requirement that the
  classification be recorded and mechanically checked.

## Impact

- Affected code: `lib/Listener/ObjectCleanupListener.php`, `HookListener.php`,
  `FlowActionListener.php`, `ContextChatSubmissionListener.php`,
  `ActionListener.php`, `SourceRecordChangeListener.php`;
  `lib/AppInfo/Application.php` (registration split for `HookListener`,
  L2624-2629); six new `ActorForwardedJob` subclasses under
  `lib/BackgroundJob/`; `lib/Service/Flow/OpenRegisterFlowResolver.php`;
  `lib/Service/WebhookService.php` (guard ordering).
- Request-path effect: the six deferred listeners leave the synchronous write
  path, replaced by an interest gate plus a buffered entry append (one
  `oc_jobs` INSERT per chunk). Filtered flow resolution removes the dominant
  42.64 ms warm item outright rather than relocating it.
- Behavioural: those six side effects become eventually consistent (next cron
  run). Delivery is at-least-once; every job re-resolves and no-ops on a gone
  or soft-deleted object. `occ config:app:set openregister listenerDeferral
  --value inline` restores the pre-change synchronous behaviour instance-wide.
- Consumers: opencatalogi and softwarecatalog exercise object writes heavily;
  both must be regression-checked, particularly hook-driven workflows
  (`HookListener`) and source-record master recompute.
- Security: `SourceRecordChangeListener`'s recompute calls
  `find(_rbac: true, _multitenancy: true)`. In a job that runs under the
  **current** organisation authority of the forwarded user, not the captured
  authority — for a cross-organisation master this can resolve differently than
  it did inline. Called out as an accepted, logged posture (design D3), not
  papered over.
