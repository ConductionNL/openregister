# Tasks: Integration — Collectives

## Backend

- [x] `CollectiveLink` entity + mapper + migration
- [x] `CollectivesPageService` wrapping Collectives REST API — implemented as `CollectiveLinkService`
- [x] `CollectivesController` — `CollectiveLinksController`
- [x] `CollectivesProvider` — id='collectives', label='Knowledge', icon='BookOpenPageVariant', group='docs', requiredApp='collectives', storage='link-table'
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [x] `CnCollectivesTab.vue` — list with markdown preview, link-existing (collective → page picker), unlink, "Open in Collectives"
- [x] Barrel + tests

## Frontend — Widget

- [x] `CnCollectivesCard.vue`:
  - `user-dashboard`: recent linked pages
  - `app-dashboard`: scoped
  - `detail-page`: inline page content (most recent) with multi-page tabs if >1
  - `single-entity`: page-title chip
- [x] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/collectives.js` — register with `referenceType: 'collectives'`

## Quality

- [x] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [~] E2E: link an existing Collectives page, verify markdown renders in tab; detail-page inline render — deferred to live verification on docker env; unit tests cover backend
- [~] Hide test; reference-property test — deferred to live verification on docker env
