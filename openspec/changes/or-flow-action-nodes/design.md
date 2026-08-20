# Design: or-flow-action-nodes

## The lowering

Authored document: nodes `N` each carrying `{id, name?, type, config}`; edges
`e = (A → B)` carrying `{id, from, to, title?, note?}` and **no** behaviour.

Lowering to `Definition(places, transitions, initialPlaces)`:

| Authored | Lowered |
| --- | --- |
| node `N` | place `in(N)`, plus transition `T_N` carrying `N`'s `type`/`config` |
| edge `A → B` | `T_A` gains `in(B)` in its `tos` |
| node `N`, no outgoing edges | place `end(N)`, and `T_N.tos = [end(N)]` |
| node `N`, no incoming edges | `in(N)` joins `initialPlaces` |
| node `N` with `join: true` | one input place per incoming edge; `T_N.froms` = all of them |

Formally, for every node `N`:

```
T_N.froms = N.join ? [ in(N, e) for e in incoming(N) ] : [ in(N) ]
T_N.tos   = outgoing(N) ? [ placeFor(e) for e in outgoing(N) ] : [ end(N) ]

placeFor(A → B) = B.join ? in(B, edge.id) : in(B)
initialPlaces   = [ in(N) for N where incoming(N) is empty ]
```

The construction is **total**: every node yields exactly one transition, every
edge yields exactly one place reference, and every node has at least one `from`
and one `to` by construction. There is no document shape that lowers to nothing.

## The trap: merge is the default, join is opt-in

This is the one place where a naive lowering silently changes behaviour, and
getting it backwards would deadlock live flows.

Two edges arriving at one node have two possible readings:

- **OR-merge** — `B` runs when *either* `A` or `C` finishes.
- **AND-join** — `B` waits for *both*.

Petri nets express these differently: a merge is two transitions targeting one
shared place; a join is one transition with two `from` places. Under the
current model, converging edges each produce a token on the shared target
place, so **today's default is OR-merge**.

The live Hydra sequencer depends on this. Its `done` place is reached from
several distinct paths (`stop-idle`, `stop-full`, `advance-failed`) and exactly
one of them fires in any run. Lowering converging edges to an AND-join would
require all three to fire, and the flow would deadlock on every run — while
still reporting a valid definition.

So: `in(N)` is **shared across all incoming edges** by default, preserving
OR-merge. An AND-join must be declared (`join: true`), which is a strictly new
capability — the current model can express joins, and this keeps that.

**Proof obligation.** A unit test lowers a three-path converge onto one node and
asserts the node fires after exactly one predecessor. A second test asserts a
`join: true` node does *not* fire on one token and *does* on all of them. These
are the same `B3 == false` / `B5 == true` assertions ADR-065 used to verify
`symfony/workflow`, applied to the lowering.

## Loops need no engine support

A loop is a cycle, and the construction already produces one: body nodes chain
from the loop node and the last body node edges back to it. `in(L)` receives a
token again and `T_L` fires again, bounded by `limits.maxIterations` exactly as
today.

The loop-in / loop-out ports hermiq draws (`hermiq-flow-canvas-ports`) are an
**authoring affordance over ordinary edges**, not a new structure. A fully
cyclic flow still has no source node, and still falls back to starting on the
first declared node — behaviour that already exists and is unchanged.

## Failing loudly

The throw being removed exists because three fleet graphs ran, reported
COMPLETED, and did nothing. Whatever replaces it must be at least as loud, so
the builder refuses — by name, never by guess:

| Condition | Response |
| --- | --- |
| any `edges[].type` is non-empty | refuse: document is pre-inversion, name the edge, point at the migration |
| a node has no `type` | refuse at build time (see `or-flow-connectivity-and-last-run` for the save-time warning) |
| a node's `type` is unknown to the registry | refuse — existing `FlowNodePreflight` behaviour, moved from edges to nodes |
| `initial` names an unknown node | refuse — existing behaviour |
| duplicate node id | refuse — existing behaviour; two nodes silently merging into one place runs a flow nobody drew |

**Legacy detection is a single predicate**: a document is pre-inversion iff any
edge carries a non-empty `type`. It is crisp, it cannot be true of a correctly
migrated document, and it never needs to inspect node shape to decide. A
document that is ambiguous (typed edges *and* typed nodes) matches the
predicate and is refused — which is the safe direction.

## Consequences worth stating

- **Transition names become node ids.** They are `edge.name ?? edge.id` today.
  `flow_run.log[].transition` therefore starts carrying node ids. Historical
  run logs keep their old values; nothing reads them structurally, but the
  change is real and is called out here rather than discovered later.
- **`initial` names nodes, not places.** Migration rewrites it.
- **Synthetic places are not addressable.** `in(N)`/`end(N)` are internal names.
  Anything that persisted a raw marking — `FlowRunMarkingStore` — is writing
  place names that change shape. In-flight runs cannot survive the migration
  and must be drained or failed; see `or-flow-migrate-definitions`.

## Seed Data

Not applicable (ADR-001). Flows are native rows in `oc_openregister_flows`, not
OpenRegister objects — `flow-storage/spec.md` states a flow definition SHALL NOT
be stored as an object — so this change introduces and modifies no schema and
generates no `_registers.json` entries.

## Declarative-vs-imperative decision

Not applicable (ADR-031). This change alters the flow engine's internal
compilation from a stored definition to a `symfony/workflow` `Definition`. It
introduces no lifecycle, aggregation, derived field, notification, relation or
dashboard widget, so there is no declarative surface (`x-openregister-*`) that
could express it. The lowering is by definition imperative engine code.

## Alternatives considered

**Accept both shapes.** Rejected in the proposal: a half-migrated document runs
and reports success while skipping the step nobody claimed. That is the
original defect wearing a migration as a disguise.

**Keep the model and fix the editors.** This is what the current code chose, and
the builder's own comment records why it does not hold: node-shaped authoring
is what a graph editor produces, so the mistake regenerates with every new
editor. Hermiq's blank canvas is the second instance in one repo.

**A node flag to opt into carrying behaviour.** A dialect by another name, with
the same ambiguity surface and no migration forcing function.
