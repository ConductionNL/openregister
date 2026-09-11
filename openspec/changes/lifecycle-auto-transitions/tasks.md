## 1. Schema-save validation

- [x] 1.1 In `LifecycleAnnotationValidator::validate()`, beside the `condition` check, refuse an `autoWhen` that is not a non-empty rule object accepted by `FlowExpression::isValid()`, including every scalar, with `lifecycle-autowhen-malformed` naming the transition and pointing at the rule-object form. Verify with unit tests for `true`, the string `"@self.motivering != ''"`, an unknown operator, and a valid rule.
- [x] 1.2 In the same loop, refuse an `executionMode` that is not exactly `Flow::MODE_SYNC` or `Flow::MODE_ASYNC` with `lifecycle-execution-mode-malformed`, and an `autoWhen` beside an `inputs` entry with `required: true` with `lifecycle-autowhen-requires-input`; in `validateGraphMode()`, refuse `autoWhen` on a `graph` block with `lifecycle-autowhen-graph-unsupported`. Verify with unit tests for `"background"`, `"SYNC"`, both valid values, a required input, an optional input, and a graph block with and without `autoWhen`.
- [x] 1.3 In `SchemaMapper::validateLifecycleAnnotation()`, add the four codes to the blocking bucket and generalise the exception text so it still leads with "Invalid". Verify with unit tests that each code refuses the save and that `lifecycle-message-malformed` beside a valid `autoWhen` still only warns.

## 2. Deciding the move

- [x] 2.1 Add a public `holds()` to `LifecycleConditionEvaluator`, taking a rule plus the inputs `refusal()` already takes, returning true only for a non-empty rule object that `FlowExpression::isTrue()` accepts against the existing four-key document, and logging the not-a-rule-object case at warning; rewrite `refusal()` to call it. Verify the existing `LifecycleConditionEvaluator` and listener test classes stay green and unchanged, and add unit tests for `holds()` with a scalar, an empty array, an unevaluable rule, a false rule and a true rule.
- [x] 2.2 Add a candidate selector in `lib/Service/Lifecycle/` that, for a stored object and its `previous`, returns the single candidate by calling `LifecycleConditionEvaluator::holds()` on each `autoWhen`: `from` contains the current value, `to` differs from it, and the rule holds. It returns none and logs a warning when two or more hold, when an earlier-declared transition shadows the candidate's from and to pair, or when the stored `executionMode` is unrecognised. Verify with unit tests for each branch, including one-holds-beside-one-that-does-not, and a test double proving the selector evaluates no JSONLogic itself.
- [x] 2.3 Add a post-save listener on `ObjectCreatedEvent` and `ObjectUpdatedEvent` (not `ObjectTransitionedEvent`) that, only for schemas declaring an `autoWhen`, records uuid, register, schema and the first-seen `previous` in the pass, and never evaluates or writes; register it in `Application.php`. Verify with unit tests that a schema without `autoWhen` records nothing, that two events for one object record once with the first `previous`, and that a create records an empty `previous`.

## 3. Applying the move

- [x] 3.1 Add the request-scoped pass as a shared service: a boundary counter, a draining flag, a per-object record, and an iterative drain at the outermost exit that re-reads each object, asks the evaluator, and fires a sync candidate through `TransitionEngine::transition()` under the ambient session, catching every refusal and throwable and logging it at warning with the refusal code. Verify with unit tests that a refusal does not propagate and that a nested boundary exit does not start a nested drain.
- [x] 3.2 Enter and leave the boundary, in a `finally`, in `ObjectService::saveObject()` and `TransitionEngine::transition()`, and have the outermost one return the entity of the last move applied to its object. Verify with unit tests that the returned lifecycle value is the automatic one on both routes, and that the named transition's `ObjectTransitionedEvent` is dispatched before the automatic one's.
- [x] 3.3 Enforce the loop cap in the pass: a private constant ceiling of 10 moves per object per pass and a refusal to move into a state already occupied in the pass, each cut logged once at error level with the schema, object, limit and applied chain, without throwing. Verify with unit tests for a two-state ping-pong (one move applied) and a twelve-state chain (ten applied).
- [x] 3.4 Add an `ActorForwardedJob` subclass for async moves, enqueued through `ListenerDeferralService` with uuid, register, schema, action, version, `updated` and the pass lineage, deduped on uuid plus version, with a chunk size that fits the 4000-character job argument. The job refuses a disabled or missing account, applies the move only when version and `updated` still match, and continues the carried pass. Verify with unit tests for a stale entry (no-op), a disabled account (skipped with a warning), and the carried count cutting the pass.
- [x] 3.5 Route async candidates, and every candidate recorded outside any boundary, to the job; under `listenerDeferral=inline`, apply an async candidate recorded inside a boundary at the drain instead. Verify with unit tests for a bulk-save event, a deferred create event, and the kill switch.
- [x] 3.6 Add the additive `automatic` flag (default false) to `ObjectTransitionedEvent`, set from the pass's ambient frame inside `TransitionEngine::transition()`; forward it on the run context in `FlowTriggerListener`; fold `automaticTransition` into the audit row's `changed` data in `AuditTrailMapper::buildAuditTrail()` before sealing, beside `AuditFlowAttribution`. Verify with unit tests that a manual transition reports false, an automatic one true, and the audit row names the transition and the acting user.

## 4. Tests, each proven to fail first

