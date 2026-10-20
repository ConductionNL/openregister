# Tasks: saved-view-user-shares

> Checkbox budget: 2 tasks x 2 = 4 unindented `- [ ]` lines (cap 20).

## Implementation tasks

### Task 1: Resolve access for user entries
- **spec_ref**: `openspec/changes/saved-view-user-shares/specs/saved-search-views/spec.md#requirement-a-view-can-be-shared-with-users-in-read-or-write-mode`
- **files**: `lib/Service/Rbac/ViewShareResolver.php`, `tests/Unit/Service/Rbac/ViewShareResolverTest.php`
- **acceptance_criteria**:
  - `{user}` entries match the caller id; widest mode wins; owner wins
- [x] Implement
- [x] Test

### Task 2: Validate user entries
- **spec_ref**: `openspec/changes/saved-view-user-shares/specs/saved-search-views/spec.md#requirement-a-view-can-be-shared-with-users-in-read-or-write-mode`
- **files**: `lib/Service/Rbac/ViewShareListValidator.php`, `lib/Service/Rbac/ViewShareResolver.php`, `lib/Service/Rbac/ViewerReachResolver.php`, `tests/Unit/Service/Rbac/ViewShareResolverTest.php`, `tests/Unit/Service/Rbac/ViewerReachResolverTest.php`
- **acceptance_criteria**:
  - Both/neither, unknown user and duplicate user refused with 400 naming the entry
- [x] Implement
- [x] Test
