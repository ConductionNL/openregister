# Design: or-flow-migrate-definitions

## The transformation is the graph dual

Old shape: places `P` (nodes) and steps `E` (edges, carrying `type`/`config`).
New shape: actions `N` (nodes, carrying `type`/`config`) and sequence `E'`.

```
for each old edge e:            emit node n_e   with e's type, config, id, name
for each old place p:
  for each e_in  ending at p:
    for each e_out starting at p:
                                emit edge n_e_in → n_e_out, titled p
```

An old place becomes the **connection** between the steps that met there, which
is what it always meant. Its id and name are preserved as the new edge's
`title`, so the labels authors wrote ("Stage finished", "Gates passed", "Gates
failed") survive as the labels on the lines.

### It preserves the semantics that matter

| Old | New | Preserved because |
| --- | --- | --- |
| place with in 1 / out 1 | one edge | plain sequence |
| edge with `to: [a, b]` (split) | node with two outgoing edges | `T_n` targets both input places — still an AND-split |
| place with in 2 / out 1 (merge) | two edges into one node | shared input place — still an OR-merge |
| place with in 0 | source node | no incoming edge → initial place |
| place with out 0 | sink node | no outgoing edge → terminal place |
| `initial: [p]` | the edges leaving `p` | the run started where those steps started |

Verified against the live Hydra sequencer: 17 places / 16 edges becomes 16
nodes, with `work-gate`, `slot-gate` and `verdict-gate` each keeping two
outgoing edges (their `to: [x, y]` splits), and the three paths converging on
`done` becoming three edges into the same node — an OR-merge, which is what
`or-flow-action-nodes` makes the default precisely so this keeps working.

## Sinks that are not terminal steps

A sink node with a non-terminal step type would be a dead end under
`or-flow-connectivity-and-last-run` and its flow would be refused — a migration
that breaks the thing it migrates.

So: after the dual, any sink node whose type is not registered terminal is
marked `exit: true`. This asserts only what the old document already meant —
that place had no outgoing edge, so the flow ended there — and it makes that
meaning explicit rather than inferred, which is the entire point of the exit
rule.

The three `openregister.stop` steps in the sequencer need no such mark: they are
registered terminal already.

## Idempotence and safety

- **Detection** uses the same predicate the engine refuses on: a flow is
  pre-inversion iff any edge carries a non-empty `type`. One predicate, so the
  migration and the engine can never disagree about what needs migrating.
- **Unscoped read.** The repair step reads through the mapper with no
  organisation or owner filter. See the proposal: 14 rows exist, an org-scoped
  read returns 13, and one flow is owned by `__system__`.
- **Refuse on in-flight runs.** If any run is `running` or `suspended`, the step
  stops and names them. Markings reference place names that this migration
  changes; resuming across it would fail obscurely, later.
- **Report counts.** How many flows were inspected, migrated, and already in the
  new shape. A migration that reports "0 migrated" because it could not see the
  rows must be distinguishable from one that had nothing to do.

## Seed Data

Not applicable (ADR-001). This change rewrites existing native rows and
introduces no schema.

## Declarative-vs-imperative decision

Not applicable (ADR-031). A one-off data transformation in a repair step, per
ADR-069's background-job and repair conventions. It defines no lifecycle,
aggregation, derived field, notification, relation or widget.

## Alternatives considered

**Migrate lazily on read.** Rejected: it makes every reader a writer, and a flow
never read is never migrated — so the refusal in `or-flow-action-nodes` would
fire on a document the system had had every chance to fix.

**Hand-edit the 14 flows.** They are the fleet's live automation, including the
sequencer that fires every five minutes. A mechanical, tested, idempotent
transformation is safer than fourteen manual rewrites, and it also covers
instances other than this one.
