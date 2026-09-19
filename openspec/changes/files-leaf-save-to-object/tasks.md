# Tasks: files-leaf-save-to-object

## 1. Backend

- [ ] 1.1 `FileService::attach(objectId, node)` reusing the upsert pipeline.
- [ ] 1.2 Talk chat export to a `.txt` node, then attach.
- [ ] 1.3 Route for the picker's writable-object title search.

## 2. Plugins

- [ ] 2.1 Files action "Add to object" with the object picker.
- [ ] 2.2 Talk conversation action "Save chat to object".
- [ ] 2.3 Read `fileActions.attachTargets` from the consuming manifest.

## 3. Tests

- [ ] 3.1 Unit tests for attach by node and the writable filter.
- [ ] 3.2 `tests/e2e/ci/files-leaf-save-to-object.spec.ts`: from Files,
      run the action on a file, pick an object, see the file on the object.
