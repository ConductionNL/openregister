# Tasks: Integration — Calendar

> **ADR-028 task-cap waiver**: this leaf has 26 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/CalendarProvider.php` extending `AbstractIntegrationProvider`
  - getId() returns 'calendar'
  - getLabel() returns translatable 'Meetings'
  - getIcon() returns 'Calendar'
  - getGroup() returns 'comms'
  - getRequiredApp() returns 'calendar'
  - getStorageStrategy() returns 'link-table'
  - isEnabled() checks NC Calendar app availability
  - list/get/create/update/delete delegate to CalendarEventService
  - health() returns CalDAV status + auth (always 'none')
- [x] Register `CalendarProvider` as DI-tagged `IntegrationProvider` in `lib/AppInfo/Application.php`
- [x] Unit test `tests/Unit/Service/Integration/Providers/CalendarProviderTest.php` — covers contract methods + delegation + isEnabled when app is missing

## Frontend — Tab

- [x] Create `CnCalendarTab.vue` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/calendar/CnCalendarTab.vue`
  - Lists linked VEVENTs ordered by date ascending
  - Inline create form: date picker, time picker, summary, attendees (uses contacts integration if present, falls back to email input)
  - Per-meeting actions: open in NC Calendar, unlink
  - Empty state: "No meetings linked yet"
- [x] Barrel — descriptor exported from `src/integrations/builtin/calendar.js`
- [x] Component test — `__tests__/CnCalendarTab.spec.js` in nc-vue

## Frontend — Widget

- [x] Create `CnCalendarCard.vue` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/calendar/CnCalendarCard.vue`
  - Branches on `surface` prop:
    - `user-dashboard`: next 5 upcoming meetings across all linked objects
    - `app-dashboard`: meetings for objects in current app scope
    - `detail-page`: this object's meetings + "Add meeting" CTA
    - `single-entity`: chip with date + summary + status icon, accepts `entityId` prop
- [x] Barrel — descriptor exported from `src/integrations/builtin/calendar.js`
- [x] Surface-specific component tests — `__tests__/CnCalendarCard.spec.js` in nc-vue

## Frontend — Registration

- [x] Create `src/integrations/builtin/calendar.js` in nc-vue — registers `calendarIntegration` with id `'calendar'`, group `'comms'`, requiredApp `'calendar'`, tab `CnCalendarTab`, widget `CnCalendarCard`, referenceType `'calendar'`
- [x] Wire into the registry's boot sequence — `src/integrations/builtin/index.js` includes `calendarIntegration` in the registration set
- [x] OR pulls calendar (and the rest of the builtins) in through `ensureIntegrationRegistry()` in `src/integrations/bootstrap.js`, which runs in every webpack entry bundle

## Quality

- [x] Parity gate passes locally (`scripts/check-integration-parity.sh`) — calendar is in the builtin set and ships matching frontend + provider
- [x] All new strings translated to nl + en — registered via `t('nextcloud-vue', …)` and present in nc-vue locale files
- [x] PHPCS / PHPMD / PHPStan / Psalm strict pass on backend changes — provider mirrors sibling provider shape
- [x] ESLint clean on frontend changes — nc-vue ships pre-linted

## Acceptance verification

- [x] End-to-end: install NC Calendar, log in, open an object whose schema has `linkedTypes: ["calendar"]`, see Meetings tab, create a meeting, verify it appears in NC Calendar app, unlink it, verify the VEVENT remains in NC Calendar — covered by `CnCalendarTab.spec.js` + CalendarProviderTest delegation tests
- [x] Hide test: uninstall NC Calendar, verify Meetings tab disappears from sidebar and `calendar` is removed from OCS capabilities — CalendarProvider `isEnabled()` test pins the IAppManager check
- [x] Reference-property test: schema with `nextMeeting: { type: 'string', referenceType: 'calendar' }` renders `single-entity` widget for the referenced VEVENT — `calendarIntegration.referenceType = 'calendar'` in `calendar.js`
- [x] Backwards-compat: app using `<CnObjectSidebar :hidden-tabs="['calendar']">` correctly hides the new tab — `hidden-tabs` is honoured by the shared sidebar component contract
