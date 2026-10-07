# Tasks: record-star-follow-and-unread-on-screen

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Cross-repo prerequisite

- [ ] 1.1 nextcloud-vue ships `CnFavouriteToggle`, `CnFollowToggle`, `CnUnreadMarker`, the tab badge on `CnDetailPage`, and the `CnIndexPage` options for the star column and the quick filters `favourite`, `recent`, `watching`, `unread` (design D-1). Bump `@conduction/nextcloud-vue` to that release.

## 2. Record page (`src/views/object/ObjectDetails.vue`)

- [ ] 2.1 `CnFavouriteToggle` and `CnFollowToggle` in the header beside the title, optimistic with revert (D-2); the no-notification tooltip of D-4 from the schema's notification rules. Verify: `ObjectDetails.spec.js` toggles both and reverts on a failing request.
- [ ] 2.2 `PUT .../read-state` after the record renders, never on a failed load; "Mark as unread" in `NcActions` sends `DELETE .../read-state` (D-3). Verify: unit test on the load handler for the rendered and the failed case.
- [ ] 2.3 Tab badges from `@self.unreadCounts` on Files and the tabs it names. Verify: unit test with a fixture carrying counts.

## 3. Tables page (`src/views/search/SearchIndex.vue`)

- [ ] 3.1 Turn on the `CnIndexPage` star column, unread marker and the four quick filters; Recent disables the column sort (D-5). `tests/e2e/ci/record-star-follow-and-unread.spec.ts`: star from the record page and find it under Favourites, follow and find it under Following, a colleague's change shows the record bold under Unread, opening it clears it, Mark as unread brings it back.
- [ ] 3.2 Newman: the list with each lens and a page size returns correct totals (guards the screen's counts against a post-page filter).

## 4. Close

- [ ] 4.1 `docs/`: "Star, follow and catch up on records".
- [ ] 4.2 `@spec` tags on every touched method; `openspec validate record-star-follow-and-unread-on-screen --strict`.
- [ ] 4.3 Set rows `rec-favourites`, `rec-follow` and `rec-unread` to built once 3.1 passes.
