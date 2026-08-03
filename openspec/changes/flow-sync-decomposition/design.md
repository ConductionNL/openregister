# Iteration in the flow engine

## The problem

A paginating fetch loops an unknown number of times, and each iteration must run
a *chain* of steps before deciding whether to continue. The same shape covers
every while/for loop an author might draw.

Three ways to model it, and the choice drives both the engine and the builder.

## Option A — cycle in the graph (what the engine does today)

Draw an edge from the last step of the body back to the paginating step. The
Petri net executes this correctly; `MAX_TRANSITIONS` stops a runaway.

**Rejected as the authoring model.** It executes, but:

- A back-edge looks identical to a forward edge. The single most important fact
  about the graph — that a region repeats — is carried by edge *direction*, which
  is exactly what a person scanning a diagram does not reliably read.
- Loop membership is implicit. Nothing says which steps are "inside"; you infer
  it by tracing the cycle. A step added near the join silently joins or leaves
  the loop depending on where the edge lands.
- The bound is global, not per-loop. `MAX_TRANSITIONS` is a whole-run ceiling, so
  two nested loops share one budget and the error names neither.
- It is diagnosed at runtime. "may contain an unbounded loop" arrives after the
  run, when the side effects have already happened.

## Option B — sub-flow per iteration

Make the body a separate flow and call it with `openregister.sub-flow`.

**Rejected.** The user's instinct here is right. It works, but it forces a
one-loop-per-flow split that has nothing to do with how the author thinks about
the work: a paginated harvest is *one* job. It also fragments the run history
across flows, so "what happened in this harvest" needs joining runs together —
reintroducing the ambiguity the step table removed. And a sub-flow boundary is a
real boundary: context, items and token do not flow across it for free.

## Option C — declared loop region (CHOSEN)

A loop is a **node that owns a body**, not a cycle and not a separate flow.

```
openregister.iterate
  ├─ source:  the step that produces the next batch (and says whether more exist)
  ├─ body:    an ordered chain of step ids, run once per batch
  └─ limits:  maxIterations, and what to do when hit (fail | stop | continue)
```

The engine runs `source`, and while it reports more, runs `body` over each batch.
Body membership is **declared**, not inferred.

### Why this one

- **Membership is data.** The builder can draw the body as a container — a box
  with the steps inside it — because it knows exactly which steps are in it. No
  edge-direction reading.
- **Bounds are per-loop and authored.** `maxIterations` sits on the loop that
  owns it, so the error can name which loop failed to converge, and two loops in
  one flow do not compete for a shared budget.
- **Non-convergence is a validation error, not a runtime surprise.** A loop
  whose source declares no termination signal is refusable at save time.
- **Iteration state has a home.** The loop owns a per-iteration scope (cursor,
  index, page), distinct from `flow-state`, which stays per-run.
- **The step table gains iteration.** Each body step records its iteration index,
  so "page 7 failed" is a query rather than a log read.

### What it costs

The engine gains a nested execution scope, which is real complexity in the one
place complexity is most dangerous. Mitigated by keeping the loop a NODE — it
executes its body through the same dispatcher as everything else, so there is
still exactly one thing that runs a step.

## Visualisation

The container is the point. A loop should read as a region, not a line:

```
┌─ iterate: GitHub search pages ──────── max 50 ──┐
│                                                 │
│  [fetch page] → [map] → [write objects]         │
│                                                 │
└────────────────── while: has next page ─────────┘
```

- The **header** carries the source step and the bound.
- The **footer** carries the continue condition, in words.
- Steps inside are laid out normally; the container is a background region.
- The iteration counter is a live badge during a run, and the run history
  colours the container by its worst body step.

Nested loops nest as boxes. A back-edge cannot express nesting legibly at all,
which is the second reason Option A loses.

## Migration

`openregister.loop` keeps its behaviour and is **renamed in the palette** to
"Batch items", because it batches and does not loop. The id stays
`openregister.loop` — it is referenced by stored flow definitions, and renaming
an id breaks authored data. This is the same reasoning that kept both
`json_decode` and `jsonDecode` registered when mapping consolidated.
