# Tasks: records-tree-view

## 1. Backend

- [x] 1.1 `tree` presentation allowed only on a schema with `x-openregister-hierarchy`, config with label and extra fields. Verify: view save tests for accepted and refused.
- [x] 1.2 `_childCount=true` adding `@self.childCount` through one grouped count per page under the caller's RBAC. Verify: unit test asserts one query for a page of 50, API test on a department tree.
- [x] 1.3 Root request returns readable records with an unreadable parent, marked `@self.parentHidden`. Verify: API test with a hidden parent.
  > `_roots=true` on a hierarchy schema narrows the page (and its count) to the records with no parent plus the readable records whose parent the caller may not list; the hidden parents are read in two queries on `buildFilteredQuery()`, the list's own access path, and those records carry `@self.parentHidden: true`. Verified by `tests/Unit/Db/MagicMapper/RootScopeTest.php` over SQLite with an access predicate (a closed parent and a missing parent both count as hidden); the API test against a live instance is not run here.

## 2. Interface

- [ ] 2.1 Tree dispatch in `SearchIndex.vue` with `CnTreeView`, loading a branch on open and opening a record on choose; going back from a record restores the open branches. Verify: `tests/e2e/tree-view.spec.ts` opens two levels of a department tree.
- [ ] 2.2 Keyboard operation (arrows open and close, Enter opens the record). Verify: same e2e drives the tree by keyboard and an axe check passes.
- [ ] 2.3 nextcloud-vue index page tree presentation for a schema with a hierarchy, so leaf apps such as buildiq get it. Verify: component test in nextcloud-vue.

## 3. Docs

- [x] 3.1 `docs/` section on declaring a hierarchy and browsing it as a tree.

Acceptance:
- No request loads more than one level of the tree.
