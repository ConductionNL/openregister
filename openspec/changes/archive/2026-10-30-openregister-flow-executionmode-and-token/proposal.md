# Flow execution mode and the flow token

## Why

Two engine gaps block apps from moving their bespoke chaining onto the flow
engine. Both were found while migrating OpenConnector (openconnector#1066,
change `openconnector-flow-migration`), the fifth of the six "flow" systems
ADR-065 converts into consumers.

**1. A trigger can only ever queue.** `FlowTriggerService::fire()` calls
`FlowRunService::queue()` and nothing else, so every event-triggered flow is
drained later by `FlowRunWorker` (60s cadence). That is the right default, but
it is not the only semantics apps need. OpenConnector's object-lifecycle
synchronisation runs *synchronously inside the triggering save* today; migrating
it onto a queue-only engine would silently turn a save-time guarantee into an
eventually-consistent one. An app cannot opt out, so the migration either
changes behaviour or does not happen.

**2. A node cannot write run-level state.** The dispatcher contract is
`dispatch(step, items, context): array` — items come back, `$context` is passed
by value and nothing returns. So a step can read run-level metadata but can
never contribute to it. Every app that needs a value to survive *across* steps
has to smuggle it through the item list, which breaks the moment a node fans
out or filters items (the exact failure `FlowItems`' "context is not the data
channel" rule warns about). OpenConnector's existing `FlowToken` helper —
original+amended request/response/syncInput/syncOutput carried through a whole
synchronisation — has no home on the engine at all.

Once a token exists, two further things must hold, because they are what apps
actually do with one:

- **it must cross into a sub-flow, and come back.** `SubFlowNode` already copies
  the parent context into the child (`$childCtx = $context`), but a waited-on
  child's contributions are discarded — the parent gets the child's *items* and
  nothing else. A sub-flow that resolves a value cannot hand it back.
- **it must survive pause and continue-later.** A `Wait` node suspends the run
  for minutes or days; a token that evaporates over a suspend is not a token.

## What Changes

- **`executionMode` on a flow** — `async` (default, today's behaviour) or
  `sync`. `FlowTriggerService::fire()` honours it: a `sync` flow executes inline
  within the triggering call; an `async` flow queues exactly as it does now.
  Failure of a `sync` run is contained the same way `fire()` already contains
  every failure — it never unwinds the host app's save.
- **A flow token** — a mutable, run-level `FlowToken` object reachable at
  `context['token']`. Nodes read and write it *without any change to the
  `IFlowNode` signature*: an object handle survives the by-value array copy, so
  `$context['token']->set(...)` mutates the one instance the engine holds.
- **Token persistence** — the token serialises into the existing `FlowRun`
  `context` JSON on every outcome and rehydrates on resume, so it survives
  suspend/resume with no new column and no migration.
- **Token propagation and return** — a sub-flow is seeded with a child token
  carrying the parent's values; on `wait`, the child's token is merged back into
  the parent's. Fire-and-forget still gets the seed and returns nothing, because
  there is no run to return into.

## Impact

- Affected specs: `flow-execution-mode` (new), `flow-token` (new)
- Affected code: `lib/Service/Flow/FlowTriggerService.php`,
  `lib/Service/Flow/FlowRunService.php`, `lib/Service/Flow/FlowToken.php` (new),
  `lib/Service/Flow/Nodes/SubFlowNode.php`, `lib/Settings/flow_register.json`
- **Backwards compatible.** `executionMode` defaults to `async`; a flow without
  the field behaves exactly as today. The token is additive — no node, no
  dispatcher, and no `IFlowNode` implementation changes signature, so the ten
  registered nodes and every leaf app (hermiq, openconnector) keep working
  untouched.
- Unblocks `openconnector-flow-migration` Phases 2 and 3, and retires the
  app-local `FlowToken` helper OpenConnector carries today.