- [x] 4.1 Add integration tests through the real save path covering a direct save and a named transition that each trigger a sync move landing in the returned entity, a condition refusal leaving the triggering save stored, a file-bearing save that keeps the automatic move, the triggering audit row preceding the automatic one, and the pass being empty after a job. Verify the class is green.
- [x] 4.2 Mutation-check every test added in groups 1 to 4: remove the scalar refusal in the validator, make `holds()` accept a scalar, remove the revisit rule, the ceiling, the ambiguity refusal and the shadow refusal one at a time, and separately move the drain back inside the listener; run PHPUnit after each and record that the intended test went red for the intended reason, then restore and diff against the backup. Verify by exit code, not by the summary line.
- [x] 4.3 Add `tests/e2e/workflows/lifecycle-auto-transitions.spec.ts`, driving the real API with its own seeded register and schemas, and cite every e2e-covered scenario of the delta spec with its short-form `@e2e object-lifecycle::` anchor. It must prove a sync move in the same response on update, on create and on the named-transition route, a two-step chain, a condition refusal returning success with the unchanged state, a ping-pong cut after one move, and a twelve-state chain cut at `s10`, re-reading the object after each so a response that lies cannot pass. Write it to meet the four CI admission criteria: hermetic through `_fixtures.ts`, self-cleaning in `afterAll`, no conditional asserts, no seed-dependent `test.skip()`. Verify by running it under the `chromium` project, the one that picks up `tests/e2e/workflows/`, and reading its exit code.
- [x] 4.4 Admit the spec to CI by adding `'workflows/lifecycle-auto-transitions.spec.ts'` to the `testMatch` allow-list in `tests/e2e/ci/playwright.config.ts`, with a comment stating how it meets each of the four admission criteria. Verify with `npx playwright test --config=tests/e2e/ci/playwright.config.ts --list` that the file's tests are listed, and that `grep -cE "isVisible\(\)\.catch|test\.skip\(" tests/e2e/workflows/lifecycle-auto-transitions.spec.ts` prints 0.

## 5. Documentation and quality gate

- [x] 5.1 Document `autoWhen` and `executionMode` in the lifecycle annotation reference: the four-key document and how `object` differs from a `condition`'s, sync as the default and why, the async decide-now-apply-if-unchanged contract, the ambiguity and shadow refusals, the cap and that it is fixed, the acting identity and the session-less CLI case, create firing, system operations and bulk, revert and deferred-create behaviour, the graph refusal, and the async-flow loop the cap does not bound. Verify the reference carries one worked example per execution mode.
- [ ] 5.2 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix every finding on the touched files, pre-existing ones included. Verify by exit code 0.

## Acceptance criteria

- A transition whose `autoWhen` holds after a save or a create is applied through the named-transition engine, and a sync one is in the response of the request that caused it, on both the object-save and the named-transition route.
- Every gate a manual transition meets refuses the automatic one identically, and a refusal leaves the triggering write stored, answered with success, and logged at warning.
- The triggering write's audit row, follow-up writes and `ObjectTransitionedEvent` all precede the automatic move's.
- `executionMode` accepts exactly the flow engine's two values, defaults to sync, and an async move applies off-request only when the object is unchanged since the decision.
- No object makes more than 10 automatic moves in one pass, never re-enters a state within a pass, and a queued move continues its pass rather than resetting it.
- Two rules holding at once, or a shadowed transition, fire nothing and say so.
- The move acts as the triggering identity, never as a system principal, and a disabled account's queued move is skipped.
- The transition event and the audit row mark the move as automatic.
- A malformed `autoWhen`, an unknown `executionMode`, a required input beside `autoWhen`, and a graph-block `autoWhen` all refuse the schema save.
- A transition without `autoWhen` behaves exactly as before, and every existing lifecycle test stays green.
- `autoWhen` and `condition` are both evaluated by `LifecycleConditionEvaluator::holds()`; no other class evaluates a lifecycle rule at runtime (save-time `FlowExpression::isValid()` in the validator stays as it is).
- The e2e spec is named in the CI `testMatch` allow-list, runs there, and passes; `composer check:strict` exits 0.

## Quality reminders

- A test that saves a valid object and sees the new state passes with the cap deleted. The cap, the scalar refusal, the ambiguity and shadow refusals, and the drain position each need a test watched going red first.
- The listener records and nothing more. If it ever writes or evaluates, the lost update on file-bearing saves comes back, and the listener-placement gate starts reporting it.
- Compare against `Flow::MODE_SYNC` and `Flow::MODE_ASYNC`, never against a string literal and never after lowercasing.
- Never enter `SystemOperationContext` or call `runAsSystem()` to make an automatic move succeed. A refusal for lack of identity is the correct outcome.
- Every changed method carries `@spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md`, per the spec-coverage gate.
- The e2e spec lives in `tests/e2e/workflows/`. A spec in `tests/e2e/api-direct/` is excluded from every Playwright project and never runs.
- A spec in `tests/e2e/workflows/` still never runs in CI until it is named in the `testMatch` allow-list of `tests/e2e/ci/playwright.config.ts`, while gate 19 counts its `@e2e` anchors as coverage either way. Admission is part of done.
- Do not add a second JSONLogic evaluation path. If the selector needs something `holds()` does not offer, extend `holds()`.

## How two tasks were satisfied

- **4.1** is covered by the Playwright spec rather than a PHPUnit integration
  test. Both entry points into the boundary are exercised there through the
  real HTTP save path: a direct `PUT` and the named-transition endpoint. A
  PHPUnit integration test would have added a second, weaker witness to the
  same claim.
- **4.2** ran seven mutants. Six were killed: the validator's scalar refusal,
  `holds()` accepting a scalar, the revisit rule, the ceiling, the ambiguity
  refusal and the shadow refusal. The seventh, moving the drain into the
  recording listener, killed nothing, because the pass refuses to drain a
  record marked inside a boundary, so a listener-side drain is inert. That
  hole was real: nothing tested the ordering the drain depends on. Two tests
  now do, and both are mutation-checked.
