# Tasks: modelling-query-backed-type

## Implementation tasks

### Task 1: View-backed schema read path and write refusal
- **spec_ref**: `openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type`
- **files**: `lib/Service/ObjectService.php`, `lib/Service/Object/SaveObject.php`, `lib/Db/Schema.php`
- **acceptance_criteria**:
  - rows match the view query
  - writes answer 405
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
