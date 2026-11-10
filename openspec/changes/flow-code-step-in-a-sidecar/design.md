# Design: flow-code-step-in-a-sidecar

Read at openregister development 555af7212, and issue #2066.

## Context

- A node implements `IFlowNode` (`lib/Service/Flow/IFlowNode.php:76-159`):
  `getId()`, `isAvailableForScope()`, `validateConfig()` and
  `execute(array $items, array $config, array $context): array`. Built-ins
  register through `RegisterFlowNodesEvent` in
  `lib/Listener/FlowNodeRegistrationListener.php`, and
  `FlowNodeRegistry` refuses a duplicate id.
- No code or script node exists in `lib/Service/Flow/Nodes/`.
- OpenRegister already calls an ExApp: `AnonymisationBackendService`
  resolves `OCA\AppAPI\PublicFunctions` lazily and calls
  `exAppRequest($appId, $route, null, $method, $params)`
  (`lib/Service/Anonymisation/AnonymisationBackendService.php:353-369`), with a
  cached health probe (`probe()`, `:224`).
- A step failure reaches the edge's `onError` policy
  (`lib/Service/Flow/FlowEngine.php:965` and `:1236`).
- `flow-powerful-steps-need-a-right` (open) introduces rights for steps that
  can do more than their author; this change adds one more of that kind.

## D-1: a container boundary, never in-process

Authored code in the PHP process would have the whole server, the database
and the file system. No library closes that. The runner is an ExApp with
Node 22 and `isolated-vm`, one isolate per call, dropped after the call. The
runner receives `{ source, mode, items, limits }` and answers
`{ items, logs, durationMs }` or `{ error, logs }`.

## D-2: limits are the node's config, capped by the instance

`timeoutMs` and `memoryMb` default to 5,000 and 64. An administrator sets the
instance ceiling in the flow settings. A config above the ceiling is refused
at save, naming the ceiling. The runner enforces the same numbers, so a
misbehaving runner call is bounded twice.

## D-3: egress is declared, default none

`egress` lists host names the code may call. The runner's isolate has no
network API unless the list is non-empty, and then only a `fetch` bound to
those hosts. Private and loopback addresses are refused, as
`webhook-allow-private-targets` refuses them for webhooks.

## D-4: absence is loud

`CodeNode::isAvailableForScope()` returns false when the runner probe fails,
so the palette hides it. `FlowNodePreflight` refuses to publish or run a flow
that contains `openregister.code` without the runner, with a message naming
`flow-code-runner`. A run already started whose runner disappears fails the
step, and the edge's `onError` decides, like any other step failure.

## D-5: the trace keeps the code

The step report records the source hash and the source, the items in and out
(within the run log's existing size cap), and the runner's log lines. A
reviewer can see exactly what ran.

## D-6: a right of its own

Writing a step that runs code is more than editing a flow. `flow.code` joins
the action seeds, granted to administrators only by default. Saving a flow
that adds or changes an `openregister.code` node without it is refused.

## Risks

- Hosted instances may not run an extra container. Then the node stays hidden,
  which is the documented behaviour, not a failure.
- `isolated-vm` needs native builds per Node version. The runner image is
  pinned and built in CI.
