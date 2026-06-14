# Add Approval Step Events

## Problem

OpenRegister's `ApprovalService` (`lib/Service/ApprovalService.php`) drives a multi-step, role-gated approval chain for any object. It mutates `ApprovalStep` state — `pending` → `approved` / `rejected` — and persists a record of the decision to `workflow_executions`. But it does **not** dispatch typed events on those state transitions.

This makes the engine effectively a black box to downstream apps:

- **docudesk** wants to migrate its bespoke signing workflow onto OpenRegister's `approval-workflow` capability so signing requests, signature collection, and post-sign archival are driven by the central engine. The next-step in the docudesk roadmap (`openspec/changes/migrate-signing-to-or-approval-workflow`) is blocked because there is no event the docudesk listener can subscribe to in order to advance its own document state when a signer signs.
- **decidesk** has the same problem for multi-step decision approvals — chair, board, council — and a further 10 [~] queued changes across the fleet are all gated on this same hook.
- The only ways to observe a decision today are: (a) poll the workflow_executions table, or (b) hijack `ObjectUpdatedEvent` and reverse-engineer the chain context. Both are fragile.

OR already follows the typed-event pattern for CRUD (`ObjectCreatedEvent`, `ActionCreatedEvent`, etc., dispatched via `IEventDispatcher::dispatchTyped()` — see `lib/Service/ActionService.php`). The approval engine is the gap.

## Proposed Solution

Add four typed events to `lib/Event/`, dispatched at the existing transition points in `ApprovalService`. Each event carries the chain, the step, the deciding user (when applicable), the resolved `statusOnApprove` / `statusOnReject`, and (for approvals) the next pending step or null.

| Event | Dispatched from | When |
|---|---|---|
| `ApprovalStepInitiatedEvent` | `ApprovalService::initializeChain()` and `ApprovalService::approveStep()` | A step transitions to `pending` — i.e. step 1 of a fresh chain, or the next waiting step after a prior approval. |
| `ApprovalStepApprovedEvent` | `ApprovalService::approveStep()` | A `pending` step is approved and persisted. Carries the resolved `statusOnApprove`, the deciding user, and the next step (or null when this was the final step). |
| `ApprovalStepRejectedEvent` | `ApprovalService::rejectStep()` | A `pending` step is rejected. Carries the resolved `statusOnReject` and the deciding user. No follow-up event is dispatched — the chain is terminated. |
| `ApprovalStepCompletedEvent` | `ApprovalService::approveStep()` | The **final** step of the chain is approved (no next waiting step). Convenience event for downstream listeners that only care about full-chain completion. Always fired alongside `ApprovalStepApprovedEvent` for the final step. |

`ApprovalService` gains a constructor dependency on `OCP\EventDispatcher\IEventDispatcher`. Existing behaviour (state mutation, persistence to `workflow_executions`, role verification) is unchanged.

## Capabilities

| Capability | Type | Action |
|---|---|---|
| `approval-workflow` | backend | **Modified** — adds four typed events to the engine's contract. |

## Out of Scope

- No new HTTP endpoint, no UI surface, no schema change.
- No event-replay or persistence of the dispatched events — dispatching is fire-and-forget per the existing `dispatchTyped()` contract.
- The `Initiated` event is intentionally NOT dispatched for the steps created in `waiting` state during `initializeChain()`. It fires only at the moment a step actually becomes `pending` (which is also the moment a downstream notification, reminder, or integration would want to wake up).

## Downstream Consumers (informational)

- `docudesk` → `migrate-signing-to-or-approval-workflow` (unblocks; the leaf listener subscribes to `ApprovalStepInitiatedEvent` to dispatch the signing request and to `ApprovalStepCompletedEvent` to advance the signed-document state).
- `decidesk` → multi-step decision workflows.
- Any leaf app that already wraps OR approval chains for its own status model.

## Backwards Compatibility

Strictly additive. No existing listener, controller, or persisted shape changes. The `ApprovalService` constructor signature gains a final required parameter (`IEventDispatcher`), wired through Nextcloud DI automatically — no caller change required.
