## 1. Storage

- [x] 1.1 `lib/Migration/Version1Date20260714010000.php` — add nullable `requester_id` (string 255) to `openregister_approval_steps` (idempotent `hasColumn` guard).
- [x] 1.2 `lib/Db/ApprovalStep.php` — add `requesterId` property/type/getter/setter, add to `hydrate()` field list and `jsonSerialize()`.
- [x] 1.3 `lib/Db/ApprovalStepMapper.php` — add `deleteByChainAndObject(int $chainId, string $objectUuid): int`.
- [x] 1.4 `lib/Db/ApprovalChainMapper.php` — add `findBySchemaAndName(int $schemaId, string $name): ?ApprovalChain`.

## 2. Declarative provisioning

- [x] 2.1 `lib/Service/ApprovalChainAnnotationInstaller.php` — `IEventListener` on `SchemaCreatedEvent`/`SchemaUpdatedEvent`, mirrors `NotificationsAnnotationInstaller`: reads `x-openregister-approval-chains`, upserts one `ApprovalChain` row per entry (`name` = chain key, `schemaId`, `steps` = declared `approvers` mapped to `{order, role}`, `enabled: true`) via `findBySchemaAndName` + insert/update.
- [x] 2.2 Register on `SchemaCreatedEvent`/`SchemaUpdatedEvent` in `Application.php`.

## 3. Gate (block/release)

- [x] 3.1 `lib/Listener/ApprovalChainGateListener.php` on `ObjectUpdatingEvent`: re-derive matched transition from old/new lifecycle field (small self-contained port of `LifecycleValidationListener::findTransitionByTarget`'s shape); no-op when no `x-openregister-approval-chains` entry names that transition.
- [x] 3.2 Resolve required tier: call installer's upsert (idempotent, guarantees the chain row exists) then `findBySchemaAndName`; when `amountField` is declared, select the single highest-`minAmount`-`<=`-object-value tier; else use all declared tiers.
- [x] 3.3 Gate state machine via `ApprovalStepMapper::findByChainAndObject`: none → `initializeChain(requesterId: current user, stepsOverride: resolved tier)`, reject `approval-chain-pending`; any `rejected` → `deleteByChainAndObject` then re-run the "none" branch; all `approved` → allow (no `stopPropagation()`); otherwise → reject again (no duplicate rows).
- [x] 3.4 Register in `Application.php` on `ObjectUpdatingEvent`, immediately after `LifecycleValidationListener`.

## 4. Decide + auto-advance

- [x] 4.1 `ApprovalService::initializeChain()` — add optional `?string $requesterId=null, ?array $stepsOverride=null` params (backward compatible); stamp `requesterId` on created steps; use `$stepsOverride ?? $chain->getStepsArray()`.
- [x] 4.2 `ApprovalService::approveStep()`/`rejectStep()` — add private `resolveSeparationOfDuties(ApprovalChain $chain): bool` (loads schema via injected `SchemaMapper`, reads `x-openregister-approval-chains[$chain->getName()].separationOfDuties`, defaults `true` when the entry exists, `false` when absent); reject with a distinct message when `$step->getRequesterId() === $userId` and separation applies.
- [x] 4.3 `lib/Listener/ApprovalChainAdvanceListener.php` on the existing `ApprovalStepCompletedEvent`: resolve the chain's schema + matching annotation entry; when `onApprove === 'advanceTransition'`, call `TransitionEngine::transition($event->getObjectUuid(), $entry['transition'])`; fail-soft try/catch + logger warning.
- [x] 4.4 Register in `Application.php` on `ApprovalStepCompletedEvent`.

## 5. Tests (failing-path required)

- [x] 5.1 `tests/Unit/Listener/ApprovalChainGateListenerTest.php`: ungated transition passes untouched; first gated attempt is BLOCKED (`approval-chain-pending`) and provisions steps; a still-pending chain blocks a repeat attempt without duplicating steps; threshold routing selects `finance-clerks` for the low-amount object and `finance-directors` for the high-amount object.
- [x] 5.2 Extend `tests/Unit/Service/ApprovalServiceTest.php`: an approver equal to the chain's `requesterId` is REJECTED when the schema declares `separationOfDuties` (existing pure-CRUD chains with no matching annotation are unaffected — regression-check on existing tests); completing the required step marks the chain `approved`/fires `ApprovalStepCompletedEvent` exactly as today (no change) — used as the trigger `ApprovalChainAdvanceListenerTest` asserts against.
- [x] 5.3 `tests/Unit/Listener/ApprovalChainAdvanceListenerTest.php`: `onApprove: advanceTransition` invokes `TransitionEngine::transition()` with the declared action; a chain with no matching annotation (or a different `onApprove` value) does not invoke it.
- [x] 5.4 Run the full OR suite in a `php:8.3-cli` container against a clean `composer install`; establish and report baseline pass/fail counts from `origin/development` before comparing.

## Acceptance criteria

- A transition named by a declared chain cannot complete without its provisioned steps all `approved`; `ObjectUpdatingEvent` rejects with `approval-chain-pending` until then.
- An approver equal to the chain's requester is rejected whenever the schema declares `separationOfDuties` (default true when the annotation exists); pre-existing pure-CRUD chains are unaffected.
- A fully-approved chain releases the gated transition and auto-advances it under `onApprove: advanceTransition`, reusing the existing `ApprovalStepCompletedEvent`.
- Amount-threshold routing selects the correct single tier when `amountField` is declared; parallel-all-tiers behaviour is unchanged when it is absent (shillinq's shipped shape).
- Schemas/chains without a matching `x-openregister-approval-chains` entry are unaffected — existing `ApprovalServiceTest`/`ApprovalControllerTest`/`ApprovalStepEventsTest` suites pass unmodified.
- `composer check:strict` passes; all new and pre-existing PHPUnit tests green in the `php:8.3-cli` container.
