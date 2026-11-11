# Tasks: api-atomic-batch

## Implementation tasks

### Task 1: Atomic flag on the bulk save
- **spec_ref**: `openspec/changes/api-atomic-batch/specs/objects-crud/spec.md#requirement-req-atomic-001-an-atomic-batch-is-written-whole-or-not-at-all`
- **files**: `lib/Controller/BulkController.php`, `lib/Db/MagicMapper/MagicBulkHandler.php`
- **acceptance_criteria**:
  - all or nothing
  - row index in the refusal
  - events after commit only
- [x] Implement
- [x] Test (red first): tests/Unit/Controller/BulkAtomicSaveTest.php, 3 of 5 red on development

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
