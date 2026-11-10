# Tasks: object-views-kanban-calendar

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Presentation config on views

- [x] 1.1 Add a nullable `presentation` JSON property to `lib/Db/View.php` (`addType('presentation','json')`) + a migration adding the column (REQ-VIEW-PRES-01)
  - `viewType` defaults to `table` when `presentation` is null; existing views render unchanged.
  - Done: `View::$presentation` + `getPresentationFormatted()` default; migration `Version1Date20260724000000`.
- [x] 1.2 In `ViewService.create/update` + `ViewsController`, accept/return `presentation` and validate `groupByField`/`dateField` against the view's schema properties (reject an unrenderable config) (REQ-VIEW-PRES-01)
  - Done: `ViewService::validatePresentationConfig()` + `assertFieldExistsOnViewSchema()`; controller create/update/patch pass `presentation` through and return 400 on `InvalidArgumentException`.

## 2. Kanban (backend contract)

- [x] 2.1 Derive kanban columns from the distinct values of `groupByField` (enum order or `columnOrder`; distinct observed values otherwise); cards come from the existing view `query` with filters/sort preserved (REQ-VIEW-KANBAN-02)
  - Done: `ViewPresentationService::getKanbanBoard()` (+ `deriveColumnValues()`/`discoverDistinctValues()` via the existing facet machinery); cards paginated per column through `ObjectService::searchObjectsPaginated()`. Read-only `GET /api/views/{id}/kanban`.
- [x] 2.2 Drag-to-move updates `groupByField` via the existing guarded object PATCH/PUT path — RBAC + `x-openregister-lifecycle` enforced; an illegal transition is rejected (card snaps back). No bespoke move endpoint (REQ-VIEW-KANBAN-03)
  - Done: verified, not built — no move/drag endpoint exists on `ViewsController` or in `routes.php` (asserted by `ViewsControllerTest::testNoBespokeCardMoveEndpointOnController` + `testKanbanAndCalendarRoutesAreReadOnlyGet`). A future drag write goes through the existing `/api/objects/{register}/{schema}/{id}` PATCH/PUT, whose lifecycle guard is unchanged by this backend phase.

## 3. Calendar (backend contract)

- [x] 3.1 Calendar view returns objects whose `dateField` falls in the visible range via existing search/filter; optional `endDateField` spans days (REQ-VIEW-CAL-04)
  - Done: `ViewPresentationService::getCalendarObjects()`; read-only `GET /api/views/{id}/calendar?start=...&end=...`.

## 4. Frontend + nc-vue

- [x] 4.1 Ship `CnObjectKanban` + `CnObjectCalendar` in nextcloud-vue (shared components; declared dependency) and dispatch `src/views/` object-list on `presentation.viewType` to them, passing objects/config/write-callback (REQ-VIEW-PRES-05)
  - Components published in `@conduction/nextcloud-vue@1.0.0-beta.220` (nc-vue PR#530). OpenRegister bumped to `^1.0.0-beta.220`; `SearchIndex.vue` dispatches on `presentation.viewType` (`table` default → CnIndexPage unchanged; `kanban` → CnObjectKanban; `calendar` → CnObjectCalendar); store `fetchKanbanBoard`/`fetchCalendarObjects` hit the two endpoints; drag-to-move calls the existing guarded object PATCH with resolve/reject rollback. Jest: 21 tests green; production webpack build green with beta.220.

## 5. Verification

- [x] 5.1 Unit tests: `presentation` persistence + field validation; kanban column-move updates `groupByField` through the guarded path and rejects an illegal lifecycle transition; calendar date-range query (REQ-VIEW-PRES-01, REQ-VIEW-KANBAN-03, REQ-VIEW-CAL-04)
  - Run in the `nextcloud:34` container: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit`.
  - Done: `tests/Unit/Db/ViewTest.php`, `tests/Unit/Service/ViewServiceTest.php`, `tests/Unit/Service/ViewPresentationServiceTest.php`, `tests/Unit/Controller/ViewsControllerTest.php` — 123 tests, 270 assertions, green.
- [x] 5.2 Live smoke on a disposable nextcloud:34 + Postgres container (NOT the shared 8080 instance): created a register + `task` schema (enum `status` todo/doing/done + date `dueDate`), 5 objects, and kanban + calendar views (REQ-VIEW-KANBAN-02, REQ-VIEW-CAL-04)
  - **PASS.** Kanban rendered 3 columns in `columnOrder` (todo:1, doing:3, done:1) with cards showing title + dueDate; a guarded PATCH move (todo→doing) persisted server-side and appeared in the `doing` column. Calendar rendered July 2026 with all 5 objects plotted on their exact `dueDate`. Console showed only unrelated sandbox errors (hermiq 404, NC core `user_status` 500) — ZERO from openregister or the CnObjectKanban/CnObjectCalendar components. Verified against `@conduction/nextcloud-vue@1.0.0-beta.220` (RUN in a real browser, not grepped). info.xml bumped to 0.2.17-unstable.16 to defeat the `?v=` cache-buster.

Acceptance criteria:
- A saved view persists a `viewType` of table/kanban/calendar with validated config; `table` remains the default and existing views are unchanged.
- Kanban renders columns from a status/enum field; dragging a card changes the object's value through the guarded write path (illegal transitions rejected).
- Calendar plots objects by a date field over a visible range.
- Shared components live in nextcloud-vue; OpenRegister only wires them.
