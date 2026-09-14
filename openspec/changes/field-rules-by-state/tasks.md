# Tasks: field-rules-by-state

## 1. Schema

- [ ] 1.1 `states.<state>.fields` in the lifecycle annotation validator with the three refusals.

## 2. Enforcement and publication

- [ ] 2.1 State input on `PropertyRbacHandler` for `hidden` and `readOnly`; merge with property blocks.
- [ ] 2.2 `required` by resulting state in `SaveObject`, on plain saves and on transitions.
- [ ] 2.3 `@self.fieldRules` in `RenderObject`, cached per (schema, state, groups) per request.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/field-rules-by-state.spec.ts`: close without an outcome, see the refusal, fill it, close.
- [ ] 3.2 Unit tests for the validator, the three rule kinds, GraphQL and export inheriting the stripping.

## 4. Discovery wave 1 (CT-2)

- [ ] 4.1 A `hidden`, `readOnly` or `required` entry may carry a condition over the object's data; the rule applies only when it holds.
- [ ] 4.2 The condition operand may be any declared property, including one authored through an extending form.
- [ ] 4.3 `@self.fieldRules` reports the rules that apply to the object as it stands.
- [ ] 4.4 `states.<state>.entry` and `states.<state>.exit` conditions, grouped with and or or, evaluated on every path in and out, with the failing clause named.
- [ ] 4.5 Schema-save validation refuses a condition naming a property the schema does not declare.
- [ ] 4.6 Hand `field-rules-declared` to the dossiq lane with study rows B4, B5, B6 and the second half of C1.
