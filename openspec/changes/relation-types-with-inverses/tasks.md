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

## Discovery cluster 61

- [ ] C61.1 A split that records a typed relation to the source object and the source entry (D-C61-1).
- [ ] C61.2 Declared inheritance on a relation type: classification, confidentiality, responsible principal, once at creation (D-C61-2).
- [ ] C61.3 A later change to the parent does not change the child, with a test that asserts it.
- [ ] C61.4 An external address as a relation target with a title and a type (D-C61-3).
- [ ] C61.5 A reference recorded from prose creating a typed relation on both sides, removed with the text.
- [ ] C61.6 A depth-bounded graph read with typed, directed edges, marked when truncated, and its export (D-C61-4).
- [ ] C61.7 Tests: the split provenance, the inheritance at creation, the unchanged child, the external relation, the graph bound.
- [ ] C61.8 Hand over to the dossiq lane for `case-merge` and `DeelzaakService`, with candidate ids C-case-core-15, -17, -19, -22, -23, -33 and -38.
