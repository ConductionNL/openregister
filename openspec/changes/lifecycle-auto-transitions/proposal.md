---
kind: code
depends_on:
  - lifecycle-declarative-conditions
chain:
  - lifecycle-declarative-conditions
  - lifecycle-auto-transitions     # this spec
  - lifecycle-graph-enforcement
---

## Why

A lifecycle can refuse a transition declaratively since `lifecycle-declarative-conditions`, but it still
cannot make one. "Once the motivation is filled in, the case is decided" and "a received request with
every document attached moves to review" are rules about the object's own data, yet today each needs a
PHP listener or a flow per app. ADR-031 lists exactly that as the anti-pattern: a custom state machine
written as code because the schema engine offers no declarative way to say it. This change is the second
link of the chain. It reuses the first link's JSONLogic vocabulary and data document, which is why it
comes second.

## What changes

- **New optional `autoWhen` key on a lifecycle transition.** Its value is a JSONLogic rule object,
  evaluated against the same four-key document a `condition` reads (`object`, `previous`, `user`,
  `transition`). After an object is written, OpenRegister looks for a transition whose `from` contains
  the object's current lifecycle value and whose `autoWhen` holds, and fires it.
- **An automatic transition is a named transition.** It is applied through the same engine as
  `POST /api/objects/{id}/transition`, so `authorization`, `condition`, `requires`, the approval-chain
  gate and `actions[]` all apply. An automatic move can never bypass what a manual one obeys. A refusal
  by any of those gates is logged and leaves the object where it is.
- **New optional `executionMode` key, per transition.** `sync` (the default) applies the move in the same
  request, after the triggering write is complete, so the response already carries the new state.
  `async` queues it and applies it off-request. The two values are the flow engine's existing
  `Flow::MODE_SYNC` and `Flow::MODE_ASYNC` constants, not new strings. This makes the automatic
  transition another place that decides sync versus async, a cost the user accepted knowingly. Binding
  to the existing constants is the mitigation: the vocabulary has one definition.
- **A sync move never unwinds the triggering write.** It runs after that write has finished, including
  its audit row and any follow-up write of the same save, and its failures are caught and logged. The
  triggering request still succeeds.
- **A loop cap.** An automatic write fires the post-save event again, so A to B to A ping-pong or a long
  chain is bounded: at most 10 automatic moves per object per pass, and a move back into a state the pass
  already visited is never made. A breach logs at error level with the chain it cut. The cap is a
  constant, not configurable, and the count travels with a queued `async` move so a loop cannot escape
  it by crossing into a background job.
- **Ambiguity is refused, not resolved by order.** When more than one automatic transition holds from
  the same state, none fires and a warning names them all. Declaration order is not stable across the
  databases Nextcloud supports, so "first declared wins" would pick differently per installation.
- **The move acts as the caller.** A sync move runs under the identity whose write triggered it. An
  async move carries that identity into the job and refuses to run as a removed or disabled account. It
  never runs as a system principal, because `autoWhen` is author-supplied input (ADR-099).
- **Automatic moves are marked.** The transition event and the audit row say the move was automatic, so
  an auditor can tell a user's click from a rule firing on that user's save.
- **Schema-save validation that refuses the save.** A malformed `autoWhen`, an unknown `executionMode`,
  an `autoWhen` on a transition with a required input (it could never fire), and an `autoWhen` on a
  graph-mode block are all refused. These join the condition errors in the bucket that refuses the save
  rather than the advisory one, for the reason given in design.md.
- **Graph mode is refused, like the first link.** Graph-mode moves are unenforced on the ordinary save
  path, so an automatic graph move would inherit that gap. `lifecycle-graph-enforcement`, the third link,
  owns it.
- Fully additive. A transition without `autoWhen` behaves exactly as before.

## Capabilities

### New capabilities
<!-- none, this extends an existing capability -->

### Modified capabilities
- `object-lifecycle`: a lifecycle transition MAY declare `autoWhen` and `executionMode`. OpenRegister
  fires a matching transition after a write, through the named-transition engine, bounded by a loop cap,
  and refuses malformed or unsupported declarations at schema-save time.

## Impact

- `lib/Service/Lifecycle/LifecycleConditionEvaluator.php`: gains a public `holds()` that `refusal()` now
  calls, so `autoWhen` and `condition` are evaluated by one method against one document, with the same
  scalar guard and the same fail-closed `FlowExpression` call.
- `lib/Service/Lifecycle/`: a new selector that picks the candidate transition by calling `holds()`, and
  a request-scoped pass that applies sync moves at the outermost write boundary and enforces the cap.
- `lib/Listener/`: a new post-save listener on `ObjectCreatedEvent` and `ObjectUpdatedEvent` that only
  records the written object. It never writes, which keeps it clean of the listener-placement gate.
- `lib/Service/ObjectService.php` and `lib/Service/Lifecycle/TransitionEngine.php`: each opens the pass
  boundary, so the outermost one drains it and returns the object in its final state.
- `lib/BackgroundJob/`: an `ActorForwardedJob` subclass for `async` moves, enqueued through
  `ListenerDeferralService`.
- `lib/Event/ObjectTransitionedEvent.php`: an additive `automatic` flag, defaulting to false.
- `lib/Service/Lifecycle/LifecycleAnnotationValidator.php` and `lib/Db/SchemaMapper.php`: four new
  schema-save error codes, all in the bucket that refuses the save beside the two condition codes.
- `tests/e2e/workflows/lifecycle-auto-transitions.spec.ts`, admitted to CI by name in the `testMatch`
  allow-list of `tests/e2e/ci/playwright.config.ts`; without that entry CI never runs it.
- API surface: the response of an object save or a named transition can now carry a lifecycle value the
  caller did not send, because a rule moved it. No route, signature or database change.
- Consuming apps: no change required. A leaf app can retire a listener or flow that exists only to
  advance a status, which is ADR-031's opportunistic migration, not a task here.
- `depends_on: lifecycle-declarative-conditions`: this change reuses that change's
  `LifecycleConditionEvaluator` and its blocking-error bucket in `SchemaMapper`.
- No new dependency: `jwadhams/json-logic-php` is already required.
