# Proposal: or-flow-triggers

## Summary

Close the loop: a Nextcloud event queues a flow run, and the worker actually
executes it. Adds the first native trigger (object create / update / delete).

## Why

Everything was in place except the two ends. The run queue existed but the
worker could not execute a run — it had a TODO where the flow document and
subject should be resolved. And nothing turned a Nextcloud event into a run.

A Nextcloud-native trigger is the differentiator the whole programme rests on:
an external automation tool sees Nextcloud over WebDAV through a generic
connector; a flow triggered by a *share being declined*, with the sharee's
identity and RBAC already resolved, is not something that tool can reproduce.

## What Changes

- **`IFlowResolver` + `RegisterFlowResolversEvent` + `FlowResolverRegistry`.**
  The engine does not know where flows are stored — hermiq keeps them as
  `agentflow` objects, another app might keep them elsewhere. An app that owns
  flows contributes a resolver, discovered the same way node types and MCP
  providers are. The registry asks each in turn: first non-null wins. This is
  also exactly what hermiq#35 needs to plug its store in.

- **The worker executes.** `FlowRunWorker::advance()` now resolves the flow and
  subject and runs it. A flow no resolver owns (deleted, its app removed) fails
  the run with a clear reason rather than looping forever; a subject that no
  longer exists fails likewise; a subjectless run (manual/webhook) still gets a
  marking carrier.

- **`FlowTriggerService`.** Turns an event into queued runs: asks every resolver
  which of its flows are wired to that event, queues one run each. It does NOT
  execute — a trigger fires inside the dispatch of the user action that caused
  it, and a graph must not sit on that critical path. Never throws into the
  caller: a failure to queue must not break the save that fired it.

- **`FlowTriggerListener`** wires object create / update / delete to the trigger
  service. The first native trigger; files, shares, calendar, users and tags
  register the same way — a small listener translating a core event into a
  `fire()` call.

## Out of scope (this change)

- **The other native triggers** — file, share, calendar, user/group, tag,
  schedule, webhook, manual. The machinery is here; each is a listener plus a
  `fire()` call, a follow-up per trigger.
- **A built-in flow store in OpenRegister.** Flows are resolved through the
  contributed resolver; hermiq provides one for its agentflows (hermiq#35). OR
  owning a flow schema of its own is a separate decision.
