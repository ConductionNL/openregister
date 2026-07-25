# Proposal: or-flow-per-item-routing (design)

## Status

**Design only — no code.** This records the last named gap in #2067 (per-item
routing) and the recommended approach, so the decision is made deliberately
rather than by a rushed engine change. Implementation is a follow-up once the
approach is agreed.

## Summary

Today a choice point (If/Switch) routes the **whole** item batch down one
branch, chosen by the first item as the branch's representative
(`FlowEngine::selectTransition`). n8n instead splits the item stream: each item
goes to the output whose condition it matches, and every output branch runs with
its own subset. This proposal is how to get there without breaking the
Petri-net engine everything else now depends on.

## Why it is not a small change

The engine is a Petri net (symfony/workflow). A Switch is modelled as several
transitions **from the same place**, each with an edge condition. When one
fires, the token leaves the input place, so the sibling branches are disabled —
which is exactly why routing is all-or-nothing today. Firing several of them to
carry different item subsets would mean firing multiple transitions as one
logical step, which the token model does not express.

## Options considered

1. **Multi-fire a choice point.** At a conditioned choice, partition the input
   items and fire *every* edge that received items, each with its subset.
   - Rejected: needs the engine to fire N transitions atomically from one token,
     against the Petri-net model. Invasive to the run loop, the marking store
     and resume.

2. **Per-output item routing on a single transition (recommended).** Model a
   Switch as *one* transition with several `to` places (its outputs). The node
   returns items tagged with a target output; `advanceItems` distributes each
   item to the matching `to` place instead of copying the whole list to all of
   them (its current behaviour). One transition still fires — no multi-fire — but
   items fan out per output.
   - This is n8n's own model (a node has N outputs; items carry an output index).
   - Contained: the change is to the item/dispatch/advance contract, not the
     Petri-net firing rule.

3. **Leave batch routing as the documented default.** Per-item routing is
   authored as an upstream Filter/Split that produces separate branches.
   - The current behaviour; kept as the fallback if (2) is not pursued.

## Recommended change (option 2)

- Extend the produced-item shape with an optional output key (e.g.
  `pairedItem` stays as provenance; add `output` or reuse a branch label) that
  names which of the transition's `to` places the item goes to.
- `advanceItems`: when items carry an output assignment, distribute per output;
  when they do not (every existing node), keep today's copy-to-all behaviour, so
  nothing regresses.
- A Switch/Filter node opts in by tagging its output items; every other node is
  unchanged and unaffected.
- `selectTransition` stays for the true either/or case (a genuine branch, not a
  split); per-item routing is the *split* case and lives in `advanceItems`.

## Risks

- The item contract is shared with every consuming app's nodes. The opt-in
  design (untagged items behave exactly as now) is what keeps this safe; it must
  be covered by a test that an untagged multi-output node still copies to all.
- Resume: the per-place buffers already persist, so a split that suspends
  mid-branch resumes correctly — but this needs an explicit test.

## Out of scope

The builder UI for drawing multi-output nodes and labelling their outputs. This
is the engine contract only.
