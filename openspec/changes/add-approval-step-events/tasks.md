# Tasks: Add Approval Step Events

## Capability: approval-workflow (event hooks)

### Task 1 — Create the four event classes in `lib/Event/`
- [x] `ApprovalStepInitiatedEvent` — constructor `(ApprovalChain $chain, ApprovalStep $step, string $objectUuid)`; getters `getChain()`, `getStep()`, `getObjectUuid()`.
- [x] `ApprovalStepApprovedEvent` — constructor `(ApprovalChain $chain, ApprovalStep $step, string $userId, string $statusOnApprove, ?ApprovalStep $nextStep)`; getters plus `isFinalStep(): bool` and `getObjectUuid(): string`.
- [x] `ApprovalStepRejectedEvent` — constructor `(ApprovalChain $chain, ApprovalStep $step, string $userId, string $statusOnReject)`; getters plus `getObjectUuid()`.
- [x] `ApprovalStepCompletedEvent` — constructor `(ApprovalChain $chain, ApprovalStep $finalStep, string $userId, string $statusOnApprove)`; getters plus `getObjectUuid()`.

All four classes:
- Extend `OCP\EventDispatcher\Event`.
- Carry the EUPL-1.2 SPDX docblock header (matching `lib/Event/ObjectCreatedEvent.php`).
- Use constructor property promotion with `readonly`.
- Are immutable — no setters, no mutators.

### Task 2 — Wire `IEventDispatcher` into `ApprovalService`
- [x] Add `private readonly IEventDispatcher $eventDispatcher` as the sixth constructor parameter.
- [x] Import the four event classes and `OCP\EventDispatcher\IEventDispatcher`.

Existing callers do NOT need changes — Nextcloud DI resolves the dependency automatically.

### Task 3 — Dispatch from `initializeChain()`
- [x] When the FIRST step is created (the one with `status = 'pending'`), dispatch `ApprovalStepInitiatedEvent($chain, $step, $objectUuid)`.
- [x] Do NOT dispatch for the `waiting` steps — they fire when they later transition to `pending` via `approveStep()`.

### Task 4 — Dispatch from `approveStep()`
- [x] After persisting the step and the execution history, dispatch `ApprovalStepApprovedEvent($chain, $step, $userId, $statusOnApprove, $nextStep)`.
- [x] If `$nextStep !== null`: ALSO dispatch `ApprovalStepInitiatedEvent($chain, $nextStep, $step->getObjectUuid())`.
- [x] If `$nextStep === null` (final step): ALSO dispatch `ApprovalStepCompletedEvent($chain, $step, $userId, $statusOnApprove)`.

The `Approved` event always fires; the `Initiated` and `Completed` events are mutually exclusive follow-ups.

### Task 5 — Dispatch from `rejectStep()`
- [x] After persisting the step and the execution history, dispatch `ApprovalStepRejectedEvent($chain, $step, $userId, $statusOnReject)`.
- [x] Do NOT dispatch any other event — a rejection terminates the chain.

### Task 6 — Unit tests
- [x] Update `tests/Unit/Service/ApprovalServiceTest.php` to inject an `IEventDispatcher` mock; add tests:
  - `testInitializeChainDispatchesInitiatedEventForFirstStep`
  - `testApproveStepDispatchesApprovedAndInitiatedForNextStep`
  - `testApproveStepDispatchesCompletedEventForFinalStep`
  - `testRejectStepDispatchesRejectedEventOnly`
- [x] Add `tests/Unit/Event/ApprovalStepEventsTest.php` covering constructor-getter round-trip for each of the four events, plus the `isFinalStep()` boolean on the approved event.

### Task 7 — Document hook points
- [x] This `proposal.md` lists the four events, when each fires, and which downstream apps consume them.
- [x] PHPDoc on each event class explains when it is dispatched and which apps subscribe.
- [x] The delta spec at `specs/approval-workflow/spec.md` captures the contract as `MODIFIED Requirements` with Gherkin-style scenarios so downstream apps can rely on a fixed dispatch order.

## Definition of Done

- All four event classes exist under `lib/Event/`.
- `ApprovalService` dispatches in the order specified by Task 3-5.
- `composer check:strict` is clean (no new PHPCS/PHPMD/Psalm/PHPStan violations).
- `vendor/bin/phpunit -c phpunit-unit.xml --filter 'ApprovalStepEvents|ApprovalServiceTest'` is green.
- `docudesk/openspec/changes/migrate-signing-to-or-approval-workflow` can list `add-approval-step-events` as a satisfied dependency.
