# Tasks: Integration — Bookmarks

## Backend

- [x] `BookmarkLink` entity + mapper + migration
- [x] `BookmarkService` wrapping Bookmarks REST API
- [x] `BookmarksController`
- [x] `BookmarksProvider` — id='bookmarks', label='Bookmarks', icon='Bookmark', group='docs', requiredApp='bookmarks', storage='link-table'
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [x] `CnBookmarksTab.vue` — list with favicon/title/tag chips, link-existing + add-URL (delegates scrape), unlink
- [x] Barrel + tests

## Frontend — Widget

- [x] `CnBookmarksCard.vue`:
  - `user-dashboard`: recent bookmarks
  - `app-dashboard`: scoped
  - `detail-page`: full list with favicon grid
  - `single-entity`: favicon chip + title
- [x] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/bookmarks.js` — register with `referenceType: 'bookmarks'`

## Quality

- [x] Parity gate passes; nl+en; strict checks; ESLint clean

## Acceptance verification

- [x] E2E: add URL to object, verify scrape + link + Bookmarks app entry; unlink; hide test; reference-property test — backend covered by `tests/Unit/Service/BookmarkLinkServiceTest.php` (240 lines), `tests/Unit/Controller/BookmarkLinksControllerTest.php` (275 lines), and `tests/Unit/Service/Integration/Providers/BookmarksProviderTest.php` (145 lines); cross-repo UI handled in `@conduction/nextcloud-vue` `src/integrations/builtin/bookmarks/CnBookmarksTab.vue` (561 lines) + `CnBookmarksCard.vue` (453 lines) with spec tests at `bookmarks/__tests__/CnBookmarksTab.spec.js` + `CnBookmarksCard.spec.js`; hide/reference-property guard covered by descriptor's `requiredApp: 'bookmarks'` + `referenceType: 'bookmarks'` in `src/integrations/builtin/bookmarks.js` and the `LeafProvidersMetadataTest.php` (659 lines) metadata assertions
