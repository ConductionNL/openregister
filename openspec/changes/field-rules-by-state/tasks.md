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
