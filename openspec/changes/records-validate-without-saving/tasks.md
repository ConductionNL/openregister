# Tasks: records-validate-without-saving

## 1. Checks

- [ ] 1.1 `ObjectSaveCheck` interface and registry; `CodedValueValidationListener` and `DependentValueListener` implement it and call it from `handle()`. Verify: their existing listener tests pass unchanged, plus a direct `check()` test each.

## 2. Contract and route

- [ ] 2.1 `validateObject()` on `ObjectServiceInterface` and `ObjectService`, create and update modes, returning `ValidationVerdict`. Verify: `tests/Unit/Service/ObjectServiceValidateTest.php` asserts a sample that violates a dependent value is invalid on that property, and that no row, audit entry or event appears.
- [ ] 2.2 `POST /api/objects/{register}/{schema}/validate` with the rights of design D-3, placed before the wildcard routes. Verify: `ObjectsControllerTest` for a valid sample, an invalid one, 403 without `create`, and an update sample with `id`.

## 3. Proof and docs

- [ ] 3.1 Newman: validate a sample that breaks a coded value, then save it and read the same property in the 422.
- [ ] 3.2 Document validate-only in `docs/` and in the contract's docblock.

Acceptance:
- For any sample, validate says invalid exactly when save refuses it for a rule.
