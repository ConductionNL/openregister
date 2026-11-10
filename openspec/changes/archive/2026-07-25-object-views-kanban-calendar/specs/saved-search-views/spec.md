# saved-search-views Specification (delta)

---
status: proposed
---

## Purpose delta

Saved views gain a presentation dimension: a `viewType` (table / kanban /
calendar) with type-specific config persisted on the view, a kanban
drag-to-change-status contract that routes through the guarded object write
path, and a calendar date-range object query. `table` remains the default so
existing views are unchanged.

## ADDED Requirements

### Requirement: Views persist a validated presentation config (REQ-VIEW-PRES-01)

A saved view MUST support a nullable `presentation` block declaring a `viewType`
of `table`, `kanban`, or `calendar`, plus type-specific config
(kanban: `groupByField`, optional `cardFields`, optional `columnOrder`;
calendar: `dateField`, optional `endDateField`). When `presentation` is null the
view MUST render as `table` (default), leaving existing views unchanged. On
create/update the service MUST validate that `groupByField`/`dateField` name
real properties on the view's schema and MUST reject a presentation that cannot
render.

#### Scenario: Save a kanban view

- **WHEN** a client saves a view with `presentation.viewType = kanban` and
  `groupByField = status` where `status` exists on the schema
- **THEN** the view persists the presentation and returns it on read.

#### Scenario: Reject an unrenderable presentation

- **WHEN** a client saves a kanban view whose `groupByField` is not a property
  of the view's schema
- **THEN** the save is rejected with a validation error.

#### Scenario: Legacy view still renders as table

- **GIVEN** a view saved before this change (no `presentation`)
- **WHEN** it is read
- **THEN** its `viewType` is `table` and it renders exactly as before.

### Requirement: Kanban columns and cards (REQ-VIEW-KANBAN-02)

A kanban view MUST render one column per distinct value of `groupByField` —
using enum order (or `columnOrder` when supplied) for enum properties, and
distinct observed values otherwise — and MUST populate cards from the objects
returned by the view's existing `query` (filters and sort preserved). Cards MUST
be paginated through the existing object-query pagination rather than loading a
whole column at once.

#### Scenario: Columns from an enum status

- **GIVEN** a `status` enum property with values `todo`, `doing`, `done`
- **WHEN** a kanban view groups by `status`
- **THEN** three columns render in enum (or configured) order, each holding the
  objects with that status, paginated.

### Requirement: Kanban drag changes status via the guarded write path (REQ-VIEW-KANBAN-03)

Moving a card to another column MUST update the object's `groupByField` to the
target column's value through the existing guarded object update API, enforcing
RBAC, validation and `x-openregister-lifecycle`. There MUST NOT be a bespoke
"move card" endpoint. An update that violates a lifecycle transition MUST be
rejected by the write path, and the view MUST reflect the rejection (the card
returns to its origin column with the reason surfaced).

#### Scenario: Legal move persists

- **WHEN** a user drags a card from `todo` to `doing` and the transition is
  permitted
- **THEN** the object's `status` is updated to `doing` through the guarded write
  path and the card stays in the `doing` column.

#### Scenario: Illegal transition snaps back

- **GIVEN** a lifecycle that forbids `done` → `todo`
- **WHEN** a user drags a `done` card to `todo`
- **THEN** the write is rejected and the card returns to `done` with the reason
  shown.

### Requirement: Calendar plots objects by a date field over a range (REQ-VIEW-CAL-04)

A calendar view MUST place objects by their `dateField` value and MUST return
the objects whose `dateField` falls within the visible date range using the
existing object search/filter. When an `endDateField` is configured, an object
MUST span from `dateField` to `endDateField`.

#### Scenario: Objects appear on their date

- **GIVEN** objects with a `dueDate` property
- **WHEN** a calendar view over `dueDate` shows a given month
- **THEN** each object appears on its `dueDate` and only objects within the
  visible range are returned.

### Requirement: Presentation components are shared and wired, not owned by OR (REQ-VIEW-PRES-05)

The kanban and calendar rendering components MUST be shared nextcloud-vue
components (`CnObjectKanban`, `CnObjectCalendar`); OpenRegister MUST consume them
and dispatch its object-list surface on `presentation.viewType`, passing the
objects, the presentation config, and the write callback. OpenRegister MUST NOT
re-implement the rendering locally.

#### Scenario: OR dispatches to the shared component

- **WHEN** a view with `viewType = kanban` is opened in OpenRegister
- **THEN** OpenRegister renders it via the nextcloud-vue `CnObjectKanban`
  component wired to the object store, not a bespoke OR-local kanban.
