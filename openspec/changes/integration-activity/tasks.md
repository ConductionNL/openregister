# Tasks: Integration — Activity

## Umbrella coordination

- [x] Open a tiny PR against the umbrella's docs/enum to add `'query-time'` as a recognised storage strategy — `query-time` is documented in `lib/Service/Integration/IntegrationProvider.php` (storage-strategy enum + contract notes)

## Backend

- [x] `ActivityFeedService` — query NC Activity filtered by object + linked entities; merge with OR cross-integration events — implemented as `ActivityFilterService` (Tier-2 read-only filter/paginate over NC IExtension + OR audit-trail merge)
- [x] `ActivityController` — list endpoint only (no mutations) — `ActivityLinksController`
- [x] `ActivityProvider` — id='activity', label='Activity', icon='Timeline', group='workflow', requiredApp='activity', storage='query-time'; mutation methods throw NotImplemented
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [x] `CnActivityTab.vue` — feed with event-type filter chips, saved filter prefs, infinite scroll
- [x] Barrel + tests

## Frontend — Widget

- [x] `CnActivityCard.vue`:
  - `user-dashboard`: "N new today" across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: feed (same layout as tab, smaller height)
  - `single-entity`: single event chip with actor + verb + target
- [x] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/activity.js` — register with `referenceType: 'activity'`

## Quality

- [x] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [~] E2E: activity related to an object appears in tab; filter chips narrow view; "new today" count correct on dashboard — deferred to live verification; ActivityProviderIntegrationTest covers the read path
- [~] Hide test — deferred to live verification on docker env
