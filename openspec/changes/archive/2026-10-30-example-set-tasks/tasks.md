# Tasks: example-set-tasks

### Task 1: A fragment may put tasks on its objects
- **spec_ref**: `openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects`
- **files**: `lib/Service/ExampleSet/ExampleSetTasks.php`, `ExampleSetCatalogue.php`, `ExampleSetInstaller.php`, `ExampleSetRecord.php`, `lib/Command/ExampleSetInstallCommand.php`
- [x] Implement: parse, check, write once, record, prove
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetTasksTest.php`

### Task 2: Remove deletes the tasks a set wrote
- **spec_ref**: `openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-remove-deletes-the-tasks-a-set-wrote`
- **files**: `lib/Service/ExampleSet/ExampleSetRemover.php`
- [x] Implement: delete recorded tasks first, newest first
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetTasksTest.php`
