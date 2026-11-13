# Tasks: record-star-follow-and-unread-on-screen

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

## 1. Cross-repo prerequisite

- [ ] 1.1 nextcloud-vue ships `CnFollowToggle` with the notifications switch (`merge-follow-and-favourites`), `CnUnreadMarker`, the tab badge on `CnDetailPage`, and the `CnIndexPage` options for the follow column and the quick filters `watching`, `recent`, `unread` (design D-1). Bump `@conduction/nextcloud-vue` to that release.

## 1b. Backend: `manage` in `@self.can`

- [x] 1.2 `lib/Service/Object/RenderObject.php` `applyUpdateRightMarker`: add `manage` from `ObjectScopeResolver::admitsUnconditionally` with the caller's uid and groups, the object's owner and authorization, the same call `WatcherService::requireManage` makes (design D-6); `false` when the decision throws. Verify: `tests/Unit/Service/Object/RenderObjectCanMarkerTest.php` covers owner (true), admin (true), RBAC editor who is not owner (false, and `WatcherService::addWatcher` refuses the same caller), no extend (no `can`).
  - Built: `RenderObject::mayManage()` beside `update` in `setRightsMarker()`. The cases are in `tests/Unit/Service/Object/RenderObjectUpdateRightTest.php`, which already renders `@self.can` (owner, admin, owning group true; non-owning editor false and refused by `WatcherService::addWatcher`; a decision that cannot be taken reads false; no extend, no `can`).
- [ ] 1.3 Newman: `GET .../objects/{register}/{schema}/{id}?_extend=@self.can` as owner and as a non-owning editor; assert `manage` true and false, and that `PUT .../watchers/{userId}` agrees for both.

## 2. Record page (`src/views/object/ObjectDetails.vue`)

- [ ] 2.1 The detail read asks for `_extend=@self.can`. `CnFollowToggle` in the header beside the title, optimistic with revert (D-2); the no-notification tooltip of D-4 from the schema's notification rules. Verify: `ObjectDetails.spec.js` toggles follow and notifications and reverts on a failing request.
  - Partly built (second pass): `CnFollowToggle` (already in `@conduction/nextcloud-vue` 2.180.0, which `package.json` allows) sits in the header with `@self.watching`, `watchNotify`, `watcherCount` and `can.manage`; a failure shows the server's message, a 404 reloads the page; the deep-link detail read asks `_extend=@self.can`. The optimistic flip and revert live in the library component. Open: the D-4 tooltip (`notifies` from the schema's notification rules) and `_extend=@self.can` on reads that reach the page from the list.
- [x] 2.2 `PUT .../read-state` after the record renders, never on a failed load; "Mark as unread" in `NcActions` sends `DELETE .../read-state` (D-3). Verify: unit test on the load handler for the rendered and the failed case.
  - Built: `markReadAfterRender()` sends the PUT once per record after `$nextTick`, from `mounted`/`updated` only when the store holds a record (a failed load sets none); "Mark as unread" sends DELETE and clears the shown record, and stays on it when the server refuses. Helpers in `src/views/object/recordFollow.js`; tests in `ObjectDetails.follow.spec.js` and `recordFollow.spec.js`.
- [ ] 2.3 Tab badges from `@self.unreadCounts` on Files and the tabs it names. Verify: unit test with a fixture carrying counts.

## 3. Tables page (`src/views/search/SearchIndex.vue`)

- [ ] 3.1 Turn on the `CnIndexPage` follow column, unread marker and the three quick filters; Recent disables the column sort (D-5). `tests/e2e/ci/record-star-follow-and-unread.spec.ts`: follow from the record page and find it under Following, switch its notifications off and still find it there, a colleague's change shows the record bold under Unread, opening it clears it, Mark as unread brings it back.
- [ ] 3.2 Newman: the list with each lens and a page size returns correct totals (guards the screen's counts against a post-page filter).

## 4. Close

- [ ] 4.1 `docs/`: "Follow and catch up on records".
- [ ] 4.2 `@spec` tags on every touched method; `openspec validate record-star-follow-and-unread-on-screen --strict`.
- [ ] 4.3 Set rows `rec-favourites`, `rec-follow` and `rec-unread` to built once 3.1 passes.
