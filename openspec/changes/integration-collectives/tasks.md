# Tasks: Integration — Collectives

## Backend

- [~] `CollectiveLink` entity + mapper + migration — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `CollectivesPageService` wrapping Collectives REST API — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `CollectivesController` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `CollectivesProvider` — id='collectives', label='Knowledge', icon='BookOpenPageVariant', group='docs', requiredApp='collectives', storage='link-table'
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnCollectivesTab.vue` — list with markdown preview, link-existing (collective → page picker), unlink, "Open in Collectives" — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnCollectivesCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: recent linked pages
  - `app-dashboard`: scoped
  - `detail-page`: inline page content (most recent) with multi-page tabs if >1
  - `single-entity`: page-title chip
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/collectives.js` — register with `referenceType: 'collectives'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: link an existing Collectives page, verify markdown renders in tab; detail-page inline render — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
