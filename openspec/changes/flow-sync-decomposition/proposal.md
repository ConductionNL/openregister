---
kind: code
---

## Why

`openconnector.synchronization-run` is one node that runs an entire
synchronisation. It is the last monolith in the flow engine: everything else in
the catalogue is a step, and this is a program.

That has three consequences.

**You cannot see what a sync does.** The run history records one step,
`synchronization-run / completed`. Which page failed, which record was skipped as
unchanged, which contract was written — none of it is a step, so none of it is
queryable. The whole point of the step table was to stop "it ran" being the only
thing we know.

**You cannot change part of it.** Wanting to map differently, or write to a
second register, or filter before persisting means editing PHP, because the four
things inside the monolith are not addressable:

| inside the monolith | node today |
|---|---|
| paginate the source | — |
| detect change by hash | — |
| resolve the synchronisation contract (origin ↔ target id) | — |
| write to target + update the contract | — |

**And the contract gap makes flow-built syncs unsafe.** A flow can already do
fetch → map → write with existing nodes. What it cannot do is know that it has
seen a record before, so a second run creates duplicates rather than updating.
Idempotency is the reason `synchronization-run` still has to be used whole.

### The iteration problem underneath it

Pagination is not just another step. It loops an unknown number of times, and
each iteration must drive a *chain* of steps — fetch page, map it, write it —
before deciding whether to go round again.

The engine can already express this: it is a Petri net, so a cycle in the graph
is a loop, bounded by `MAX_TRANSITIONS`. What is missing is not execution but
**authoring and legibility**:

- `openregister.loop` is misleadingly named. It does not loop; it splits items
  into fixed-size batches. A user reaching for iteration picks it and gets
  something else.
- A loop drawn as a back-edge is hard to read and easy to draw wrong. An
  unbounded cycle is only caught at runtime, by the transition ceiling, and
  reported as "may contain an unbounded loop" — after the run.
- Nothing carries loop state. A paginating node needs somewhere to keep the
  cursor across iterations, and `openregister.flow-state` is per-run, not
  per-iteration.

This affects every while/for shape, not only pagination.

## What Changes

- **DECOMPOSE** `synchronization-run` into contributed nodes:
  `openconnector.source-paginate`, `openconnector.change-detect`,
  `openconnector.contract-resolve`, `openconnector.contract-write`. The
  monolith stays, deprecated, until the decomposed set is proven — deleting it
  first would strand every existing sync.
- **ADD** a first-class iteration construct rather than leaving loops as
  hand-drawn back-edges: a node that emits a batch and a "more" signal, plus a
  declared loop body, so the builder can DRAW the cycle as a container instead
  of an edge that happens to point backwards.
- **EXTEND** `openconnector.source-call` so a Source may reference a
  doriath-held credential, brokered by OpenRegister when the credential is
  host-locked. ONE node, not two: which credential shape a source carries is
  exactly what the broker exists to hide, so it must not become a modelling
  choice the author has to get right.
- **ADD** a `publiccode` schema and a worked example flow that harvests
  publiccode.yml files from GitHub into OpenRegister objects, searchable by
  OpenCatalogi.

## The credential constraint (why publiccode is the right example)

GitHub's API is rate-limited hard enough that the harvest needs a PAT. That PAT
must not reach the flow.

The fleet already has the right shape for this, and it is easy to get wrong:

- **doriath** stores the secret zero-knowledge — write-without-read, RSA-4096 /
  AES-256, not readable by the administrator.
- **OpenRegister** brokers it. The `github` credential is a **host-locked proxy**:
  `resolveInjectable()` returns `null` for it, and that null is a ROUTING signal
  meaning "use `request()` instead", **not** a denial.
- `POST /api/credentials/{id}/request` performs the call server-side. Only
  response bytes cross the boundary.

So the Source must be able to carry a doriath-held credential, and resolve it by
SHAPE: injectable credentials are attached to the request as now; a host-locked
one is handed to OpenRegister's broker, which performs the call server-side and
returns only response bytes. The author configures a credential and calls the
source; the brokering is under the waterline.

"Integrating correctly with doriath" therefore means never fetching a token —
not adding a second node for the case where you cannot.

This is why publiccode is a better first example than a toy: it exercises
pagination, an unbounded loop with a per-iteration chain, mapping, idempotent
writes, and brokered credentials — the whole decomposition, on a real source.

## Impact

Sequenced so nothing is stranded:

1. The iteration construct first — pagination depends on it, and every later
   node is easier to reason about once loops are first-class.
2. doriath credentials on Sources, because the publiccode example cannot run
   without a PAT.
3. The publiccode schema and example flow, as the proving ground.
4. The synchronisation nodes, with `synchronization-run` deprecated but present.

`synchronization-run` is NOT deleted by this change. It is deleted when a
decomposed flow has demonstrably replaced it for a real synchronisation,
including the contract bookkeeping — not before.
