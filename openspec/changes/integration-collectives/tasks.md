# Tasks: Integration — Collectives

## Backend

- [ ] `CollectiveLink` entity + mapper + migration
- [ ] `CollectivesPageService` wrapping Collectives REST API
- [ ] `CollectivesController`
- [x] `CollectivesProvider` — id='collectives', label='Knowledge', icon='BookOpenPageVariant', group='docs', requiredApp='collectives', storage='link-table'
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnCollectivesTab.vue` — list with markdown preview, link-existing (collective → page picker), unlink, "Open in Collectives"
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnCollectivesCard.vue`:
  - `user-dashboard`: recent linked pages
  - `app-dashboard`: scoped
  - `detail-page`: inline page content (most recent) with multi-page tabs if >1
  - `single-entity`: page-title chip
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/collectives.js` — register with `referenceType: 'collectives'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: link an existing Collectives page, verify markdown renders in tab; detail-page inline render
- [ ] Hide test; reference-property test
