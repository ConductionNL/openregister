---
status: implemented
retrofit: true
---

# Saved Search Views

## Purpose

Lets OpenRegister users save the configuration of an object search — selected registers and schemas, free-text search terms, facet filters, and enabled facets — as a reusable, named **view** backed by `/api/views`. Views can be marked public or default, favorited per user, and re-applied to the live search from the search sidebar. This capability describes the observed frontend contract of `src/sidebars/search/SearchSideBar.vue` and the `viewsStore` it drives. It was retrofitted under ADR-003 on 2026-05-25 (cluster `fe-sidebars`); requirements capture observed behavior rather than original intent.
## Requirements
### Requirement: REQ-001 — Saved view lifecycle through the views store and /api/views

The search sidebar (`SearchSideBar.vue`) MUST expose a saved-view surface backed by `viewsStore` and the `/api/views` endpoints. A "view" persists a reusable query configuration — `registers`, `schemas`, `searchTerms`, `facetFilters`, and `enabledFacets` — under a user-supplied `name` and optional `description`, with `isPublic` and `isDefault` flags. The sidebar MUST support: listing available views (`viewOptions` / `selectedViewValue` computeds drawn from `viewsStore.getAllViews`), creating a view (`saveView` → `viewsStore.createView`), updating the active view (`updateActiveView` → `viewsStore.updateView`), activating a view (`handleViewChange` / `loadView` → `viewsStore.fetchView` then `applyViewConfiguration`), and deleting a view (`confirmDeleteView` / `confirmDeleteActiveView` stage `viewToDelete`; `handleDeleteClose` refreshes the list and clears the active view if it was deleted). Applying a view (`applyViewConfiguration`) MUST read the stored config (supporting both the new `query` and legacy `configuration` key), repopulate the sidebar's selection state, set it as the active view via `viewsStore.setActiveView`, and re-run the search when `canSearch` is satisfied. Only query parameters MUST be persisted — never transient UI state such as pagination, sorting, or visible columns.

#### Scenario: Saving the current search as a new view persists only query parameters
- **GIVEN** the user has selected registers, schemas, search terms, and facet filters and entered a view name
- **WHEN** `saveView()` runs
- **THEN** `viewsStore.createView` MUST be called with `{ name, description, isPublic, isDefault, configuration: { registers, schemas, searchTerms, facetFilters, enabledFacets } }`
- **AND** the new view MUST become the active view and the save form MUST be reset
- **AND** pagination, sorting, and visible-column state MUST NOT be included in the persisted configuration

#### Scenario: Activating a view re-applies its configuration to the live search
- **GIVEN** a saved view with a stored `query` (or legacy `configuration`) block
- **WHEN** the user selects it and `handleViewChange(option)` resolves `viewsStore.fetchView`
- **THEN** `applyViewConfiguration(view)` MUST repopulate `selectedRegisters`, `selectedSchemas`, `searchTerms`, `facetFilters`, and `enabledFacets` from the stored config
- **AND** the view MUST be set active via `viewsStore.setActiveView`
- **AND** when `canSearch` is true the search MUST be re-run via `performSearchWithFacets()`

#### Scenario: Deleting the active view clears the active selection
- **GIVEN** the active view is staged for deletion via `confirmDeleteActiveView`
- **WHEN** the delete dialog closes through `handleDeleteClose()`
- **THEN** `viewsStore.fetchViews` MUST refresh the list
- **AND** because the deleted view was active, `viewsStore.activeView` MUST be cleared and `activeViewName` reset to an empty string

### Requirement: REQ-002 — View favoriting, default-view auto-apply, and list filtering

The search sidebar MUST let a signed-in user favorite a view, automatically apply the configured default view on mount, and filter/sort the view list. `isFavorited(view)` MUST return true when the current user's uid appears in `view.favoredBy`. `toggleFavorite(view)` MUST add or remove the current user's uid from `favoredBy` via a `PATCH /api/views/{id}` request carrying only the updated `favoredBy` array, then refresh the list; when no user is signed in it MUST surface a notification and make no request. On mount, when `viewsStore.getDefaultView` returns a view, the sidebar MUST apply it via `applyViewConfiguration`. `filteredViews` MUST filter the list by a case-insensitive substring match against name and description, then sort favorited views first and alphabetically by name within each group.

#### Scenario: Toggling favorite patches only the favoredBy array
- **GIVEN** a signed-in user viewing a view they have not favorited
- **WHEN** `toggleFavorite(view)` runs
- **THEN** a `PATCH /api/views/{id}` request MUST be sent with a body containing only `favoredBy` extended by the current user's uid
- **AND** after a successful response `viewsStore.fetchViews` MUST refresh the list and a confirmation notification MUST be shown

#### Scenario: Favoriting requires an authenticated user
@e2e exclude unauthenticated context redirects to /login before the SPA mounts — not reachable in a browser session; covered by Jest unit tests
- **GIVEN** no current user is available from `OC.getCurrentUser()`
- **WHEN** `toggleFavorite(view)` runs
- **THEN** a "must be logged in" notification MUST be shown
- **AND** no PATCH request MUST be issued

#### Scenario: The default view is applied on mount
- **GIVEN** `viewsStore.getDefaultView` returns a view
- **WHEN** the sidebar's `mounted()` hook runs
- **THEN** `applyViewConfiguration(defaultView)` MUST be invoked

#### Scenario: The view list is filtered and favorite-sorted
- **GIVEN** the user types a query into the view search box and some views are favorited
- **WHEN** `filteredViews` recomputes
- **THEN** only views whose name or description contains the query (case-insensitive) MUST be returned
- **AND** favorited views MUST sort before non-favorited views, alphabetically by name within each group

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

