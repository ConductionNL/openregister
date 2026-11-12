# Tasks: example-set-views

### Task 1: A fragment may declare saved views for the set's own accounts
- **spec_ref**: `openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts`
- **files**: `lib/Service/ExampleSet/ExampleSetViews.php`, `ExampleSetEngineRows.php`, `ExampleSetEngineKinds.php`, `ExampleSetInstaller.php`, `ExampleSetCatalogue.php`, `ExampleSetRecord.php`, `lib/Command/ExampleSetInstallCommand.php`
- [x] Implement: parse, check, write once, record, prove; tasks moved onto the shared path
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetViewsTest.php`

### Task 2: Remove deletes the views a set wrote
- **spec_ref**: `openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-remove-deletes-the-views-a-set-wrote`
- **files**: `lib/Service/ExampleSet/ExampleSetRemover.php`, `ExampleSetEngineKinds.php`
- [x] Implement: delete recorded views with the tasks, newest first
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetViewsTest.php`

### Task 3: The cast carries the jeugdteam regisseur
- **spec_ref**: `openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-the-cast-carries-the-jeugdteam-regisseur`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement: Fatma Kaya, group Sociaal domein
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetCatalogueTest.php`

### Task 4: The cast carries the handler the dossiq boards draw
- **spec_ref**: `openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-the-cast-carries-the-handler-the-dossiq-boards-draw`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement: Pieter Jansen, account id `pieter`, group Vergunningen
- [x] Test: `tests/Unit/Service/ExampleSet/ExampleSetCatalogueTest.php`
