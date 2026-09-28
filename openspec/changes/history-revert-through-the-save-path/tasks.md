# Tasks: history-revert-through-the-save-path

## 1. Save path

- [ ] 1.1 `RevertHandler` writes through `SaveObject` as an update with `origin: revert`; `ObjectRevertedEvent` still follows. Verify: `tests/Unit/Service/Object/RevertHandlerTest.php` asserts the updating event is dispatched and `ObjectEntityMapper::update()` is not called directly.
- [ ] 1.2 Audit entry with action `revert` and `revertedToVersion`. Verify: the same test reads the audit entry.
- [ ] 1.3 A restored state the current schema refuses answers 422 with the errors and writes nothing. Verify: test with a schema that gained a required field after the version.

## 2. Answers and spec

- [ ] 2.1 Fixed sentences for 403, 404, 423 and 500 with a logged request id. Verify: `RevertControllerTest` asserts no exception text in any body.
- [ ] 2.2 Correct the route in `openspec/specs/content-versioning/spec.md` at archive time of this change. Verify: `grep -n "api/revert/" openspec/specs/content-versioning/spec.md` finds nothing.

## 3. Proof

- [ ] 3.1 Add `tests/e2e/ci/revert-through-save.spec.ts`: edit a record twice, restore the first version from the history tab, and assert the value and a `revert` audit entry.
