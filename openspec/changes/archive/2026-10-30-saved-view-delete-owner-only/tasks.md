# Tasks: saved-view-delete-owner-only

> Checkbox budget: 1 task x 2 = 2 unindented `- [ ]` lines (cap 20).

## Implementation tasks

### Task 1: Owner-or-admin guard before delete
- **spec_ref**: `openspec/changes/saved-view-delete-owner-only/specs/saved-search-views/spec.md#requirement-only-the-owner-or-an-administrator-deletes-a-view`
- **files**: `lib/Service/ViewService.php`, `tests/Unit/Service/ViewServiceDeleteTest.php`
- **acceptance_criteria**:
  - A public or shared view of another owner is not deleted and the caller gets DoesNotExistException
  - Owner and administrator delete as before
- [x] Implement
- [x] Test
