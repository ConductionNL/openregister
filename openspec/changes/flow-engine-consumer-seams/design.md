# Design: flow-engine-consumer-seams

## Context

The state of the code today:

- `FlowRunAssignee` (`lib/Service/Flow/FlowRunAssignee.php`) already holds the
  ONE copy of the "who may answer" rule — uid match, group resolution,
  unassigned-is-open, anonymous-fails-closed. What the engine does not hold is
  a guarded VERB: `FlowRunController::refuseUnlessAssignee()` consults the
  rule for HTTP, and every PHP caller of `FlowRunService::signal()` must
  remember to consult it themselves. dossiq's two listeners do; the seam
  exists so the third consumer does not have to.
- `FlowRunService::baseContextFor()` stamps `$context['runAs'] =
  $run->getRunAs()` on every walk — assignment, not coalesce, per ADR-099
  (identity narrows, never widens; a context-supplied identity would be a
  queue-time privilege escalation). The literal `'runAs'` is read back in
  `ObjectWriteNode`, `ObjectReadNode` and `FlowMessagingService`, and by
  dossiq's `FlowRunAsScope` — with no exported constant to reference.
- `RegistryStepDispatcher::dispatch()` checkpoints the guard, scopes the
  resume slot, scopes the signal (#3325), executes the node, clears the slot.
  It is constructed at three sites: twice inside `FlowRunService` (execute and
  the #3310 stream walk — both with a per-run guard) and once as a container
  factory in `Application.php` (guardless; the loop node's dispatcher).
- OpenRegister's own storage-touching nodes each wrap their work in
  `ObjectService::runAs()` after validating the identity exists and is
  enabled (`ObjectWriteNode::resolveOwner()`). A contributed node starts with
  none of that.

## Decisions

### D-1: The signal seam wraps the existing rule; it does not replace it

`FlowRunSignalService` composes `FlowRunAssignee` + `FlowRunMapper` +
`FlowRunService::signal()`. The rule object stays exactly where it is and
keeps its contract (unassigned means anyone; anonymous fails closed; group
membership admits). What the seam adds is the VERB: resolve the run, apply
the rule for a NAMED actor, audit a refusal, deliver. Two methods:

- `signalAs(runUuid, payload, actorUid, nodeId?)` — for callers holding a
  uuid (dossiq's listeners hold exactly that).
- `signalRunAs(run, payload, actorUid, nodeId?)` — for callers that already
  resolved the run (the controller, which must 404 before it 403s).

Refusals are one typed exception, `FlowSignalRefused`, carrying a reason
constant. An exception rather than a nullable return because the unguarded
primitive already uses `null` to mean "not suspended", and a seam whose
refusal can be ignored by ignoring a return value is not a guard.

### D-2: The HTTP path migrates onto the seam, byte-identical

`resume()` and `signalByKey()` keep their own run resolution (their 404/409
wording differs per endpoint and is asserted by existing tests) and delegate
the guard + delivery to `signalRunAs()`. `refuseUnlessAssignee()` and the
controller's private rule factory are deleted — the controller no longer
touches `FlowRunAssignee` at all. Status codes, bodies and the
routing-artefact stripping are unchanged, so no contract test moves.

### D-3: `nodeId` narrows the guard to the addressed slot, and can only narrow

A run can await several nodes at once, each with its own recorded assignee.
The run-level rule answers with the FIRST asked slot's assignee, which
refuses the second node's own audience. When the caller names the node its
answer addresses, the guard checks THAT node's recorded assignee. A `nodeId`
whose slot is not held (the node is not asking) falls back to the run-level
rule — naming a node that asked nothing must not become a way around the
guard on the node that did.

### D-4: The dispatcher scopes contributed nodes; engine nodes are untouched

The identity wrap happens in `RegistryStepDispatcher::dispatch()`, around
`$node->execute()` only — after the checkpoint, the resume-slot scoping and
the signal scoping (#3325), and before the per-node budget check, so neither
#3325's addressing nor #3310's stream walk changes shape.

Which nodes: a node whose class lives under `OCA\OpenRegister\` manages its
own scoping today (`ObjectWriteNode` et al. validate and wrap internally,
with node-specific error wording and skip-when semantics) and is left alone —
wrapping it again would be harmless for writes but would change the ambient
identity of nodes that deliberately run bare. Everything else is contributed
and gets the wrap, unless it implements `IFlowSelfScopedNode` — the
documented escape hatch for a node that must manage its own identity (for
example one whose work is legitimately system-level installation plumbing;
such a node takes on the obligations the interface docblock names).

Semantics match dossiq's `FlowRunAsScope`, which mirrored
`ObjectWriteNode::resolveOwner()`:

- context names NO identity → the node runs bare (the interactive path, and
  every existing test fixture);
- identity resolves to no account → refuse loudly;
- identity resolves to a DISABLED account → refuse loudly, so a run parked
  for weeks cannot resume with an offboarded user's rights;
- identity valid → `ObjectService::runAs($user, execute)`. Narrowing only:
  a run whose owner cannot write is still refused, now for the right reason.

### D-5: The scope collaborator is nullable, and null means the harness

The dispatcher's scope is resolved from the container (`FlowRunService`
resolves it lazily; the `Application.php` factory injects it). A dispatcher
built by hand with none — the flow tester, node unit tests — runs nodes bare,
exactly as the nullable `FlowRunGuard` already works. This is not fail-open
in production: all three production construction sites supply the scope, and
the refusal tests pin the behaviour when it is present.

### D-6: The context key becomes a constant, and the value is frozen

`FlowRunService::RUN_AS_CONTEXT_KEY = 'runAs'`. The literal is stored inside
every parked run's context, so the VALUE can never change; the constant
exists so readers (engine nodes, the messaging service, consuming apps)
reference one name. All in-tree literal reads migrate to it.

## Follow-ups (audit gaps NOT built here)

The fleet audit named four engine gaps; this change closes two. The other two
are recorded so the change record is honest about scope:

- **Request-scoped trigger**: apps that trigger flows from a live request
  still queue through the worker; a request-scoped fire-and-return seam is a
  separate change.
- **Shared retry-policy vocabulary**: each app still declares its own retry
  semantics for failed runs; a shared vocabulary (and its config schema) is a
  separate change.

## Risks / Trade-offs

- **A contributed node that relied on running bare while its run named a
  `runAs` changes behaviour** — it now executes under the run's identity, or
  is refused if that identity is stale. That is the point; the escape hatch
  is the opt-out and its docblock names the cost.
- **Class-namespace as the engine/contributed test** is a heuristic, but a
  stable one: the namespace is claimed by this app's autoloader, and a
  contributed node cannot live under it. The marker interface exists for the
  cases the heuristic cannot express.
