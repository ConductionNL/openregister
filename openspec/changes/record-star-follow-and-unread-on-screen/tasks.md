# Tasks: record-star-follow-and-unread-on-screen

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

## 1. Cross-repo prerequisite

- [ ] 1.1 nextcloud-vue ships `CnFollowToggle` with the notifications switch (`merge-follow-and-favourites`), `CnUnreadMarker`, the tab badge on `CnDetailPage`, and the `CnIndexPage` options for the follow column and the quick filters `watching`, `recent`, `unread` (design D-1). Bump `@conduction/nextcloud-vue` to that release.

## 1b. Backend: `manage` in `@self.can`

- [x] 1.2 `lib/Service/Object/RenderObject.php` `applyUpdateRightMarker`: add `manage` from `ObjectScopeResolver::admitsUnconditionally` with the caller's uid and groups, the object's owner and authorization, the same call `WatcherService::requireManage` makes (design D-6); `false` when the decision throws. Verify: `tests/Unit/Service/Object/RenderObjectCanMarkerTest.php` covers owner (true), admin (true), RBAC editor who is not owner (false, and `WatcherService::addWatcher` refuses the same caller), no extend (no `can`).
  - Built: `RenderObject::mayManage()` beside `update` in `setRightsMarker()`. The cases are in `tests/Unit/Service/Object/RenderObjectUpdateRightTest.php`, which already renders `@self.can` (owner, admin, owning group true; non-owning editor false and refused by `WatcherService::addWatcher`; a decision that cannot be taken reads false; no extend, no `can`).
- [ ] 1.3 Newman: `GET .../objects/{register}/{schema}/{id}?_extend=@self.can` as owner and as a non-owning editor; assert `manage` true and false, and that `PUT .../watchers/{userId}` agrees for both.

## 2. Record page (`src/views/object/ObjectDetails.vue`)

- [x] 2.1 The detail read asks for `_extend=@self.can`. `CnFollowToggle` in the header beside the title, optimistic with revert (D-2); the no-notification tooltip of D-4 from the schema's notification rules. Verify: `ObjectDetails.spec.js` toggles follow and notifications and reverts on a failing request.
  - Partly built (second pass): `CnFollowToggle` (already in `@conduction/nextcloud-vue` 2.180.0, which `package.json` allows) sits in the header with `@self.watching`, `watchNotify`, `watcherCount` and `can.manage`; a failure shows the server's message, a 404 reloads the page; the deep-link detail read asks `_extend=@self.can`. The optimistic flip and revert live in the library component. Open: the D-4 tooltip (`notifies` from the schema's notification rules) and `_extend=@self.can` on reads that reach the page from the list.
  - Finished (fourth pass): the D-4 tooltip. `schemaNotifiesWatchers()` reads the schema's `x-openregister-notifications` (true when a rule lists `{"watchers": true}`), from the schema store when it holds that schema, else `GET /api/schemas/{id}`; an unreadable schema keeps the toggle's default. A record reached from a list row without `@self.can` is read once with `_extend=@self.can` (`fetchRecordCan()`), so the picker shows for those who may manage. Tests in `recordFollow.spec.js` and `ObjectDetails.follow.spec.js`.
- [x] 2.2 `PUT .../read-state` after the record renders, never on a failed load; "Mark as unread" in `NcActions` sends `DELETE .../read-state` (D-3). Verify: unit test on the load handler for the rendered and the failed case.
  - Built: `markReadAfterRender()` sends the PUT once per record after `$nextTick`, from `mounted`/`updated` only when the store holds a record (a failed load sets none); "Mark as unread" sends DELETE and clears the shown record, and stays on it when the server refuses. Helpers in `src/views/object/recordFollow.js`; tests in `ObjectDetails.follow.spec.js` and `recordFollow.spec.js`.
- [x] 2.3 Tab badges from `@self.unreadCounts` on Files and the tabs it names. Verify: unit test with a fixture carrying counts.
  - Built (third pass): `unreadBadges()` in `src/views/object/recordFollow.js` maps `@self.unreadCounts` onto the Files, Syncs, Emails (`emails` + `messages`), Events, Contacts and Deck tabs; a tab with no positive whole count shows no badge. `ObjectDetails.vue` puts an `NcCounterBubble` in each tab's title slot. Tests in `recordFollow.spec.js` and `ObjectDetails.follow.spec.js`.

## 3. Tables page (`src/views/search/SearchIndex.vue`)

- [ ] 3.1 Turn on the `CnIndexPage` follow column, unread marker and the three quick filters; Recent disables the column sort (D-5). `tests/e2e/ci/record-star-follow-and-unread.spec.ts`: follow from the record page and find it under Following, switch its notifications off and still find it there, a colleague's change shows the record bold under Unread, opening it clears it, Mark as unread brings it back.
  - Partly built (fourth pass): `SearchIndex.vue` passes `personalLenses` (Following, Recent, Unread) and `showUnreadRows`; a lens tab maps to `_watching` / `_recent` / `_unread` on the search query from page 1 (`src/views/search/personalLensQuery.js`), and Recent clears the column sort (CnIndexPage disables sorting while it is on). Open: the follow column. CnIndexPage builds each row's follow call from its `register` prop as a string, and this page passes the register object there, so the column would call an address without a register; switching that prop to a string turns on other register-keyed behaviour of the page, which is its own change. The e2e spec needs a live instance.
- [ ] 3.2 Newman: the list with each lens and a page size returns correct totals (guards the screen's counts against a post-page filter).

## 4. Close

- [x] 4.1 `docs/`: "Follow and catch up on records".
  - Built: `docs/features/follow-and-catch-up.md`, listed in `docs/features/README.md`.
- [ ] 4.2 `@spec` tags on every touched method; `openspec validate record-star-follow-and-unread-on-screen --strict`.
- [ ] 4.3 Set rows `rec-favourites`, `rec-follow` and `rec-unread` to built once 3.1 passes.
