## 1. Annotation validation at schema-save time

- [x] 1.1 In `LifecycleAnnotationValidator::validate()`, beside the existing `requires` check, validate a transition's optional `condition`: it MUST be a JSONLogic rule object accepted by `FlowExpression::isValid()`, else collect `lifecycle-condition-malformed` naming the transition. Verify with a unit test asserting the code for an unknown-operator rule.
- [x] 1.2 In the same loop, refuse a SCALAR `condition` with `lifecycle-condition-malformed` and a message pointing at the JSONLogic rule-object form. Verify with a unit test using the action dialect string `"@self.settlementMode == 'reimbursable'"`, which `isValid()` accepts as a literal and which would otherwise authorize every transition.
- [x] 1.3 Validate a transition's optional `message` as either a non-empty string or a per-locale map with an optional `defaultLocale`, mirroring `NotificationAnnotationValidator::validateMessage()` and returning the single code `lifecycle-message-malformed` for every malformed shape. Verify with unit tests for a valid string, a valid map, a number, an empty string, an empty map, an all-empty-values map, and a `defaultLocale` naming an undeclared locale.
- [x] 1.4 In `validateGraphMode()`, refuse a `condition` declared on the `graph` block with `lifecycle-condition-graph-unsupported`, stating that graph-mode conditions are not yet enforced. Verify with unit tests: a graph block with a condition is refused, a graph block without one validates as today, and a schema declaring both a static conditioned transition and a condition-free graph block raises no graph error.
- [x] 1.5 Confirm no regression for annotations declaring neither key. Verify by running the existing `LifecycleAnnotationValidator` test class green and unchanged.

## 2. Runtime evaluation in the listener

- [x] 2.1 Add a private data-document builder to `LifecycleValidationListener` returning exactly `object`, `previous`, `user` (`uid` plus `groups`, empty string and empty list with no session user) and `transition` (`action`, `from`, `to`). Verify with a unit test asserting the four keys and the session-less shape.
- [x] 2.2 Evaluate the matched transition's `condition` through `FlowExpression::isTrue()` AFTER the `authorization` gate and immediately BEFORE the `requires` guard resolution at `LifecycleValidationListener.php:230`. On false, reject with code `lifecycle-condition-unmet` and log at debug level naming the schema, the action and the field so a mistyped `var` path is diagnosable. Verify with unit tests for the passing and refusing paths, asserting the log fires only on refusal.
- [x] 2.3 Resolve the declared `message` for the refusal: a string verbatim, a map by caller language from `IL10N::getLanguageCode()` (not `Accept-Language` negotiation), then `defaultLocale`, then `en`, then the first declared locale, passed through untranslated either way. Verify with unit tests for the string, an exact locale hit, and a miss falling through each step.
- [x] 2.4 Inject `IL10N` and produce the engine's generic fallback message, naming the action and the field, when the transition declares a `condition` and no `message`. Verify with a unit test asserting the text comes from the translation layer, not a bare literal.
- [x] 2.5 Make a named transition be judged by its own declaration: `TransitionEngine` declares the action on a shared `LifecycleActionContext` around its save, and `LifecycleValidationListener`, `LifecycleActionListener` and `ApprovalChainGateListener` resolve through one `LifecycleTransitionResolver` instead of three copies of a first-match loop. Verify with resolver, engine and listener tests, each mutation-checked.

## 3. Tests, each proven to fail first

- [x] 3.1 Cover the listener paths in `tests/Unit/Listener/`: condition holds, condition refuses with a string message, with a map message and with no message, ordering against `authorization`, and no-condition passthrough. Include a `LifecycleGuardRegistry` double that fails the test if `resolve()` is called on a refused condition. Verify the class is green.
- [x] 3.2 Cover the fail-closed runtime rule: a stored condition that cannot be evaluated for the object at hand refuses with `lifecycle-condition-unmet` rather than allowing the transition. Verify the class is green.
- [ ] 3.3 Cover both transition routes reaching the same refusal: a `TransitionEngine::transition()` call and a direct lifecycle-field `saveObject()` both raise `HookStoppedException` carrying `lifecycle-condition-unmet`. Verify the test asserts the code on both paths.
- [x] 3.4 Mutation-check every test added in 1.x, 2.x and 3.x: delete the validator branch, separately invert the runtime refusal, and separately drop the graph-mode refusal; run PHPUnit after each and record that the intended test went RED for the intended reason before restoring. Verify by exit code, not by the summary line; a test that stays green is not finished.

## 4. Worked example and documentation

- [ ] 4.1 Verify the `condition` and per-locale `message` added to the `dataSubjectRequest` `refuse` transition in `lib/Settings/openregister_mock_register.json` import cleanly and enforce as specified: refusing without a `denialGround`, or with `not-applicable`, is refused with the Dutch message for an `nl` caller. Verify by importing the register and attempting both transitions.
- [x] 4.2 Document `condition` and `message` in the lifecycle annotation reference: the JSONLogic form, the four data-document keys, why it is not the flow engine's `json` shape, both `message` shapes and their resolution order, the empty `user` under `occ` and any session-less caller, the evaluation order against `authorization` and `requires`, the distinction from the `@self` string `condition` an `actions[]` entry declares, and that graph-mode conditions are refused pending save-path enforcement. Verify the reference carries the mock register's `refuse` example.

## 5. Quality gate

- [ ] 5.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix every finding on the touched files, pre-existing ones included. Verify by exit code 0.

## Acceptance criteria

- A transition declaring a `condition` is refused with `lifecycle-condition-unmet` when it does not hold and applied when it does, on both the named-action route and a direct lifecycle-field save.
- The refusal carries the transition's declared `message`, resolved to the caller's language for a map and verbatim for a string, and never the expression.
- A transition declaring a `condition` and no `message` is refused with the engine's own generic message, produced through `IL10N`.
- A malformed condition, including a scalar in the action string dialect, cannot be stored: the schema save is refused with `lifecycle-condition-malformed` naming the transition.
- Every malformed `message` shape is refused at schema-save time with the single code `lifecycle-message-malformed`; both the string and the per-locale map validate.
- A `condition` on a `graph` block is refused with `lifecycle-condition-graph-unsupported`; a graph block without one validates exactly as today.
- The evaluation order is `authorization`, then `condition`, then `requires`, and a refused condition never resolves the guard.
- A transition declaring no `condition` behaves exactly as before, and every existing lifecycle test stays green.
- An expression that cannot be evaluated refuses the transition; it never allows it.
- The mock register imports cleanly and its `refuse` transition demonstrates the feature end to end.
- `composer check:strict` exits 0 and every new PHPUnit test is green.

## Quality reminders

- Every test for the malformed, fail-closed and graph-refusal paths is proven RED against a deliberate break before it counts as done. A happy-path-only assertion passes with the silent-false defect present and proves nothing.
- No new dependency: `jwadhams/json-logic-php` is already required, and `FlowExpression` is the only expression facade this change may use.
- The engine's fallback message is translated through `IL10N`; the author's `message` is passed through untouched and never translated. The three existing rejection codes stay English literals in this change, which is a knowing inconsistency recorded in design.md, with ADR-025 holding the rules for the follow-up.
- Both touched PHP files carry `@spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md` on the changed methods, per the spec-coverage gate.
- The mock register edit is a text insertion with zero deleted lines. Never reserialise that file to add a key.
