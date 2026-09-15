# Tasks: field-rules-by-state

## 0. What openregister#3745 already satisfies

Measured before building, against the merged content of
openregister#3745 (`feat(rules): a rule an administrator can list, read back,
try out and explain`, merged 2026-09-14). #3745 published the `stateFieldRule`
kind that this change fills in. Its engine files are NOT edited here.

| What #3745 shipped | Where | What this change still owed |
|---|---|---|
| The `stateFieldRule` kind, its three actions `hideField` / `readOnlyField` / `requireField`, and its evaluation-order position | `lib/Service/Rules/RuleVocabulary.php:53,96` | Nothing. The names are consumed verbatim. |
| The inventory reads `x-openregister-lifecycle.states.<state>.fields` and lists one rule per state | `lib/Service/Rules/RuleInventoryService.php:248-273` | Nothing. The declaration shape below is the one it already reads, including the `condition` and `enabled` keys. |
| The off switch writes `states.<state>.enabled` | `lib/Service/Rules/RuleEnablementService.php:169-171` | Honouring it, so the switch is not a silent no-op. Done in `StateFieldRuleResolver::blockFor()`. |
| `ConditionDialect`, which answers whether a node holds in JSONLogic or in the JSON AST | `lib/Service/Rules/ConditionDialect.php` | Nothing. Consumed for every `when`, `entry` and `exit` condition, which is how task 4.1 and 4.2 land without a second evaluator. |
| `ConditionTracer`, which names the operand that decided | `lib/Service/Rules/ConditionTracer.php` | Consumed, with a local fallback: its walk iterates a LIST of arguments, so the `{"!!": {"var": "x"}}` idiom names nothing. Named in the PR body for the rules part-2 lane. |
| The run log and its recorder | `lib/Service/Rules/RuleRunRecorder.php` | Writing to it, so a refused save is answerable. Done in `StateFieldRuleListener::record()`. |

Everything below was missing and is built here: the declaration's validation,
the enforcement on write, the publication on read, and the surfaces.

## 1. Schema

- [x] 1.1 `states.<state>.fields` in the lifecycle annotation validator with the three refusals.
      `LifecycleAnnotationValidator::validateStates()` and its helpers; refusals
      `lifecycle-state-field-missing`, `lifecycle-state-unknown` and
      `lifecycle-input-hidden-in-target`.

## 2. Enforcement and publication

- [x] 2.1 State input on `PropertyRbacHandler` for `hidden` and `readOnly`; merge with property blocks.
      `stripStateHiddenProperties()` on the read path and `stateBlockedProperties()`
      on the write path, both before the admin short-circuit, so GraphQL and
      export inherit them with no call-site change.
- [x] 2.2 `required` by resulting state in `SaveObject`, on plain saves and on transitions.
      Enforced in `StateFieldRuleListener` on `ObjectCreatingEvent` and
      `ObjectUpdatingEvent` rather than inside `SaveObject`: that is the door
      every save goes through, and it keeps a 5,958-line file out of the diff.
- [x] 2.3 `@self.fieldRules` in `RenderObject`, cached per (schema, state, groups) per request.
      `RenderObject::attachFieldRules()` over the transient `@self` mechanism;
      the memo lives in the resolver and is skipped for a conditional rule,
      because the requirement is the rules that apply to THIS object.

## 3. Tests

- [x] 3.1 `tests/e2e/ci/field-rules-by-state.spec.ts`: close without an outcome, see the refusal, fill it, close.
- [x] 3.2 Unit tests for the validator, the three rule kinds, GraphQL and export inheriting the stripping.
      `LifecycleStateFieldValidationTest`, `StateFieldRuleResolverTest`,
      `StateConditionEvaluatorTest`, `StateFieldRuleListenerTest`. GraphQL and
      export inherit by construction: both call the two `PropertyRbacHandler`
      methods the rules were added to.

## 4. Discovery wave 1 (CT-2)

- [x] 4.1 A `hidden`, `readOnly` or `required` entry may carry a condition over the object's data; the rule applies only when it holds.
      `when` (or `condition`) on the entry, evaluated through `ConditionDialect`.
- [x] 4.2 The condition operand may be any declared property, including one authored through an extending form.
      An extending form's properties land in the schema's own `properties`, so
      the operand is read off the object like any other; asserted in
      `StateFieldRuleResolverTest::testAConditionReadsAPropertyTheLifecycleDoesNotDeclare`.
- [x] 4.3 `@self.fieldRules` reports the rules that apply to the object as it stands.
- [x] 4.4 `states.<state>.entry` and `states.<state>.exit` conditions, grouped with and or or, evaluated on every path in and out, with the failing clause named.
      `StateConditionEvaluator`.
- [x] 4.5 Schema-save validation refuses a condition naming a property the schema does not declare.
- [x] 4.6 Hand `field-rules-declared` to the dossiq lane with study rows B4, B5, B6 and the second half of C1.
      The declaration contract is in the PR body, and is the shape
      `RuleInventoryService` already reads.

## 5. Surfaces

- [x] 5.1 `StateFieldRulesPanel` on the schema workflow tab: which status freezes
      what, for whom, and whether a condition narrows it. Read only, the same
      call `TaskSequencePanel` makes: the schema is the one authoring surface.
