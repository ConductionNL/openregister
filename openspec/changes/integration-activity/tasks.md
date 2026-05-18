# Tasks: Integration — Activity

## Umbrella coordination

- [ ] Open a tiny PR against the umbrella's docs/enum to add `'query-time'` as a recognised storage strategy

## Backend

- [ ] `ActivityFeedService` — query NC Activity filtered by object + linked entities; merge with OR cross-integration events
- [ ] `ActivityController` — list endpoint only (no mutations)
- [x] `ActivityProvider` — id='activity', label='Activity', icon='Timeline', group='workflow', requiredApp='activity', storage='query-time'; mutation methods throw NotImplemented
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnActivityTab.vue` — feed with event-type filter chips, saved filter prefs, infinite scroll
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnActivityCard.vue`:
  - `user-dashboard`: "N new today" across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: feed (same layout as tab, smaller height)
  - `single-entity`: single event chip with actor + verb + target
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/activity.js` — register with `referenceType: 'activity'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: activity related to an object appears in tab; filter chips narrow view; "new today" count correct on dashboard
- [ ] Hide test
