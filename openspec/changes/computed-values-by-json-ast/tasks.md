# Tasks: computed-values-by-json-ast

## 1. The catalogue

- [x] 1.1 `GET /api/schemas/calculation-operators`: every operator with arity, operand types, result type and a sentence, generated from the evaluator's dispatch (D-2).

## 2. Authoring

- [x] 2.1 A calculation declaration is a forwardable property key under the `property-vocabulary-published` contract, validated at schema save against the catalogue.
- [x] 2.2 `POST` evaluation of a declaration against a named object or a sample payload, returning the value or the error, with no schema write (D-4).
- [x] 2.3 The properties an expression reads are derived from the AST; circular detection runs on the derived list and a declared list is not required (D-3).

## 3. Documentation of the two engines

- [x] 3.1 State in `computed-fields` which engine an authoring surface offers and which is for code-authored schemas (D-1).

## 4. Tests

- [x] 4.1 `tests/e2e/ci/computed-values-authored.spec.ts`: author a calculation through a form, try it against a sample, save it, create an object and read the derived value.
- [x] 4.2 Unit tests: the catalogue matching the dispatch, the forwarded declaration, the pre-save evaluation error path, the derived dependency list and the circular refusal.
- [x] 4.3 `openspec validate computed-values-by-json-ast --strict`.

## 5. Hand over

- [ ] 5.1 Hand the catalogue to the dossiq lane for `property-definition-management`, with study row B2 and ledger row 3.17.
