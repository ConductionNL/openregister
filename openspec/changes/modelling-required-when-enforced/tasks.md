# Tasks: modelling-required-when-enforced

## 1. Declaration

- [x] 1.1 `RequiredWhenDeclaration` with parsing, the operator set and evaluation of design D-1. Verify: `tests/Unit/Service/Schemas/RequiredWhenDeclarationTest.php` for each operator, a list of conditions, and number versus string `eq`.
- [x] 1.2 Schema-save validation and the `MODIFIERS` entry. Verify: `PropertyValidatorHandlerTest` refuses an unknown `op`, an undeclared `field` and a self condition; `PropertyVocabularyTest` lists the modifier.

## 2. Enforcement

- [x] 2.1 `RequiredWhenListener` on create and update with collected refusals and fail-soft schema reads, registered beside `DependentValueListener`. Verify: `tests/Unit/Listener/RequiredWhenListenerTest.php` with real `ObjectCreatingEvent` and `ObjectUpdatingEvent` instances.
- [x] 2.2 Newman: a create with `requestType: "Klacht"` and no `complaintCategory` answers 422 naming the property; with the category it answers 201.
  - Done (25 Nov): `tests/newman/openregister-required-when.postman_collection.json`, 11 requests and 16 assertions, 0 failed, against a sandbox Nextcloud 34 + OpenRegister main on MariaDB 10.11. It also covers an update that drops the category (422), another request type that needs no category (201), and a schema whose condition names an undeclared field (422 naming it).

## 3. Docs

- [x] 3.1 Document `x-openregister-required-when` in `docs/` beside the other property annotations, with the operator table.

Acceptance:
- The same rule refuses the same payload through the API, an import and a flow write.
