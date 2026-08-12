# Tasks: object-views-kanban-calendar

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Presentation config on views

- [ ] 1.1 Add a nullable `presentation` JSON property to `lib/Db/View.php` (`addType('presentation','json')`) + a migration adding the column (REQ-VIEW-PRES-01)
  - `viewType` defaults to `table` when `presentation` is null; existing views render unchanged.
- [ ] 1.2 In `ViewService.create/update` + `ViewsController`, accept/return `presentation` and validate `groupByField`/`dateField` against the view's schema properties (reject an unrenderable config) (REQ-VIEW-PRES-01)

## 2. Kanban (backend contract)

- [ ] 2.1 Derive kanban columns from the distinct values of `groupByField` (enum order or `columnOrder`; distinct observed values otherwise); cards come from the existing view `query` with filters/sort preserved (REQ-VIEW-KANBAN-02)
- [ ] 2.2 Drag-to-move updates `groupByField` via the existing guarded object PATCH/PUT path — RBAC + `x-openregister-lifecycle` enforced; an illegal transition is rejected (card snaps back). No bespoke move endpoint (REQ-VIEW-KANBAN-03)

## 3. Calendar (backend contract)

- [ ] 3.1 Calendar view returns objects whose `dateField` falls in the visible range via existing search/filter; optional `endDateField` spans days (REQ-VIEW-CAL-04)

## 4. Frontend + nc-vue

- [ ] 4.1 Ship `CnObjectKanban` + `CnObjectCalendar` in nextcloud-vue (shared components; declared dependency) and dispatch `src/views/` object-list on `presentation.viewType` to them, passing objects/config/write-callback (REQ-VIEW-PRES-05)
  - nc-vue components land in a beta first (or lockstep); backend is verifiable independently.

## 5. Verification

- [ ] 5.1 Unit tests: `presentation` persistence + field validation; kanban column-move updates `groupByField` through the guarded path and rejects an illegal lifecycle transition; calendar date-range query (REQ-VIEW-PRES-01, REQ-VIEW-KANBAN-03, REQ-VIEW-CAL-04)
  - Run in the `nextcloud:34` container: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit`.
- [ ] 5.2 Live smoke on 8080: create a kanban view grouped by an enum property, drag a card across columns, confirm the object's field changed; create a calendar view and confirm objects plot by date (REQ-VIEW-KANBAN-02, REQ-VIEW-CAL-04)

Acceptance criteria:
- A saved view persists a `viewType` of table/kanban/calendar with validated config; `table` remains the default and existing views are unchanged.
- Kanban renders columns from a status/enum field; dragging a card changes the object's value through the guarded write path (illegal transitions rejected).
- Calendar plots objects by a date field over a visible range.
- Shared components live in nextcloud-vue; OpenRegister only wires them.
