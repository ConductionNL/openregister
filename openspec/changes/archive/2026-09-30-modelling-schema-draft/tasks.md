# Tasks: modelling-schema-draft

## Implementation tasks

### Task 1: Draft column, save, publish and discard
- **spec_ref**: `openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published`
- **files**: `lib/Db/Schema.php`, `lib/Migration/`, `lib/Controller/SchemasController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - draft save leaves validation unchanged
  - publish bumps the version once with one changelog entry
  - discard removes the draft
- [x] Implement
- [x] Test (red first)

### Task 2: Draft mode in the schema editor
- **spec_ref**: `openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published`
- **files**: `src/modals/schema/EditSchema.vue`
- **acceptance_criteria**:
  - save as draft, publish and discard buttons
  - a badge shows a pending draft
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
