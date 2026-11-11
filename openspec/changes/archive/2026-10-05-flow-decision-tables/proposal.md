---
kind: code
depends_on: [shared-decision-table-evaluator]
---

# Proposal: flow-decision-tables

## Summary

Put rule evaluation into the graph. A new node type
`openregister.decision-table` evaluates a DMN-style decision table against
each item and writes the winning outputs onto configured item fields. The
table travels inline in the node's configuration, so flow versioning and
pinning apply to the rules the same way they apply to every other authored
step. Evaluation is delegated in full to the shared
`DecisionTableEvaluator`; this node maps item fields in and out, and
refuses a table the evaluator could not execute at save time rather than at
run time.

## Why

**The directive is explicit: apps get no flow engines of their own, and
decision tables are flow-engine work.** dossiq ships its own decision-table
storage, lookup and wiring (`EvaluateDecisionHandler`,
`DecisionTableService`, a `decisionTable` register schema and a
`dossiq.evaluateDecision` flow-node wrapper) and is staging their
retirement against this change. The evaluation core already moved here —
`shared-decision-table-evaluator` consolidated dossiq's and openbuild's
engines into `lib/Service/Dmn/` — but nothing in the flow palette can
invoke it. The capability exists and is unreachable from a flow, which is
exactly the orphan shape gate-57 exists to catch.

**A rule step is not a human step.** The fleet's decision split is:
automated rule evaluation belongs to the engine (this node), human
decisions belong to decidiq (`openregister.user-task` and its consumers).
This node therefore never suspends, never creates a task and never waits.
It is deterministic: the same item and the same configuration produce the
same outputs, every time, with no I/O in the evaluation path.

**The migration must not strand.** dossiq's tables and handler define the
floor: five hit policies (UNIQUE, FIRST, PRIORITY, ANY, COLLECT — the
shared evaluator already implements all five), positional
`inputEntries`/`outputEntries`, typed inputs (string, number, boolean,
date), an `inputMapping`/`outputMapping` vocabulary with a same-name
default, and loud typed failures. Every one of those is expressible here,
and the test suite proves it with a table translated from dossiq's real
LHS enforcement matrix.

## What Changes

- **A new node, `openregister.decision-table`**, in
  `lib/Service/Flow/Nodes/`, implementing `IFlowNode`,
  `IFlowNodeConfigKeys` and `IFlowNodeConfigForm`, registered through
  `lib/Listener/FlowNodeRegistrationListener.php` like every built-in. Its
  `configForm()` is served by the existing node catalog; no editor change.
- **The table is inline in the node config** (`table`), so it is versioned
  and pinned with the flow and imported through `x-openregister-flows`
  with no extra machinery. See design.md D-1 for why a live object
  reference was considered and refused.
- **A new engine-owned validator, `DecisionTableValidator`**, in
  `lib/Service/Dmn/`, that validates a table by exercising the
  evaluator's own grammar against every rule cell. The accepted grammar is
  the executable one by construction, not by a parallel re-implementation
  that can drift.
- **No-match behaviour is a choice the author makes explicitly**:
  `defaultOutputs` supplies a complete fallback row, otherwise a no-match
  fails the step loudly and the step's `onError` policy decides. There is
  no silent empty result.
- **No new endpoints.** The node plus the existing flow save/import path
  is the whole surface.

## Out of Scope

- **A referenced, engine-stored table catalogue.** Refused for now, with
  the reasoning recorded in design.md D-1. If it returns it composes on
  top of this node rather than replacing it.
- **Retiring dossiq's copies.** The parallel dossiq change does that,
  pointing at this node; deleting a working evaluation path from here
  would couple two repos' release trains.
- **DMN XML interchange and full FEEL.** Both already ruled out by
  ADR-065 and `shared-decision-table-evaluator`; nothing here reopens
  them.

## Risks

- ⚠️ **An inline table is copied per node.** Two nodes using one policy
  table hold two copies. Deliberate: a shared mutable table under a
  published flow is the bigger hazard (design.md D-1); a genuinely shared
  decision lives in one flow invoked via `openregister.sub-flow`.
- ⚠️ **Every declared input must resolve on the item.** The evaluator
  refuses a missing input (`type_mismatch`/`missing_input`) rather than
  wildcarding it, inherited deliberately from dossiq's handler: a decision
  taken over absent data is not a decision.
