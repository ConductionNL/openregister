# Tasks: records-form-and-cell-editors

## Implementation tasks

### Task 1: Enum, file and translatable editors in the record form
- **spec_ref**: `openspec/changes/records-form-and-cell-editors/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor`
- **files**: `src/modals/object/ViewObject.vue`, `src/components/i18n/TranslationFieldEditor.vue`
- **acceptance_criteria**:
  - enum renders a select with the declared values
  - file renders a picker
  - translatable renders one input per register language
- [x] Implement
- [x] Test (red first)

### Task 2: Editable cells in the records list
- **spec_ref**: `openspec/changes/records-form-and-cell-editors/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place`
- **files**: `src/views/search/SearchIndex.vue`, `src/components/tables/EditableCell.vue`
- **acceptance_criteria**:
  - saves one field through PATCH
  - shows the refusal and restores the value
  - absent without update rights
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
