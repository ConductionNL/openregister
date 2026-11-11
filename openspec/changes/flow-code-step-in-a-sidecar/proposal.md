---
kind: code
depends_on: []
---

# Proposal: flow-code-step-in-a-sidecar

## Summary

A developer adds a step of their own JavaScript to a flow, for the reshaping,
parsing or looping that the built-in nodes cannot express. The code runs in a
separate runner container, never inside Nextcloud. It sees only the items it is
given, has a time and memory limit, and reaches the network only where the
flow declares it. Without the runner installed the step is not offered, and a
flow that uses it refuses to run.

This is OpenRegister issue #2066, now written as a change.

## Rows and halves this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| integriq | `auto-code` | Run a step of your own code inside a flow. | no |

Row `auto-code` sits in integriq's matrix with `built.owner`
ConductionNL/openregister: ADR-065 decision 1 makes OpenRegister the only home
for a flow engine, so a code step is an OpenRegister node. All six competitors
in that matrix rate it `yes`, for example:

- n8n: "packages/nodes-base/nodes/Code/Code.node.ts:153 'language' runs JavaScript or Python per item or for all items, executed in task runners"
- MuleSoft: https://docs.mulesoft.com/scripting-module/latest/index.md "Scripting module executes custom logic written in a scripting language"
- Frank!Framework: "core/src/main/java/org/frankframework/senders/JavascriptSender.java:86 runs a JavaScript function as a step"

It is also the OpenRegister half of buildiq's merged change
`logic-script-step` (buildiq rows `logic-custom-code-step`, 3 competitors
yes). Buildiq writes: "openregister: the whole runtime. The `code` step type
and the `flow-code-runner` ExApp are OpenRegister issue #2066 (open) ...
OpenRegister owes the node, its id and config keys, the runner, its limits,
the egress declaration, and the run trace that records the code and the items.
Until it lands this change's step stays hidden."

## What changes

- A node `openregister.code` with config `language` (`javascript`), `source`,
  `mode` (`perItem` or `allItems`), `timeoutMs`, `memoryMb` and `egress` (a
  list of host names, empty by default).
- A runner ExApp `flow-code-runner`: Node 22 in its own container, no
  Nextcloud, no database, no file system. Items in, items out.
- The node is offered only when the runner answers its health check. A flow
  that contains the node refuses to publish and to run without the runner,
  naming it.
- The run trace records the source that ran, its hash, the items in, the items
  out, and the runner's log lines, like any other step.
- Using the node needs a named right, `flow.code`, on top of `flow.edit`.

## Out of scope

- Python. JavaScript first; a second language is a new `language` value later.
- Code inside Nextcloud's own PHP process, in any form.
- Declarative integrations on the same runner (issue #2065).

## Impact

- New `lib/Service/Flow/Nodes/CodeNode.php`, registered in
  `lib/Listener/FlowNodeRegistrationListener.php`.
- New `lib/Service/Flow/CodeRunnerClient.php` over AppAPI, in the shape of
  `lib/Service/Anonymisation/AnonymisationBackendService.php`.
- New repository or directory for the runner image, pinned per instance.
- `lib/actions.seed.json` for `flow.code`.
