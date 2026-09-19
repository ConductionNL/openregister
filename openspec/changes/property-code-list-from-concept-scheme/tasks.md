# Tasks: property-code-list-from-concept-scheme

## 1. Schema and validation

- [ ] 1.1 `x-openregister-concepts` in the schema validator; refuse beside `enum`.
- [ ] 1.2 Value-in-scheme check in `ValidationHandler` through the concept resolution API, cached per (scheme, version) per request.

## 2. Read side

- [ ] 2.1 Bounded options on the schema read with negotiated labels; `@self.labels` on `_extend`; facet labels.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/concept-code-list.spec.ts`: declare a property on a scheme, pick a value in the form, save.
- [ ] 3.2 Unit tests for the validator, the check, deprecation, options paging and labels.

## 4. Discovery wave 1 (CT-4)

- [ ] 4.1 Refuse a choice property with an empty `enum` and no concept scheme, naming the property.
- [ ] 4.2 Hand the editor half to the dossiq lane for `code-lists-from-concepts`: the Properties tab has no input for `enumValues`, study row A4.
- [ ] 4.3 Record that the B3 half, options narrowed by another property's value, is carried by `code-list-lifecycle-and-hierarchy` REQ-CLH-002.
