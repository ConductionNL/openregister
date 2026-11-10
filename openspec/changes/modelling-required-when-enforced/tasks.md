# Tasks: modelling-required-when-enforced

## 1. Declaration

- [ ] 1.1 `RequiredWhenDeclaration` with parsing, the operator set and evaluation of design D-1. Verify: `tests/Unit/Service/Schemas/RequiredWhenDeclarationTest.php` for each operator, a list of conditions, and number versus string `eq`.
- [ ] 1.2 Schema-save validation and the `MODIFIERS` entry. Verify: `PropertyValidatorHandlerTest` refuses an unknown `op`, an undeclared `field` and a self condition; `PropertyVocabularyTest` lists the modifier.

## 2. Enforcement

- [ ] 2.1 `RequiredWhenListener` on create and update with collected refusals and fail-soft schema reads, registered beside `DependentValueListener`. Verify: `tests/Unit/Listener/RequiredWhenListenerTest.php` with real `ObjectCreatingEvent` and `ObjectUpdatingEvent` instances.
- [ ] 2.2 Newman: a create with `requestType: "Klacht"` and no `complaintCategory` answers 422 naming the property; with the category it answers 201.

## 3. Docs

- [ ] 3.1 Document `x-openregister-required-when` in `docs/` beside the other property annotations, with the operator table.

Acceptance:
- The same rule refuses the same payload through the API, an import and a flow write.
