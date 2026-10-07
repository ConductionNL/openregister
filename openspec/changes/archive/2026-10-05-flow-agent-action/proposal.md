---
kind: code
depends_on: []
---

## Why

Spectr's "classify tender" / "requirement extraction" / "feature mapping" stages
(SPECTR-NEXTCLOUD-PLAN.md §5.3) all need a declarative object-lifecycle flow to
trigger an LLM agent turn — the same shape as today's `calendar-event`/`email`
flow actions, but landing in Hermiq's governed agent runtime instead of a
Nextcloud built-in surface. Today `x-openregister-flows` only knows two action
types (`FlowActionService::runAction()`), and there is no sanctioned way for a
declarative flow to reach an agent runtime: OpenRegister must not call Hermiq
directly (gate-27, `no-phantom-cross-app-rpc`) and must not grow its own inline
LLM-invocation code path for this (v1's "OR runs an Agent inline" design was
explicitly retired in favour of the Hermiq re-platform, plan §5.2).

This change adds the `type: "agent"` flow action: a schema author declares the
same trigger/action shape they already use for `calendar-event`/`email`, and
`FlowActionService` dispatches a typed `AgentRunRequestedEvent` (ADR-041's
cross-app command recipe) instead of invoking a runtime inline. A consuming app
(Hermiq, in a companion change) registers an `IEventListener` for this event and
performs the actual governed run through its own services.

## What Changes

- Add a new action `type: "agent"` to the `x-openregister-flows` vocabulary
  handled by `FlowActionService::runAction()` — config keys: `agent` (required,
  agent UUID), `skill` (optional slug), `prompt` (required, `{{field}}`-templated
  like the existing actions), `resultField` (required — the object field the
  agent's output is written to by the consumer), `requiresApproval` (optional
  bool, default `false`), `mode` (optional, default `"async"` — the only
  supported value in v1; any other value is treated as malformed config).
- Add `lib/Event/AgentRunRequestedEvent.php` — a new `OCP\EventDispatcher\Event`
  carrying the triggering object's `subjectUuid`/`subjectRegister`/
  `subjectSchema`, the `agent`/`skill` refs, the fully-rendered `prompt`, the
  `resultField`, `requiresApproval`, `mode`, the owning flow's `flowName`, and a
  generated `correlationId`. `getPayload()` flattens the event to a
  JSON-serialisable array for a consumer that hands the request off to a
  background job.
- Inject `IEventDispatcher` into `FlowActionService` and dispatch the event via
  `dispatchTyped()`. OpenRegister never calls the consuming app directly — if no
  listener is installed the dispatch is a silent no-op (existing objects keep
  flowing; ADR-041 point 1).
- Malformed config (missing `agent`, missing `resultField`, or an unsupported
  `mode`) is logged and the action is skipped — the existing flow semantics
  (a failing/malformed action never corrupts the triggering save) are
  unchanged.

## Capabilities

### New Capabilities

- `flow-actions`: the declarative `x-openregister-flows` action vocabulary that
  `FlowActionService` executes on an object-lifecycle trigger. This is the
  *first* formal spec for the flow engine — the pre-existing `calendar-event`/
  `email` actions ship without one (`FlowActionService::run()` carries a
  `@spec exclude` annotation dating back to their introduction) and stay out of
  scope here (diff-scoped per ADR-020); this change specs only the new `agent`
  action and its event contract.

## Impact

- **New event contract**: `OCA\OpenRegister\Event\AgentRunRequestedEvent` —
  the payload shape a consuming app (Hermiq) must match exactly on the listener
  side. See `design.md` for the full field table.
- **`FlowActionService` constructor gains `IEventDispatcher`** — resolved by
  Nextcloud's DI container automatically; no explicit construction site to
  update (verified: the service is never `new`'d directly in `lib/`).
- **No new endpoints, no schema migration, no new OR public API surface.** The
  action is declared in existing `x-openregister-flows` schema configuration —
  purely additive to the vocabulary an author can already write.
- **Downstream dependent**: Hermiq's `feat/flow-agent-listener` change
  (companion, different repo) implements the listener that turns this event
  into a governed agent run. That change `depends_on` this one.
