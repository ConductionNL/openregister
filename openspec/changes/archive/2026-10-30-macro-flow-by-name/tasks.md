# Tasks: macro-flow-by-name

### Task 1: A macro can name a flow its schema declares
- **spec_ref**: `openspec/changes/macro-flow-by-name/specs/declared-actions/spec.md#requirement-a-macro-can-name-a-flow-its-schema-declares`
- **files**: `lib/Db/FlowMapper.php`, `lib/Service/Flow/MacroActionBinding.php`, `lib/Service/Flow/MacroActionResolver.php`, `lib/Service/Flow/MacroActionValidator.php`
- [x] Implement: save-time check against the declarations, run-time resolution by name and schema
- [x] Test: `tests/Unit/Service/Flow/MacroFlowByNameTest.php`
