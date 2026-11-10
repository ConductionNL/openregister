# object-views-kanban-calendar

## Why

Multiple view types (kanban / calendar / gallery / timeline) are 2026
table-stakes for structured-data platforms — Airtable, Baserow, NocoDB, Grist
and SeaTable all ship them, and they are the headline reason non-technical users
choose those tools. OpenRegister today renders objects only as a list/table; its
saved-view entity (`View`, with a `query` JSON of registers/schemas/filters/sort
and `isDefault`/`isPublic`) has **no presentation dimension** — there is no way
to say "show this register as a kanban grouped by status" or "as a calendar by
due-date".

This is also the concrete first step of the strategic "Tables that scales"
positioning (research 2026-07-23): Nextcloud Tables collapses at ~2k rows, so a
kanban/calendar UI over OpenRegister's dedicated-magic-table storage is a lane
no competitor in the Nextcloud ecosystem occupies. This change delivers the
**kanban** and **calendar** MVP; gallery/timeline are explicit phase-2
follow-ups.

## What Changes

- **Presentation config on saved views:** the `View` entity gains a
  `presentation` block declaring a `viewType`
  (`table` (default) | `kanban` | `calendar`) plus type-specific config:
  - `kanban`: a `groupByField` (an enum/status property) whose distinct values
    become columns; card ordering; which fields render on the card.
  - `calendar`: a `dateField` (a date/datetime property) to plot objects on, and
    an optional `endDateField` for spanning events.
- **Kanban status change via drag = object write:** moving a card between
  columns issues an object update setting `groupByField` to the target column's
  value, through the existing object PATCH/PUT API and its RBAC + lifecycle
  guards (an illegal lifecycle transition is rejected and the card snaps back).
- **Calendar plotting:** objects are placed by `dateField`; the view returns the
  objects for a visible date range using existing object search/filter.
- **Shared Vue components in nextcloud-vue:** `CnObjectKanban` and
  `CnObjectCalendar` live in the shared design-system library (fleet rule: Vue
  logic and shared components belong in nextcloud-vue); OpenRegister consumes
  them and wires them to its object store and the `presentation` config. This
  change's OpenRegister scope is the **`presentation` config persistence + view
  wiring**; the nc-vue component work is a declared dependency.

**BREAKING:** none. `viewType` defaults to `table`; existing views render
exactly as today. The `presentation` block is additive to the `View` `query`.

## Capabilities

### Modified Capabilities

- `saved-search-views`: saved views gain a `presentation` dimension
  (`viewType` + kanban/calendar config) persisted on the `View` entity, a
  kanban drag-to-change-status contract that routes through the guarded object
  write path, and a calendar date-range object query.

## Impact

**Affected code (OpenRegister):** `lib/Db/View.php` (+ its DB table/migration:
add a `presentation` JSON column), `lib/Service/ViewService.php` and
`lib/Controller/ViewsController.php` (accept/return `presentation`; validate
`groupByField`/`dateField` against the view's schema), the object list surface
in `src/views/` that renders a view (dispatch on `viewType` to the nc-vue
component), and the object store call for the kanban drag-write.

**Affected code (nextcloud-vue):** new `CnObjectKanban` and `CnObjectCalendar`
components (declared dependency; shipped via a nc-vue beta and consumed here).

**Tests:** `ViewService`/`ViewsController` unit tests for `presentation`
persistence + field validation; an object-write test proving a kanban column
move updates `groupByField` through the guarded path and that an illegal
lifecycle transition is rejected; a calendar date-range query test. Runnable in
the `nextcloud:34` container. Frontend: component render smoke on live 8080.

**Dependencies:** the nc-vue `CnObjectKanban`/`CnObjectCalendar` components must
land first (or in lockstep). A DB migration adds the `presentation` column.
