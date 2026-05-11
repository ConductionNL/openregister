---
status: proposed
---

# approval-step-events Specification

**OpenSpec change**: `add-approval-step-events`

**Cross-references**: ADR-019 (integration registry), ADR-022 (consume OR abstractions),
ADR-008 (testing contract), `hydra/openspec/changes/consume-or-approval-workflow-fleet-wide`,
`procest/openspec/changes/migrate-parafering-to-or-approval-workflow`,
`docudesk/openspec/changes/migrate-signing-to-or-approval-workflow`.

## Purpose

Define the event API that `OCA\OpenRegister\Service\ApprovalService` dispatches on `ApprovalStep`
state changes, enabling consuming apps to react to approval-chain decisions without polling.

## ADDED Requirements

### Requirement: ApprovalStepInitiatedEvent SHALL Be Dispatched on Chain Initialisation

`ApprovalStepInitiatedEvent` SHALL be dispatched by `ApprovalService::initializeChain()` when
the first `ApprovalStep` in a chain is created with `status: pending`. The event SHALL carry
the `ApprovalStep` entity and the parent `ApprovalChain` reference. Dispatch SHALL occur after
the step is persisted to the database.

#### Scenario: Event dispatched when chain is initialised

- GIVEN a new `ApprovalChain` entity and a target object UUID
- WHEN `ApprovalService::initializeChain($chain, $objectUuid)` is called
- THEN `IEventDispatcher::dispatchTyped()` SHALL be called with an instance of
  `ApprovalStepInitiatedEvent`
- AND `$event->getStep()->getStatus()` SHALL equal `'pending'`
- AND `$event->getStep()->getOrder()` SHALL equal `1`
- AND `$event->getChain()->getId()` SHALL equal `$chain->getId()`

#### Scenario: No event dispatched if chain creation fails

- GIVEN a chain initialisation attempt that throws an exception before step persistence
- WHEN the exception is thrown
- THEN `IEventDispatcher::dispatchTyped()` SHALL NOT be called with `ApprovalStepInitiatedEvent`

---

### Requirement: ApprovalStepApprovedEvent SHALL Be Dispatched on Successful Step Approval

`ApprovalStepApprovedEvent` SHALL be dispatched by `ApprovalService::approveStep()` when a
step is successfully moved to `status: approved`. The event SHALL carry the `ApprovalStep`
entity (in its post-approval state), the parent `ApprovalChain`, the actor `$userId`, and the
optional `$comment`. Dispatch SHALL occur after state persistence and after any automatic
next-step advance.

#### Scenario: Event dispatched when step is approved

- GIVEN an `ApprovalStep` with `status: pending`, `id: 1`, associated with `ApprovalChain` id 42
- AND the requesting user `jvandenberg` is a member of the step's `role` group
- WHEN `ApprovalService::approveStep(1, 'jvandenberg', 'Akkoord')` is called
- THEN `IEventDispatcher::dispatchTyped()` SHALL be called with an instance of
  `ApprovalStepApprovedEvent`
- AND `$event->getStep()->getStatus()` SHALL equal `'approved'`
- AND `$event->getStep()->getDecidedBy()` SHALL equal `'jvandenberg'`
- AND `$event->getUserId()` SHALL equal `'jvandenberg'`
- AND `$event->getComment()` SHALL equal `'Akkoord'`
- AND `$event->getChain()->getId()` SHALL equal `42`

#### Scenario: No event dispatched if approveStep is called on a non-pending step

- GIVEN an `ApprovalStep` with `status: waiting`
- WHEN `ApprovalService::approveStep($stepId, $userId)` is called
- THEN the service SHALL throw an appropriate exception
- AND `IEventDispatcher::dispatchTyped()` SHALL NOT be called with `ApprovalStepApprovedEvent`

---

### Requirement: ApprovalStepRejectedEvent SHALL Be Dispatched on Successful Step Rejection

