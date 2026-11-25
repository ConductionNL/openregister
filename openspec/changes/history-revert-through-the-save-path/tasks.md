# Tasks: history-revert-through-the-save-path

## 1. Save path

- [x] 1.1 `RevertHandler` writes through `SaveObject` as an update with `origin: revert`; `ObjectRevertedEvent` still follows. Verify: `tests/Unit/Service/Object/RevertHandlerTest.php` asserts the updating event is dispatched and `ObjectEntityMapper::update()` is not called directly.
- [x] 1.2 Audit entry with action `revert` and `revertedToVersion`. Verify: the same test reads the audit entry.
- [x] 1.3 A restored state the current schema refuses answers 422 with the errors and writes nothing. Verify: test with a schema that gained a required field after the version.

## 2. Answers and spec

- [x] 2.1 Fixed sentences for 403, 404, 423 and 500 with a logged request id. Verify: `RevertControllerTest` asserts no exception text in any body.
- [x] 2.2 Correct the route in `openspec/specs/content-versioning/spec.md` at archive time of this change. Verify: `grep -n "api/revert/" openspec/specs/content-versioning/spec.md` finds nothing.

## 3. Proof

- [x] 3.1 Add `tests/e2e/ci/revert-through-save.spec.ts`: edit a record twice, restore the first version from the history tab, and assert the value and a `revert` audit entry.
  - 2026-11-25: two tests, green on a sandbox Nextcloud 34 with MariaDB (2 passed). A record is edited twice and restored to its first version through `POST .../revert`: it holds the first version's values as a new version, a field the first version did not have is gone, the audit trail has a `revert` entry with `revertedToVersion` set to the first version, and both edits stay in the history. After the schema makes `einddatum` required, restoring the version without it answers 422 naming `einddatum`, and the record's version, values and history are unchanged.
  - The restore is driven through the route, not a tab: the history tab with its restore button is nextcloud-vue's `CnVersionHistory`, mounted by the apps that show a record's history, and OpenRegister's own object page has none. The route is what this change built.
