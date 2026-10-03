# Tasks: files-leaf-save-to-object

## 1. Backend

- [ ] 1.1 `FileService::attach(objectId, node)` reusing the upsert pipeline.
- [ ] 1.2 Talk chat export to a `.txt` node, then attach.
- [x] 1.3 The picker's rule, in `lib/Service/Integration/AttachTargetFilter.php`:
      which schemas may be offered (writable AND holding files, two different
      silent failures), which search hits may be shown, and why an attach is
      refused. Pure, so every rule is drivable without a session.
      **An unofferable target is not offered and not COUNTED**, which is
      deliberately the opposite of the contact panel: there a reader is owed a
      true total, so a row they may not read is counted and never named; here
      nobody is owed a count of registers they cannot write to, and a count
      would name which ones exist.
      **A manifest declaration narrows and never widens** — a pin that could
      add a target would be a manifest handing out write access.
      The ROUTE itself waits on 1.1, because there is nothing to attach to
      until the attach exists.

## 2. Plugins

- [ ] 2.1 Files action "Add to object" with the object picker.
- [ ] 2.2 Talk conversation action "Save chat to object".
- [ ] 2.3 Read `fileActions.attachTargets` from the consuming manifest.

## 3. Tests

- [x] 3.1 Unit tests for the writable filter:
      `tests/Unit/Service/Integration/AttachTargetFilterTest.php` (12),
      including both silent failures, the declaration that cannot widen, the
      bounded search, and the refusals that name a schema to nobody who did
      not already pick it. Attach-by-node waits on 1.1.
- [ ] 3.2 `tests/e2e/ci/files-leaf-save-to-object.spec.ts`: from Files,
      run the action on a file, pick an object, see the file on the object.
