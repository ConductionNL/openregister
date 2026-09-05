---
kind: code
depends_on: [flow-approval-consolidation]
---

# Proposal: flow-engine-consumer-seams

## Summary

Close the two engine gaps the fleet audit handed back, both of the shape
"every consuming app re-implements a rule the engine should own":

1. **A guarded server-side signal API.** `FlowRunSignalService::signalAs()`
   resumes a suspended run on behalf of a named actor and applies the
   recorded-assignee guard (group resolution included) before anything is
   delivered, auditing a refusal. The HTTP resume path migrates onto the same
   seam, so there is ONE guard. `FlowRunService::signal()` stays as the
   unguarded engine-internal primitive and is documented as such.

2. **Native `runAs` scoping for contributed nodes.** `RegistryStepDispatcher`
   resolves the run's acting identity (validated: the account exists and is
   enabled, refused loudly otherwise) and executes every CONTRIBUTED node
   inside `ObjectService::runAs()`, so an app node's writes inherit the run's
   identity without the app building a wrapper. The context key is exported as
   a constant, and a documented escape hatch exists for nodes that manage
   their own scoping. OpenRegister's own nodes are unaffected — they already
   scope themselves.

## Why

**Gap 1 — the guard only lives on the HTTP path.** The assignee rule was
extracted into `FlowRunAssignee` precisely because in-process resumes exist,
but the engine still leaves CALLING it to the consumer:
`FlowRunController::refuseUnlessAssignee()` applies it for HTTP, while an app
that resumes from PHP calls `FlowRunService::signal()` directly — which
delivers unconditionally. dossiq's `TaskCompletionResumeListener` and
`ParaafResumeListener` each remembered to consult the rule (and
mutation-tested it), but "each caller must remember" is the defect class: the
next app forgets, and a forgotten check does not throw — it lets the wrong
person answer somebody else's step, HTTP 200. The engine must offer the
guarded verb so the unguarded primitive stops being the obvious API.

**Gap 2 — app nodes execute as nobody.** The engine stamps the run's `runAs`
into the node context, and its OWN write-capable nodes (`ObjectWriteNode`,
`ObjectReadNode`) wrap their storage calls in `ObjectService::runAs()` —
because the RBAC handlers read the ambient session, which under a cron worker
carries no one. A contributed node gets the context key and nothing else:
unless the app builds its own wrapper, every write it performs is refused as
anonymous (or worse, silently executed under whatever ambient identity a
request happens to carry). dossiq shipped THREE broken nodes before building
`FlowRunAsScope`, and `MergeTemplateHandler` was a fourth. The identity is
run-level state; scoping to it belongs in the dispatcher that hands the node
its context, not in every app.

**Measured this week:** two shipped dossiq defects trace to these gaps; the
audit found the same re-implementation pressure in every consuming app.

## What Changes

- New `lib/Service/Flow/FlowRunSignalService.php` — `signalAs(runUuid,
  payload, actorUid, nodeId?)` and `signalRunAs(run, …)`: resolve, guard
  (via the existing `FlowRunAssignee` rule), audit a refusal, deliver via
  `FlowRunService::signal()`.
- New `lib/Exception/FlowSignalRefused.php` — one typed refusal with a reason
  (`run-not-found`, `not-assignee`, `not-suspended`) so PHP callers and the
  controller map it without string-matching.
- `FlowRunAssignee` learns an optional `nodeId`: a caller that knows WHICH
  node its answer addresses is checked against that node's recorded assignee;
  naming a node that is not asking falls back to the run-level rule, so
  addressing can never loosen the guard.
- `FlowRunController::resume()` and `signalByKey()` migrate onto the seam;
  their routes, status codes and response bodies are byte-identical.
- `FlowRunService::signal()` is documented as the trusted engine-internal
  primitive; `FlowRunService` exports `RUN_AS_CONTEXT_KEY`.
- New `lib/Service/Flow/FlowRunAsScope.php` — validates and applies the acting
  identity (mirrors dossiq's service of the same name, which becomes
  deletable).
- New `lib/Service/Flow/IFlowSelfScopedNode.php` — the escape hatch marker for
  contributed nodes that manage their own identity.
- `RegistryStepDispatcher` executes contributed nodes inside the scope.

## Impact

- Affected specs: `flow-engine-consumer-seams` (new capability, delta spec in
  this change).
- Affected code: `lib/Service/Flow/` (dispatcher, run service, assignee rule,
  two new services, one marker interface), `lib/Controller/FlowRunController.php`,
  `lib/Exception/`, `lib/AppInfo/Application.php`.
- No route, migration or frontend changes. The HTTP contract is unchanged.
- Consuming apps: dossiq's `FlowRunAsScope` and the guard block in its two
  resume listeners become deletable once they adopt the seam; the next app
  never builds either.
