# Tasks: example-set-notes-and-read-states

### Task 1: Notes
- **spec_ref**: `openspec/changes/example-set-notes-and-read-states/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects`
- **files**: `lib/Service/ExampleSet/ExampleSetNotes.php`, `lib/Service/ExampleSet/ExampleSetEngineKinds.php`, `lib/Service/ExampleSet/ExampleSetCatalogue.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetNotesAndReadStatesTest.php`

### Task 2: Read states
- **spec_ref**: `openspec/changes/example-set-notes-and-read-states/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when`
- **files**: `lib/Service/ExampleSet/ExampleSetReadStates.php`, `lib/Service/ExampleSet/ExampleSetEngineKinds.php`, `lib/Service/ExampleSet/ExampleSetCatalogue.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetNotesAndReadStatesTest.php`

### Task 3: Remove
- **spec_ref**: `openspec/changes/example-set-notes-and-read-states/specs/example-sets/spec.md#requirement-remove-takes-away-the-notes-and-read-states-a-set-wrote`
- **files**: `lib/Service/ExampleSet/ExampleSetRecord.php`, `lib/Service/ExampleSet/ExampleSetRemover.php`, `lib/Service/ExampleSet/ExampleSetInstaller.php`, `lib/Command/ExampleSetInstallCommand.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetNotesAndReadStatesTest.php`, `tests/Unit/Service/ExampleSet/ExampleSetInstallerTest.php`
