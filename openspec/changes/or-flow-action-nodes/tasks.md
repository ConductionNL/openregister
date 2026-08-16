# Tasks: or-flow-action-nodes

- [ ] `FlowDefinitionBuilder::build()` — lower actions to transitions per
      `design.md`: one transition per node carrying its `type`/`config`, one
      input place per node, edges contributing target places, terminal places
      for sink nodes, initial places for source nodes.
- [ ] Shared input place per node so converging edges are an **OR-merge**, and
      `join: true` opting into per-edge input places for a synchronising
      **AND-join**. This is the behaviour-preserving default — see design.md;
      getting it backwards deadlocks the live Hydra sequencer on every run.
- [ ] Name transitions after the node id, and update `FlowEngine::stepFor()` to
      resolve a transition to its NODE rather than to an entry in `edges[]`.
- [ ] `RegistryStepDispatcher` reads `type`/`config` off the node.
- [ ] Refuse a pre-inversion document: predicate is "any edge carries a
      non-empty `type`". Message names the edge and the migration. Never repair,
      never reinterpret.
- [ ] Refuse a node with no `type`, an unknown `type`, a duplicate id, a
      dangling edge endpoint, or an `initial` naming an unknown node — each
      naming the offending element.
- [ ] `FlowNodePreflight` walks NODES instead of edges, carrying the
      `IFlowNodeConfigKeys` unknown-key check with it.
- [ ] `POST /api/flow/validate` reports against the new model; its report shape
      (`valid`/`blocking`/`warnings`/`message`) is unchanged.
- [ ] Unit tests for the lowering: chain, split, merge, declared join, cycle,
      sink, source, single-node flow.
- [ ] Merge-vs-join proof tests — a three-path converge fires after ONE
      predecessor; a `join: true` node does not fire on one token and does on
      all. Mirrors ADR-065's `B3 == false` / `B5 == true` verification.
- [ ] Refusal tests, each asserting the message names the offending element, with
      a positive control proving the same document builds once corrected.
- [ ] Regression test: the real Hydra sequencer shape (17 nodes, 16 edges, 3
      splits, converging `done`) lowers and runs to completion after migration.
- [ ] Rewrite `openspec/specs/flow-engine/spec.md` — it does not yet exist
      canonically (only inside `changes/or-flow-engine/`), so create it and
      point `FlowDefinitionBuilder`'s `@spec` at the canonical path.
- [ ] Update `flow-storage` spec's node/edge shape description to match.

## Acceptance criteria

- Behaviour lives on nodes; edges carry only `from`/`to` plus optional display text.
- Joins, splits, parallel markings, suspension/resume, `onError` and run logging are unchanged.
- No document shape is accepted by both the old and the new reading.
- Every refusal names the node or edge responsible.
- The migrated Hydra sequencer produces the same step sequence as before migration.

## Quality checklist

- Tests run on the container's PHP 8.4, not the host — ADR-065's verification note.
- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- `@spec` annotations target the canonical `openspec/specs/` path, never a change dir.
- Depends on nothing; `or-flow-migrate-definitions` and
  `or-flow-connectivity-and-last-run` both depend on this.
