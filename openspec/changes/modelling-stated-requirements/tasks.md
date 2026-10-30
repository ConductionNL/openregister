# Tasks: modelling-stated-requirements

The behaviour is built; these tasks tie the code to the requirements and add the checks that keep it true.

## 1. Tags

- [ ] 1.1 Add `@spec openspec/changes/modelling-stated-requirements/specs/objects-crud/spec.md#REQ-MSR-001` to `ValidateObject::formatValidationError()` and `#REQ-MSR-002` to the validation entry point in `lib/Service/Object/ValidateObject.php`.
- [ ] 1.2 Add `#REQ-MSR-003` to `lib/Listener/CodedValueValidationListener.php` beside its existing tag.
- [ ] 1.3 Add `#REQ-MSR-004` to the methods that open and save the property form in `src/views/schema/SchemaDetails.vue` and `src/modals/schema/EditSchemaProperty.vue`.

## 2. Unit tests

- [ ] 2.1 `tests/Unit/Service/Object/ValidateObjectTest.php`: one case per rule in REQ-MSR-001 (required, type, format, enum, length, item count, pattern), each asserting the message names the property path, including a nested path (REQ-MSR-001).
- [ ] 2.2 Same test: an object property with a nested schema refuses a value missing a nested required key; one without a nested schema accepts any object (REQ-MSR-002).
- [ ] 2.3 `tests/Unit/Listener/CodedValueValidationListenerTest.php`: a value in the scheme passes, a value outside is refused naming property and scheme, a concept added after the schema was saved passes (REQ-MSR-003).

## 3. End-to-end

- [ ] 3.1 `tests/e2e/ci/schema-property-form.spec.ts`: add a property in the form, create an object using it, and try to save the form without a name (REQ-MSR-004).

## 4. Matrix

- [ ] 4.1 When the tasks are done, archive this change into the three main specs and repoint `mod-visual-builder`, `mod-validate`, `mod-json-field-schema` and `mod-vocabulary` in `openspec/parity/capabilities.json` to the main spec holding their requirement.
