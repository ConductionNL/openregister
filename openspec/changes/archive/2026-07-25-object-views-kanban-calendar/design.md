# Design: object-views-kanban-calendar

## Context

The `View` entity (`lib/Db/View.php`) persists saved searches: `uuid`, `name`,
`description`, `owner`, `organisation`, `isPublic`, `isDefault`, and a `query`
JSON holding registers/schemas/filters/sort, plus `favoredBy`. `ViewService`
(`find`/`findAll`/`create`/`update`/`delete`) and `ViewsController` manage them;
`ViewCreated/Updated/DeletedEvent` fire on mutation. There is no presentation
metadata — a view is always rendered as a list. This change adds a
presentation dimension and the kanban/calendar MVP over it.

Per fleet convention, shared Vue components live in nextcloud-vue; OpenRegister
consumes them. So the data contract (this spec) is authored OR-side, and the
rendering components (`CnObjectKanban`, `CnObjectCalendar`) are a nc-vue
dependency.

## Goals / Non-goals

**Goals:** persist a `presentation` config on a view; render a register as
kanban (grouped by an enum/status field, drag = guarded status write) or
calendar (plotted by a date field); keep `table` the default with zero change to
existing views.

**Non-goals (phase-2):** gallery and timeline view types; cross-register
kanban; calendar recurrence; per-user presentation overrides on a shared view;
inline card editing beyond the status move.

## Decisions

### D1 — `presentation` JSON column on `View`

Add a nullable `presentation` JSON property to `View` (with `addType('presentation','json')`)
and a migration adding the column. Shape:
```
{ "viewType": "table|kanban|calendar",
  "kanban":   { "groupByField": "status", "cardFields": ["title","assignee"], "columnOrder": ["todo","doing","done"] },
  "calendar": { "dateField": "dueDate", "endDateField": "endDate" } }
```
`viewType` defaults to `table` when `presentation` is null → existing views
unchanged. `ViewService.create/update` validate that `groupByField`/`dateField`
name real properties on the view's schema (reject otherwise), so a view can't
persist a presentation that can't render.

### D2 — Kanban columns from distinct group values

Columns are the distinct values of `groupByField`. For an enum property the enum
values give a stable, ordered column set (respect `columnOrder` when supplied,
else enum order); for a free field, distinct observed values. Cards are the
objects, fetched through the existing view `query` (filters/sort preserved).

### D3 — Drag = guarded object write, not a bespoke endpoint

Moving a card to another column issues an object update setting
`groupByField` to the target column value via the existing object PATCH/PUT API.
This inherits RBAC, validation and `x-openregister-lifecycle`: an illegal
status transition is **rejected by the write path** and the UI snaps the card
back. No new "move card" controller — the kanban is a thin presentation over the
guarded write. (Consumes the object write path; benefits from
`put-preserve-key-order` but does not depend on it.)

### D4 — Calendar is a date-range object query

The calendar view asks the object store for objects whose `dateField` falls in
the visible range, reusing existing search/filter (a range filter on the date
property). `endDateField` (optional) lets an object span days. No new storage;
the calendar is another presentation over the same magic-table query.

### D5 — Frontend dispatch on `viewType`

The `src/views/` object-list surface dispatches on `view.presentation.viewType`:
`table` → current list; `kanban` → `CnObjectKanban`; `calendar` →
`CnObjectCalendar`, passing the objects, the config, and the write callback. The
components are nc-vue's; OR only wires props/events.

## Risks / Trade-offs

- **nc-vue dependency ordering** — the components must land in a nc-vue beta
  first (or lockstep); the OR change is testable server-side (persistence +
  validation + drag-write contract) independent of the components, so the
  backend can merge and be verified before the UI is wired.
- **Kanban over huge groups** — columns page their cards through the existing
  object query pagination; a column with thousands of cards is bounded, not
  loaded whole (the explicit contrast with Tables' whole-table-in-memory
  ceiling).
- **Lifecycle rejection UX** — the snap-back on a rejected transition must
  surface the reason; the write path already returns the rejection, the
  component shows it.

## Migration / Rollout

One additive DB migration (nullable `presentation` column). Existing views
default to `table`. Ship the nc-vue components, then enable the kanban/calendar
options in the OR view UI. Verify on live 8080.
