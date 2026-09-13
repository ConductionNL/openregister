# Tasks: relation-types-with-inverses

## 1. Schema

- [ ] 1.1 Validate `x-openregister-relation` on `$ref` properties and `x-openregister-relation-types` on the schema; refuse the two error cases.
- [ ] 1.2 Resolve `type` to the vocabulary entry on schema read.

## 2. Read path

- [ ] 2.1 Label pair on `RelationHandler::getUses()` rows.
- [ ] 2.2 Referencing property and inverse label on `getUsedBy()` rows, memoised per referencing schema per request.
- [ ] 2.3 The relations leaf renders the labels in both directions.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/relation-types.spec.ts`: link two objects through a typed property, open the far side, read "blocked by".
- [ ] 3.2 Unit tests for validation, resolution and both enrichments; Newman for the two endpoints.
