# Tasks: property-vocabulary-published

## 1. The vocabulary

- [x] 1.1 `GET /api/schemas/property-vocabulary`: every accepted type with its constraint keys, its formats and a sentence, generated from the validator's own list (D-1).
- [x] 1.2 Each entry carries whether converting a populated property to it is supported (D-4).

## 2. Validation

- [x] 2.1 A schema save naming a type or a constraint key outside the vocabulary fails with 422 naming it (D-3).
- [x] 2.2 A regression test that every schema shipped in the repository still validates.

## 3. The extending form

- [x] 3.1 An app declares which vocabulary keys its property form forwards; the declaration is validated against the vocabulary (D-2).
- [x] 3.2 A forwarded property is validated exactly like a hand-written one.
- [x] 3.3 The declared narrowing is readable, so the difference between the app's list and the vocabulary can be counted.

## 4. Tests

- [x] 4.1 `tests/e2e/ci/property-vocabulary.spec.ts`: read the vocabulary, author a property with a constraint key, save an object that breaks it and read the refusal.
- [x] 4.2 Unit tests: the generated list matching the validator, the unknown type, the unknown forwarded key and the conversion caveat.
- [x] 4.3 `openspec validate property-vocabulary-published --strict`.

## 5. Hand over

- [x] 5.1 Hand the vocabulary to the dossiq lane for `property-definition-management`, with study rows A1, A2, A3, A5, A9, A11, A12, A13, B7 and B10. The key list is in the PR body and at `GET /api/schemas/property-vocabulary`.
- [x] 5.2 Raise the `x-openregister-extends-form` finding with the dossiq lane: the annotation uses the platform namespace and the platform does not define it. It does now, and dossiq's eight-key `map` is the shape that was defined.
