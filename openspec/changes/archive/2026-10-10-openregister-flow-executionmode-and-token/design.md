# Design

## Context

The engine already has the substrate both features need; what is missing is a
seam, not machinery.

- `FlowRun` persists `context` as a JSON field (`addType('context','json')`) and
  `FlowRunService::persistResult()` writes `$result['context']` back on **every**
  outcome, including `STATUS_SUSPENDED`. `execute()` restores it with
  `$context = ($run->getContext() ?? [])`. So run-level state already round-trips
  through a suspend.
- `SubFlowNode` already seeds the child with `$childCtx = $context`.
- `FlowTriggerService::fire()` already swallows every failure so a trigger can
  never unwind the host app's save.

The two gaps are narrow: `fire()` has no branch other than `queue()`, and
`dispatch(step, items, context): array` returns items only.

## Goals / Non-Goals

**Goals**

- Let a flow declare that its trigger runs inline, without changing the default.
- Give nodes a run-level value they can both read and write.
- Make that value cross into a sub-flow and come back, and survive a suspend.

**Non-Goals**

- Changing the data channel. Records stay in the item list. The token is for
  run-level values (correlation ids, resolved credentials-by-reference, an
  amended request/response envelope) — putting per-record data in it is the
  same mistake `FlowItems` already warns about, and this change does not make
  it safer.
- Changing the `IFlowNode` interface. Ten nodes and two leaf apps implement it;
  a signature change would break all of them at once (the same reasoning that
  put the item list into #2064 *before* merge).
- A distributed transaction. A `sync` run shares the caller's request, not its
  database transaction; a failed sync flow does not roll back the host's save.

## Decisions

### D1 — The token is a mutable object in `context['token']`, not a return value

Three options were considered for letting a node write run-level state:

| Option | Verdict |
|---|---|
| Change `dispatch`/`IFlowNode` to return `{items, context}` | **Rejected.** Breaks all ten nodes and both leaf apps simultaneously. |
| Nodes smuggle values through the item list | **Rejected.** This is precisely what fan-out/filter destroys; it is the failure `FlowItems` documents. |
| A mutable object handle in `context` | **Chosen.** |

PHP arrays copy by value but objects are handles, so a node receiving
`$context` by value still mutates the single `FlowToken` the engine holds. This
gives write access with **zero** signature churn — no dispatcher change, no
`IFlowNode` change, no node change.

The cost is honest and worth naming: `context` stops being a pure value object.
It is mitigated by scope — exactly one reserved key (`token`) is an object; every
other context entry stays plain data.

### D2 — The token serialises into the existing `context` JSON

No new column and no migration. `FlowRunService::execute()` rehydrates
`context['token']` from array to `FlowToken` before calling the engine;
`persistResult()` serialises it back to an array before `setContext()`. Because
`persistResult()` already runs on the SUSPENDED path, **pause/continue-later
works with no additional code** — that is a consequence of the existing design,
not something this change adds.

Rehydration is defensive: a `context['token']` that is absent, malformed, or
already an object all resolve to a usable `FlowToken` rather than throwing. A
run persisted before this change simply starts with an empty token.

### D3 — Sub-flow: seed a *child* token, merge back only on `wait`

The child gets a token seeded with the parent's values, not the parent's own
instance. Sharing one instance would let a fire-and-forget child mutate a parent
that has already moved on — a race with no ordering guarantee.

On `wait`, the child's token is merged back into the parent's (child values win
on conflict — the child ran later and is the more specific writer). On
fire-and-forget nothing is merged, because there is no completed run to merge
from; the child still receives the seed so it can correlate.

### D4 — `sync` execution reuses the queue record

A `sync` trigger still calls `queue()` and then immediately `execute()`s the
returned run. It does not bypass persistence. This keeps one code path for run
history, retry, pinning and resume — a synchronously-executed run is
indistinguishable from an asynchronously-drained one afterwards, which is what
makes the existing tooling keep working.

A `sync` run that suspends (a `Wait` node inside a `sync` flow) is left
suspended and picked up by `FlowRunWorker` exactly as an async run would be. The
inline call returns; it does not block on a wait.

### D5 — `executionMode` lives on the flow, not the trigger

A trigger fires many flows. Putting the mode on the trigger would force one
choice on all of them; putting it on the flow lets a save fire one inline flow
and three queued ones.

It is carried on the **flow document returned by `resolveFlow()`**, which today
is `{id, nodes, edges, limits}`. Note that `cron` is *not* precedent here:
`FlowScheduleService` reads it straight off the OR flow object
(`scheduleOf(ObjectEntity)`), which only works for the OR-native store. Widening
the resolver document instead means every flow store — OR-native, hermiq's, any
future `IFlowResolver` — carries the mode through one seam, and
`FlowTriggerService` needs no object access it does not already have. A resolver
that omits the key yields `async`, so existing resolvers keep working unchanged.

## Risks / Trade-offs

- **A `sync` flow runs on the user's critical path.** A slow flow slows the save
  that triggered it. Mitigated by it being explicit and opt-in, by `fire()`'s
  existing catch-all, and by suspend still deferring to the worker. Documented
  on the schema field rather than enforced with a timeout, because a timeout
  that fires mid-write would be worse than a slow save.
- **`context` now holds one object.** Anything that assumed `context` was
  JSON-serialisable as-is must go through the serialise step. Confined to
  `FlowRunService`, which is the only place that persists it.
- **Token growth.** A token is persisted on every step boundary; an unbounded
  token means an unbounded JSON column. Left to the writer rather than capped,
  consistent with how `items` is already treated, but called out in the spec.

## Migration Plan

Additive and backwards compatible in one release. `executionMode` absent ⇒
`async`. `context['token']` absent ⇒ empty token. Existing persisted runs resume
normally. The `flow` schema gains `executionMode`; note that a shipped schema
does **not** gain properties on re-import (openregister#2075), so existing
installs need the property added to the live schema — the same footnote that
applied to `cron` in #2126.

## Open Questions

None blocking. Whether the token should be size-capped is deferred until a real
flow demonstrates growth.
