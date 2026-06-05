# Tasks: Integration — Activity

## Umbrella coordination

- [x] Open a tiny PR against the umbrella's docs/enum to add `'query-time'` as a recognised storage strategy — done: `IntegrationProvider.php` interface docblock already documents all four storage strategies including `query-time`

## Backend

- [x] `ActivityFeedService` — implemented as `ActivityFilterService` (query NC Activity filtered by object + linked entities; NC Activity already contains OR-published cross-integration events via `ActivityService`)
- [x] `ActivityController` — implemented as `ActivityLinksController` (list endpoint only, no mutations; routes: GET /api/objects/{r}/{s}/{id}/activity, /activity/types, /activity/actors)
- [x] `ActivityProvider` — id='activity', label='Activity', icon='Timeline', group='workflow', requiredApp='activity', storage='query-time'; mutation methods throw NotImplemented
- [x] DI-tag, routes, unit tests — DI wired in `Application.php`; routes in `appinfo/routes.php`; unit tests: `ActivityLinksControllerTest`, `ActivityFilterServiceTest`, `ActivityProviderTest`

## Frontend — Tab

- [ ] `CnActivityTab.vue` — feed with event-type filter chips, saved filter prefs, infinite scroll (in @conduction/nextcloud-vue repo)
- [ ] Barrel + tests (in @conduction/nextcloud-vue repo)

## Frontend — Widget

- [ ] `CnActivityCard.vue` (in @conduction/nextcloud-vue repo):
  - `user-dashboard`: "N new today" across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: feed (same layout as tab, smaller height)
  - `single-entity`: single event chip with actor + verb + target
- [ ] Barrel + surface tests (in @conduction/nextcloud-vue repo)

## Registration

- [ ] `src/integrations/builtin/activity.js` — register with `referenceType: 'activity'` (in @conduction/nextcloud-vue repo)

## Quality

- [x] Parity gate; nl+en; strict; phpcs clean on all backend files

## Acceptance verification

- [ ] E2E: activity related to an object appears in tab; filter chips narrow view; "new today" count correct on dashboard
- [ ] Hide test
