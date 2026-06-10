# Tasks: Integration — Bookmarks

## Backend

- [~] `BookmarkLink` entity + mapper + migration — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `BookmarkService` wrapping Bookmarks REST API — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `BookmarksController` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `BookmarksProvider` — id='bookmarks', label='Bookmarks', icon='Bookmark', group='docs', requiredApp='bookmarks', storage='link-table'
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnBookmarksTab.vue` — list with favicon/title/tag chips, link-existing + add-URL (delegates scrape), unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnBookmarksCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: recent bookmarks
  - `app-dashboard`: scoped
  - `detail-page`: full list with favicon grid
  - `single-entity`: favicon chip + title
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/bookmarks.js` — register with `referenceType: 'bookmarks'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes; nl+en; strict checks; ESLint clean — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: add URL to object, verify scrape + link + Bookmarks app entry; unlink; hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
