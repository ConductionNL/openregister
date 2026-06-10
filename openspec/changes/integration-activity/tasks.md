# Tasks: Integration — Activity

## Umbrella coordination

- [~] Open a tiny PR against the umbrella's docs/enum to add `'query-time'` as a recognised storage strategy — deferred to downstream cycle / fleet-wide adoption (handoff)

## Backend

- [~] `ActivityFeedService` — query NC Activity filtered by object + linked entities; merge with OR cross-integration events — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `ActivityController` — list endpoint only (no mutations) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `ActivityProvider` — id='activity', label='Activity', icon='Timeline', group='workflow', requiredApp='activity', storage='query-time'; mutation methods throw NotImplemented
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnActivityTab.vue` — feed with event-type filter chips, saved filter prefs, infinite scroll — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnActivityCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: "N new today" across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: feed (same layout as tab, smaller height)
  - `single-entity`: single event chip with actor + verb + target
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/activity.js` — register with `referenceType: 'activity'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: activity related to an object appears in tab; filter chips narrow view; "new today" count correct on dashboard — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test — deferred to downstream cycle / fleet-wide adoption (handoff)
