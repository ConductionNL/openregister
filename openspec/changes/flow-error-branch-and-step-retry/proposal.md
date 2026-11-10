---
kind: code
depends_on: []
---

# Proposal: flow-error-branch-and-step-retry

## Summary

A maker sends a failed step down a path of its own, for example "tell the
functional administrator and park the record", instead of ending the run. A
step that calls a flaky service can retry itself a few times with a pause
before it counts as failed. Both are set per step on OpenRegister's flow
engine, so integriq, buildiq and every other app that draws flows get them.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| integriq | `auto-error-path` | Send a failed step down a fallback path and retry it. | no |

Row `auto-error-path` sits in integriq's matrix with `built.owner`
ConductionNL/openregister (ADR-065 decision 1: fallback paths are flow-engine
semantics, and OpenRegister is the only home for a flow engine). Four of six
competitors rate it `yes`:

- n8n: "packages/workflow/src/interfaces.ts:1716 onError 'continueErrorOutput' routes a failed step to an error branch, and packages/core/src/execution-engine/workflow-execute.ts:1807 retryOnFail retries it up to 5 times with a pause"
- MuleSoft: https://docs.mulesoft.com/mule-runtime/latest/on-error-scope-concept.md "On-Error component (On Error Continue or On Error Propagate)", and https://docs.mulesoft.com/mule-runtime/latest/until-successful-scope.md retries the wrapped steps
- WSO2: "Enable Failover" with "Failover Endpoints" sends a failed call to a fallback backend
- Frank!Framework: "AbstractPipe.java:89 declares an exception forward on every pipe", and "MessageSendingPipe.java:918 setMaxRetries retries a failed call with a growing interval"

The integriq lane's evidence at integriq 378a4bddb: "openregister's
FlowRunService::retry() only queues a brand-new run of the WHOLE flow from the
start, manually, not a per-step fallback+retry."

## What changes

- A step may declare `retry: { attempts, delaySeconds, backoff }`. A failed
  attempt waits and runs the step again, up to `attempts` extra tries. Only
  after the last try does the failure count.
- A step may declare an error branch: `onError: "branch"` with an edge from the
  node's `error` output. The failed items, each with an `error` descriptor
  (message, step, attempt), continue down that edge. Items that succeeded
  continue on the normal edge.
- The run trace shows every attempt and which items took the error branch.
- The shared canvas in nextcloud-vue draws the `error` output; that is the
  library's half, named for its lane.

## Out of scope

- Compensation of earlier steps (integriq row `auto-compensate`, deferred).
- A flow-wide error flow. A branch per step covers the reported cases.

## Impact

- `lib/Service/Flow/FlowEngine.php` (`ON_ERROR_*`, the failure handling at
  `:965` and `outcomeForFailedStep()` at `:1236`).
- `lib/Service/Flow/FlowNodePreflight.php` (edge-level `onError` and `retry`
  validation).
- `lib/BackgroundJob/FlowRunWorker.php:444` (the continue check).
- `openspec/specs/flow-engine/spec.md`.
