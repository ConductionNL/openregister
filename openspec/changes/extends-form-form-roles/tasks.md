# Tasks: extends-form-form-roles

- [x] 1. Add `FORM_ROLES` with `hideOnCreate` to `ExtendingFormDeclaration` and accept it in `validate()`.
- [x] 2. Keep form roles out of `forwards()` and `toProperty()`.
- [x] 3. Publish `formRoles` and `sourceAliases` on the extending-forms read.
- [x] 4. Unit tests: the role is accepted, forwards nothing, never reaches a property, and every role the 2.79.0 consumer reads is accepted.
- [x] 5. Publish `allowedValues` for `inputControl` and `matchType` from the list the save path enforces, with a test that asks the validator.
