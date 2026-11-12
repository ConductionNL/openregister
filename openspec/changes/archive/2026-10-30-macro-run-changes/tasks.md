# Tasks: macro-run-changes

### Task 1: The macro audit entry lists the run's changes
- **spec_ref**: `openspec/changes/macro-run-changes/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made`
- **files**: `lib/Service/Flow/MacroRunChanges.php`, `lib/Service/Flow/MacroActionResolver.php`, `lib/Controller/ObjectActionsController.php`
- [x] Implement: read the changes off the run log, write them on the entry
- [x] Test: `tests/Unit/Service/Flow/MacroRunChangesTest.php`
