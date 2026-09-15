# Tasks: a-conflicting-save-shows-the-other-value

## 1. The conflict body

- [ ] 1.1 The 409 body lists each conflicting property with the sent, read and stored values.
- [ ] 1.2 Only properties the caller changed and somebody else changed are listed.
- [ ] 1.3 The body is filtered by field-level security; a refused property is named without values.

## 2. Every write

- [ ] 2.1 The version assertion moves into the save pipeline so PUT asserts as PATCH does.
- [ ] 2.2 A write with no expected version keeps today's behaviour.

## 3. The record

- [ ] 3.1 A refused write writes an audit entry naming both versions and the actor.

## 4. Tests

- [ ] 4.1 Unit tests for the three-value body, the intersection rule, the filtered property and the PUT assertion.
- [ ] 4.2 A Newman request asserting the 409 shape.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