`ApprovalStepRejectedEvent` SHALL be dispatched by `ApprovalService::rejectStep()` when a
step is successfully moved to `status: rejected`. The event SHALL carry the `ApprovalStep`
entity (in its post-rejection state), the parent `ApprovalChain`, the actor `$userId`, and the
optional `$comment`. Dispatch SHALL occur after state persistence.

#### Scenario: Event dispatched when step is rejected

- GIVEN an `ApprovalStep` with `status: pending`, `id: 2`, in `ApprovalChain` id 43
- WHEN `ApprovalService::rejectStep(2, 'mstoker', 'Niet akkoord met de inhoud')` is called
- THEN `IEventDispatcher::dispatchTyped()` SHALL be called with an instance of
  `ApprovalStepRejectedEvent`
- AND `$event->getStep()->getStatus()` SHALL equal `'rejected'`
- AND `$event->getUserId()` SHALL equal `'mstoker'`
- AND `$event->getComment()` SHALL equal `'Niet akkoord met de inhoud'`
- AND `$event->getChain()->getId()` SHALL equal `43`

#### Scenario: No event dispatched if rejectStep fails validation

- GIVEN an `ApprovalStep` with `status: approved` (already decided)
- WHEN `ApprovalService::rejectStep($stepId, $userId)` is called
- THEN the service SHALL throw an appropriate exception
- AND `IEventDispatcher::dispatchTyped()` SHALL NOT be called with `ApprovalStepRejectedEvent`

---

### Requirement: Event Payload MUST Include Step, Actor, Comment, and Chain

The payload of `ApprovalStepApprovedEvent` and `ApprovalStepRejectedEvent` MUST include all
four components: the `ApprovalStep` entity, the actor `userId` string, the `comment` string
(empty string `''` when no comment was provided), and the parent `ApprovalChain` reference.
The payload of `ApprovalStepInitiatedEvent` MUST include the `ApprovalStep` entity and the
parent `ApprovalChain`.

#### Scenario: Approved event carries all payload fields

- GIVEN `approveStep(5, 'user-a', 'OK')` is called and succeeds
- WHEN the dispatched `ApprovalStepApprovedEvent` is inspected
- THEN `$event->getStep()` SHALL return an `ApprovalStep` instance
- AND `$event->getChain()` SHALL return an `ApprovalChain` instance with the same `id` as the
  step's `chainId`
- AND `$event->getUserId()` SHALL return `'user-a'`
- AND `$event->getComment()` SHALL return `'OK'`

#### Scenario: Optional comment defaults to empty string

- GIVEN `approveStep(5, 'user-a')` is called without a comment argument
- WHEN the dispatched `ApprovalStepApprovedEvent` is inspected
- THEN `$event->getComment()` SHALL return `''`

---

### Requirement: Event Dispatch MUST Occur AFTER State Persistence

Event dispatch MUST occur after the `ApprovalStep` state change is written to the database,
so that any listener querying the OR API or the same mapper observes the new state. Dispatch
MUST NOT occur before the persistence call returns, and MUST NOT be rolled back if a listener
throws — listener exceptions SHALL NOT affect the step state already committed.

#### Scenario: Listener sees committed approved state via mapper

- GIVEN a test listener registered on `ApprovalStepApprovedEvent`
- AND the listener calls `ApprovalStepMapper::find($event->getStep()->getId())`
- WHEN `approveStep()` is called and the event fires
- THEN the mapper call SHALL return the step with `status: 'approved'`
- AND the listener SHALL NOT see the pre-approval `status: pending` value

#### Scenario: Listener exception does not roll back the step state

- GIVEN a listener registered on `ApprovalStepApprovedEvent` that throws a `\RuntimeException`
- WHEN `approveStep()` is called
- THEN the step `status` SHALL remain `'approved'` in the database
- AND the `\RuntimeException` from the listener SHALL propagate to the `approveStep()` caller
  (standard `IEventDispatcher` behaviour — not swallowed by OR)
