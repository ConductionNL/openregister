# Tasks: add-approval-step-events

All tasks are in the `openregister` repo. Each task includes an estimate
(S = half-day, M = 1–2 days, L = 3+ days).

---

## [openregister] Event Class Definitions

### OR-1. Define ApprovalStepInitiatedEvent (S)

- [ ] OR-1.1 Create `lib/Event/ApprovalStepInitiatedEvent.php` extending
  `OCP\EventDispatcher\Event`. Constructor accepts `ApprovalStep $step` and
  `ApprovalChain $chain`. Add read-only getters `getStep(): ApprovalStep` and
  `getChain(): ApprovalChain`. Include EUPL-1.2 SPDX docblock header (SPDX inside the
  docblock, not as separate `// SPDX-...` lines).
  - **Acceptance:** Class is instantiable; `$event->getStep()` and `$event->getChain()`
    return the constructor arguments unchanged; `composer check:strict` passes on the file.

### OR-2. Define ApprovalStepApprovedEvent (S)

- [ ] OR-2.1 Create `lib/Event/ApprovalStepApprovedEvent.php` extending
  `OCP\EventDispatcher\Event`. Constructor accepts `ApprovalStep $step`,
  `ApprovalChain $chain`, `string $userId`, `string $comment = ''`. Add getters
  `getStep()`, `getChain()`, `getUserId(): string`, `getComment(): string`.
  Include EUPL-1.2 SPDX docblock header.
  - **Acceptance:** Class is instantiable; all getters return constructor arguments;
    `$comment` defaults to `''`; `composer check:strict` passes.

### OR-3. Define ApprovalStepRejectedEvent (S)

- [ ] OR-3.1 Create `lib/Event/ApprovalStepRejectedEvent.php` extending
  `OCP\EventDispatcher\Event`. Constructor and getters mirror `ApprovalStepApprovedEvent`
  (same signature: step, chain, userId, comment).
  Include EUPL-1.2 SPDX docblock header.
  - **Acceptance:** Class is instantiable; all getters return constructor arguments;
    `composer check:strict` passes.

---

## [openregister] Dispatch Wiring in ApprovalService

### OR-4. Wire dispatch in ApprovalService (M)

- [ ] OR-4.1 Inject `IEventDispatcher $eventDispatcher` into
  `lib/Service/ApprovalService.php` via constructor (or use the existing DI container
  if the service already has one; check before adding a duplicate injection).

- [ ] OR-4.2 In `initializeChain()`: after the first step is created and persisted with
  `status: pending`, call
  `$this->eventDispatcher->dispatchTyped(new ApprovalStepInitiatedEvent($step, $chain))`.
  Dispatch MUST occur after persistence returns; MUST NOT be inside a try/catch that
  swallows the dispatch call.

- [ ] OR-4.3 In `approveStep()`: after step status is persisted as `approved` (and after
  the automatic next-step advance, if any), call
  `$this->eventDispatcher->dispatchTyped(new ApprovalStepApprovedEvent($step, $chain, $userId, $comment))`.

- [ ] OR-4.4 In `rejectStep()`: after step status is persisted as `rejected`, call
  `$this->eventDispatcher->dispatchTyped(new ApprovalStepRejectedEvent($step, $chain, $userId, $comment))`.

  - **Acceptance for OR-4.2–4.4:** Unit tests (OR-5.x) pass; `composer check:strict` passes;
    no existing `ApprovalService` tests regress.

---

## [openregister] Tests

### OR-5. Unit tests for event dispatch in ApprovalService (M)

- [ ] OR-5.1 Create or extend `tests/Unit/Service/ApprovalServiceTest.php`. Add the
  following test cases (mock `IEventDispatcher`, `ApprovalStepMapper`,
  `ApprovalChainMapper` as needed):

  - `testDispatchesInitiatedEventOnChainInit()` — assert `dispatchTyped()` is called once
    with an `ApprovalStepInitiatedEvent`; assert `getStep()->getStatus() === 'pending'`;
    assert `getChain()` matches the supplied chain.
  - `testDispatchesApprovedEventOnApproveStep()` — assert `dispatchTyped()` called with
    `ApprovalStepApprovedEvent`; assert `getUserId()`, `getComment()`, `getStep()->getStatus()`
    match expected values.
  - `testDispatchesRejectedEventOnRejectStep()` — assert `dispatchTyped()` called with
    `ApprovalStepRejectedEvent`; same shape assertions.
  - `testNoEventDispatchedOnNonPendingApprove()` — assert `dispatchTyped()` is NOT called
    when `approveStep()` throws because the step is not pending.
  - `testCommentDefaultsToEmptyString()` — call `approveStep($id, $userId)` without a
    comment; assert `$event->getComment() === ''`.

  - **Acceptance:** All new tests pass; no existing tests regress;
    `composer check:strict` passes.

### OR-6. Unit tests for ApprovalStep event classes (S)

- [ ] OR-6.1 Create `tests/Unit/Event/ApprovalStepInitiatedEventTest.php`. Verify:
  - Constructor stores step and chain; getters return them.
  - Event is an instance of `OCP\EventDispatcher\Event`.

- [ ] OR-6.2 Create `tests/Unit/Event/ApprovalStepApprovedEventTest.php`. Verify:
  - Constructor stores all four fields; getters return them.
  - Comment defaults to `''` when omitted.
  - Event is an instance of `OCP\EventDispatcher\Event`.

- [ ] OR-6.3 Create `tests/Unit/Event/ApprovalStepRejectedEventTest.php`. Verify:
  - Same shape as OR-6.2 for the rejected variant.

  - **Acceptance:** All tests pass; `composer check:strict` passes.

---

## [openregister] Seed Data Fixtures

### OR-7. Create test fixtures for event payloads (S)

- [ ] OR-7.1 Add the three seed-data fixtures from `design.md §Seed Data` as PHP data
  providers in a shared `tests/Unit/Event/ApprovalStepEventFixtures.php` trait or as
  inline `@dataProvider` arrays in the event test classes (whichever matches the project
  convention — check existing `tests/Unit/` for the pattern before choosing).
  - **Acceptance:** At least one test in each event test class uses the fixture data;
    `composer check:strict` passes.

---

## [openregister] Quality Gate

### OR-8. Run composer check:strict and fix all findings (S)

- [ ] OR-8.1 After all PHP changes, run `composer check:strict` (PHPCS, PHPMD, Psalm,
  PHPStan). Fix every finding before submitting — including any pre-existing issues
  encountered in files this change touches.
  - **Acceptance:** `composer check:strict` exits 0 with no suppressions added.

### OR-9. Run unit tests and confirm no regressions (S)

- [ ] OR-9.1 Run `composer test:unit` (or the PHPUnit invocation from `phpunit.xml`).
  Confirm all new tests pass and no existing tests regress.
  - **Acceptance:** PHPUnit exits 0; no skipped tests introduced by this change.
