# Proposal: or-flow-preflight

## Summary

Resolve a flow's step types when the flow is STORED instead of when it runs.
`FlowNodeRegistry::get()` already refuses an unknown type for exactly the right
reason — a silently skipped step produces a run that reports success while never
having done the work. It just refuses at the wrong moment: at step dispatch,
mid-run, after earlier steps have already written to the outside world.

## Why

Measured, or#2247. `flows/hydra-file-findings.flow.json` names
`openregister.explode`, which shipped in or#2247, while the running instance sat
at or#2244. The document saved cleanly. It ran. It called out to the forge. Then
it hit the explode edge and died, with nothing rolled back — no label move, no
commit, no filed issue is undone by a mid-run throw. Nothing noticed until
someone checked by hand.

The general shape: nothing validated that what a flow definition NEEDS is what
the live instance actually PROVIDES. The registry was the authority all along;
it was simply never consulted until it was too late for the answer to be useful.

## What Changes

- **`FlowNodePreflight`** resolves every `edges[].type` against the registry and
  classifies each failure by the app that owns it (derivable from the
  `<app>.<node>` contract on `IFlowNode::getId()`).
- **`FlowNodePreflightListener`** on `ObjectCreatingEvent` / `ObjectUpdatingEvent`
  refuses the write. Those events are dispatched by `MagicMapper`, so the API
  save path, the configuration importer and any seeding code all pass through it
  — a guard on the HTTP controller alone would be bypassed by the import path
  that actually carried the defect.
- **`POST /api/flow/validate`** answers the same question about a document
  nobody is writing, for CI and for the editor.

## Why not simply refuse every unknown type

Because that would break a legitimate case the fleet depends on. A shared
configuration export carries flows naming `openconnector.source-call` and
`hermiq.agent-step`; importing it onto an instance that has not enabled those
apps must land. The flow is not wrong — the instance is incomplete, and it
becomes correct the moment the app is enabled. Every other optional-app probe in
this codebase treats absence that way (`ExternalIntegrationRouter`,
`TalkLinkService`, `AnalyticsProvider`).

Ownership is what makes a hard refusal safe. If the owning app is ENABLED and
still lacks the type, no install fixes it: the app is right here and does not
have it. That is always a defect. If the owning app is absent, installing it is
the fix. So the first refuses and the second warns — loudly, structurally, and
without blocking anyone's import.

## Known gaps this does NOT close

Recorded precisely rather than half-fixed.

**Schedule-triggered flows in a leaf app's store cannot fire at all.**
`FlowScheduleService` enumerates only the `flow_register`/`flow_schema` pair
(defaulting to `flows`/`flow`); it never consults the resolver registry. So
`hydra-sequencer`, `hydra-dispatch` and `hydra-lock-reaper`, which live as
hermiq `agentflow` objects, are invisible to the scheduler. Verified in
`FlowScheduleService` (`REGISTER_KEY` / `SCHEMA_KEY` are the only lookups).

**And their `cron` is dropped on the way in.** The live `agentflow` schema (5020)
has no `cron` property — verified: `properties ? 'cron'` is false — so the `cron`
key in those flow documents is silently discarded at save. That is the same
family as `or-silent-field-loss`, and the warning added there is what makes it
visible; declaring the property and teaching the scheduler to ask the resolvers
is a separate change.

Preflight does not catch either: both are about a definition asking for
something the *instance* does not provide, but neither is expressible as an edge
type or an edge config.

## What is deliberately NOT validated

The graph's shape. `FlowDefinitionBuilder` could check it and already refuses
dangling edges, duplicate node ids and node-shaped authoring — but a flow being
drawn on a canvas is legitimately half-connected, and refusing to save a draft
would make the editor unusable. A step TYPE is never bogus on purpose; nobody
drafts a node type they do not mean.
