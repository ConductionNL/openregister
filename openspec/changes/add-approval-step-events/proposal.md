# Add Approval Step Events

## Problem

`OCA\OpenRegister\Service\ApprovalService` exposes three state-changing public methods:

- `initializeChain(ApprovalChain $chain, string $objectUuid)` — creates the first pending step
- `approveStep(int $stepId, string $userId, string $comment = '')` — moves a step to `approved`
- `rejectStep(int $stepId, string $userId, string $comment = '')` — moves a step to `rejected`

None of these methods dispatch a Nextcloud `IEventDispatcher` event after persisting the state
change. Consuming apps — most immediately procest's `ParaferingNotificationService` and
docudesk's `SigningService` — have no event-driven path to react when an `ApprovalStep` changes
state. Today they must poll `GET /api/approval-steps?chainId={id}&status=pending` after every
decision call to discover what happened next.

This polling dependency is documented as a known gap in both migration specs:

- `procest/openspec/changes/migrate-parafering-to-or-approval-workflow/design.md` — DEFERRED_QUESTIONS §2
- `docudesk/openspec/changes/migrate-signing-to-or-approval-workflow/design.md` — DEFERRED_QUESTIONS §2

It also blocks the fleet-wide consumption umbrella
(`hydra/openspec/changes/consume-or-approval-workflow-fleet-wide`), whose spec describes
providers being "triggered by the `ApprovalStep approved` event" — an event that does not
yet exist.

## Proposed Solution

Add three event classes to `lib/Event/`:

| Class | Fired by | On |
|---|---|---|
| `ApprovalStepInitiatedEvent` | `initializeChain()` | first step created with `status: pending` |
| `ApprovalStepApprovedEvent` | `approveStep()` | step state persisted as `approved` |
| `ApprovalStepRejectedEvent` | `rejectStep()` | step state persisted as `rejected` |

Each event carries the `ApprovalStep` entity, the actor `userId` (approve/reject), the optional
`$comment` string (approve/reject), and a reference to the parent `ApprovalChain`. Events are
dispatched via Nextcloud's `IEventDispatcher` **after** state persistence — so any listener
observes the final committed state.

Consuming apps register `IEventListener` implementations in their own `Application.php`; no
changes to OR's existing schema, API, or service logic are required.

## Capabilities

| Capability | Action |
|---|---|
| `approval-step-events` | **New** — event classes and dispatch in ApprovalService |

## Affected Projects

- [x] `openregister` — only repo changed; 3 event classes + dispatch wiring + tests

## Out of Scope

- Cross-app event payloads: consumers define their own listener logic; this change only defines
  the events OR dispatches.
- New notification types: `ParaferingNotificationService` (procest) and `SigningService`
  (docudesk) subscribe to these events in their own migration specs.
- Changes to `ApprovalChain`, `ApprovalStep` entities, mappers, or the REST API surface.
- New OR columns for step metadata — app-specific context continues to use the `comment` field
  (the `_meta` pattern documented in the fleet-wide umbrella design).
- ApprovalChain-level events (chain completed / chain rejected) — scoped out; can be added
  in a follow-up change once step-level events are in place.
