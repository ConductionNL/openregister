# Tasks: records-api-and-table-stated

The behaviour is built; these tasks tie the code to the requirements and add the checks that keep it true.

## 1. Tags

- [ ] 1.1 Add `@spec openspec/changes/records-api-and-table-stated/specs/objects-crud/spec.md#REQ-RAT-001` to `ObjectsController::index()`, `show()`, `create()`, `update()`, `patch()` and `destroy()` in `lib/Controller/ObjectsController.php`.
- [ ] 1.2 Add `#REQ-RAT-002` to `handleSort()` in `src/views/search/SearchIndex.vue` and to the column chooser in `src/sidebars/search/SearchSideBar.vue` (`setSearchVisibleColumns`).

## 2. Checks

- [ ] 2.1 Add a folder to the Newman collection under `tests/integration/` that creates a schema, then runs list, create, read, replace, patch and delete on it, plus a 403 on delete for a caller without the right (REQ-RAT-001).
- [ ] 2.2 `tests/e2e/ci/records-table.spec.ts`: sort on a header twice across two pages, pick columns in the sidebar and reload (REQ-RAT-002). If the column choice does not survive a reload, store it per schema in the object store's persisted state and fix it in the same PR.

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change into `objects-crud` and `zoeken-filteren` and repoint `api-rest` and `rec-table` in `openspec/parity/capabilities.json` to the main spec requirement.
