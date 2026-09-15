---
kind: code
---

# Proposal: or-flow-run-node

## Summary

Give OpenRegister an endpoint that runs ONE named node of a published flow
against ONE subject object, authorized against that subject — not the whole
flow, and not the editor's authoring trust. This is the upstream half of
dossiq `documents-on-the-case` task 3.3 (Generate document from a case,
without a dialog): dossiq has a case-detail action that should invoke
`DossiqMergeTemplateNode` directly, and nothing in OpenRegister today lets an
app do that safely.

## Why

`documents-on-the-case` 3.3 measured the gap directly against `development` on
2026-09-11 (see dossiq's `openspec/changes/documents-on-the-case/tasks.md`,
task 3.3, decisions D-1 through D-4). The relevant finding for this repo:

> There is no server to talk to. OpenRegister routes `POST /api/flows/{id}/run`,
> where `{id}` is a FLOW uuid... Nothing anywhere executes ONE registered node
> out of graph with a subject and a config blob.

Verified again while writing this proposal, against OpenRegister `development`
at `2aec4e9`:

- `FlowController::run()` (`POST /api/flows/{id}/run`) runs the WHOLE flow from
  its published `initial` node (or a `startAt` override — see below), gated by
  the named right `flow.run`.
- The only existing "start partway through" capability is
  `FlowRunController::test()` (`POST /api/flow-runs/test`, `or-flow-partial-run`),
  which accepts `startAt` + `pins` + `seedItems`. But it is an **authoring
  tool**, not a general invocation surface, and its own proposal says so
  explicitly: "a partial run is an editor action, not a background trigger."
  Concretely, `FlowRunController` has **no `FlowAccess` dependency and no
  authorization check at all** beyond "does this flow uuid exist"
  (`refuseUnlessRunnable()`). It is safe today only because the flow editor is
  the sole thing that calls it, and editor access is an app-level convention,
  not an API-level guarantee. Wiring a document action button straight to it
  would let any authenticated user run any node of any flow with an arbitrary
  seed/pin payload.
- `flow.run` itself (the right the WHOLE-flow endpoint checks) is a flat,
  **subject-blind** right: `FlowAccess::may()` asks "does this user hold the
  named right `flow.run`", never "does this user hold any permission on THIS
  object". Per `openspec/specs/flow-engine/spec.md`
  ("Creating, editing and running a flow are named rights"), `flow.run` is
  seeded `@authenticated` by default — literally any signed-in user, instance-
  wide, unless an admin has since narrowed it. So even reusing `flow.run`
  as-is would authorize "any signed-in user may run this flow against ANY
  object they can name the id of", which is not what a case-detail button
  needs and is not equivalent to the object-level RBAC every other direct
  mutation goes through (ADR-022/023, `object-op`).

So building this safely needs a new authorization idea, not just a new route.
**Design decision RN-1 below is not resolved in this proposal — it is written
up with a recommendation and needs Ruben's sign-off before implementation**,
because it changes how OpenRegister's flow engine and its object-level RBAC
relate to each other for the first time, which is fleet-wide, security-
relevant surface, not a dossiq-only detail.

## What Changes

- A new opt-in marker interface, `IFlowDirectlyInvokable` (distinct from the
  existing `IFlowSelfScopedNode`, which is about acting-identity scoping under
  `runAs`, not invocation surface):
  - `IFlowDirectlyInvokable`: a node type
    implements to say "I may be run out-of-graph, directly, against one
    subject." A node that does not implement it stays reachable only inside a
    real flow run. This is the per-node allowlist half of RN-1 — see design.md.
  - `POST /api/flows/{flowId}/nodes/{nodeId}/run` — runs the named node from
    the named flow's published graph, with `subject` and `config` in the body.
    404s when the flow or node does not exist, or the node type does not
    implement `IFlowDirectlyInvokable`. Authorization: see RN-1.
  - The response is a `FlowRun` record (same shape as `flow#run`'s response),
    scoped to the one node, so it lands in run history like any other run —
    an out-of-graph invocation is not a hidden side channel.
- `DossiqMergeTemplateNode` (dossiq repo) implements `IFlowDirectlyInvokable`
  and `IFlowNodeConfigForm` (already-existing contract — see design.md RN-2),
  so the picker in dossiq's manifest action is sourced from the node's own
  declared form, not a new manifest-level `@pick:` grammar.

## Out of scope

- Changing `flow.run`'s default seed or the flow named-rights matrix shape.
- A generic "describe any node's live config" endpoint beyond exposing
  `configForm()` for a node already resolved by `{flowId}/{nodeId}` — no
  cross-flow node catalog change.
- Anything in dossiq or nextcloud-vue: tracked in their own openspec changes
  (`dossiq-run-node-action` will reference this once RN-1 is settled;
  nextcloud-vue's `manifest-run-node-action` covers the dispatcher side).
