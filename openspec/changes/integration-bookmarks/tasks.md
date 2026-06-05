# Tasks: Integration — Bookmarks

## Backend

- [x] `BookmarkLink` entity + mapper + migration
- [x] `BookmarkService` wrapping Bookmarks REST API
- [x] `BookmarksController`
- [x] `BookmarksProvider` — id='bookmarks', label='Bookmarks', icon='Bookmark', group='docs', requiredApp='bookmarks', storage='link-table'
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnBookmarksTab.vue` — list with favicon/title/tag chips, link-existing + add-URL (delegates scrape), unlink
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnBookmarksCard.vue`:
  - `user-dashboard`: recent bookmarks
  - `app-dashboard`: scoped
  - `detail-page`: full list with favicon grid
  - `single-entity`: favicon chip + title
- [ ] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/bookmarks.js` — register with `referenceType: 'bookmarks'`

## Quality

- [x] Parity gate passes; nl+en; strict checks; ESLint clean

## Acceptance verification

- [ ] E2E: add URL to object, verify scrape + link + Bookmarks app entry; unlink; hide test; reference-property test
